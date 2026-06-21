<?php

declare(strict_types=1);

namespace Netresearch\NrMcpAgent\Mcp;

use Netresearch\NrMcpAgent\Configuration\ExtensionConfiguration;
use Netresearch\NrMcpAgent\Domain\Repository\McpServerRepository;
use Netresearch\NrMcpAgent\Exception\McpException;
use Psr\Log\LoggerInterface;
use stdClass;
use Throwable;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Core\Environment;

final class McpToolProvider implements McpToolProviderInterface
{
    /** @var array<string, McpConnection> server_key => McpConnection */
    private array $connections = [];

    /** @var array<string, string> prefixed tool name => server_key */
    private array $toolIndex = [];

    /** @var list<array<string, mixed>> cached active server rows for the current request */
    private array $activeServers = [];

    public function __construct(
        private readonly ExtensionConfiguration $config,
        private readonly McpServerRepository $serverRepository,
        private readonly FrontendInterface $cache,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * No-op for backwards compatibility. Connections are opened lazily.
     *
     * @deprecated Will be removed in a future version. Connections are managed internally.
     */
    public function connect(): void
    {
        // No-op: connections are opened lazily in getToolDefinitions() and executeTool()
    }

    /**
     * @return list<array{type: string, function: array{name: string, description: string, parameters: array<string, mixed>}}>
     */
    public function getToolDefinitions(): array
    {
        if (!$this->config->isMcpEnabled()) {
            return [];
        }

        $this->activeServers = $this->serverRepository->findAllActive();
        if ($this->activeServers === []) {
            $this->serverRepository->initDefault();
            $this->activeServers = $this->serverRepository->findAllActive();
        }
        if ($this->activeServers === []) {
            return [];
        }

        $allTools = [];

        foreach ($this->activeServers as $server) {
            $serverKey = is_string($server['server_key'] ?? null) ? $server['server_key'] : '';
            if ($serverKey === '') {
                continue;
            }

            $allTools = array_merge($allTools, $this->collectServerTools($server, $serverKey));
        }

        /** @var list<array{type: string, function: array{name: string, description: string, parameters: array<string, mixed>}}> $allTools */
        return $allTools;
    }

    /**
     * Returns the tool definitions for a single server, using the cache when available
     * and connecting to list tools on a cache miss. Failures are logged and yield [].
     *
     * @param array<string, mixed> $server
     * @return list<array{type: string, function: array{name: string, description: string, parameters: array<string, mixed>}}>
     */
    private function collectServerTools(array $server, string $serverKey): array
    {
        $cacheKey = $this->buildCacheKey($server);

        /** @var list<array{type: string, function: array{name: string, description: string, parameters: array<string, mixed>}}>|false $cached */
        $cached = $this->cache->get($cacheKey);

        if ($cached !== false) {
            // Cache hit: populate toolIndex, no connection needed
            foreach ($cached as $tool) {
                $this->toolIndex[$tool['function']['name']] = $serverKey;
            }
            return $cached;
        }

        $uid = $this->toUid($server['uid'] ?? 0);

        // Cache miss: connect (or reuse existing), list tools, cache, populate toolIndex
        try {
            $connection = $this->connections[$serverKey] ?? $this->openConnection($server);
            $this->connections[$serverKey] = $connection;

            $result = $connection->call('tools/list');
            /** @var array<mixed> $rawTools */
            $rawTools = is_array($result['tools'] ?? null) ? $result['tools'] : [];

            $serverTools = $this->buildServerToolDefinitions($rawTools, $serverKey);

            $this->cache->set($cacheKey, $serverTools);
            $this->serverRepository->updateConnectionStatus($uid, 'ok');

            return $serverTools;
        } catch (Throwable $e) {
            $this->logger->error('MCP server connection failed', [
                'server_key' => $serverKey,
                'error' => $e->getMessage(),
            ]);
            $this->serverRepository->updateConnectionStatus($uid, 'error', $e->getMessage());
            // Skip this server, continue with others
            return [];
        }
    }

    /**
     * Maps raw MCP tool rows to prefixed function definitions and registers them in toolIndex.
     *
     * @param array<mixed> $rawTools
     * @return list<array{type: string, function: array{name: string, description: string, parameters: array<string, mixed>}}>
     */
    private function buildServerToolDefinitions(array $rawTools, string $serverKey): array
    {
        $serverTools = [];
        foreach ($rawTools as $tool) {
            if (!is_array($tool)) {
                continue;
            }
            /** @var array<string, mixed> $toolData */
            $toolData = $tool;
            $originalName = is_string($toolData['name'] ?? null) ? $toolData['name'] : '';
            $description = is_string($toolData['description'] ?? null) ? $toolData['description'] : '';
            /** @var array<string, mixed> $inputSchema */
            $inputSchema = is_array($toolData['inputSchema'] ?? null) ? $toolData['inputSchema'] : [];
            $parameters = $this->normalizeToolSchema($inputSchema);

            $prefixedName = $serverKey . '__' . $originalName;
            $this->toolIndex[$prefixedName] = $serverKey;

            $serverTools[] = [
                'type' => 'function',
                'function' => [
                    'name' => $prefixedName,
                    'description' => $description,
                    'parameters' => $parameters,
                ],
            ];
        }

        return $serverTools;
    }

    /**
     * @param array<string, mixed> $input
     */
    public function executeTool(string $toolName, array $input): string
    {
        $serverKey = $this->toolIndex[$toolName] ?? null;
        if ($serverKey === null) {
            return json_encode(['error' => 'Unknown tool: ' . $toolName]) ?: '{"error":"Unknown tool"}';
        }

        // Strip prefix to get original MCP tool name
        $originalName = substr($toolName, strlen($serverKey) + 2); // +2 for '__'

        $connectionError = $this->ensureConnection($serverKey);
        if ($connectionError !== null) {
            return $connectionError;
        }

        $result = $this->connections[$serverKey]->call('tools/call', [
            'name' => $originalName,
            'arguments' => $input,
        ]);

        $texts = $this->extractTextBlocks($result);

        return implode("\n", $texts) ?: (json_encode($result) ?: '{}');
    }

    /**
     * Ensures a connection for the given server key exists (lazy-opening on the cache-hit
     * path). Returns null on success or an error JSON string on failure.
     */
    private function ensureConnection(string $serverKey): ?string
    {
        if (isset($this->connections[$serverKey])) {
            return null;
        }

        $server = $this->findServerByKey($serverKey);
        if ($server === null) {
            return json_encode(['error' => "MCP server '" . $serverKey . "' not found"]) ?: '{"error":"Server not found"}';
        }

        try {
            $this->connections[$serverKey] = $this->openConnection($server);
            $this->serverRepository->updateConnectionStatus($this->toUid($server['uid'] ?? 0), 'ok');
            return null;
        } catch (Throwable $e) {
            $this->logger->error('MCP server connection failed during executeTool', [
                'server_key' => $serverKey,
                'error' => $e->getMessage(),
            ]);
            return json_encode(['error' => "MCP server '" . $serverKey . "' not connected: " . $e->getMessage()])
                ?: '{"error":"Connection failed"}';
        }
    }

    /**
     * @param array<string, mixed> $result
     * @return list<string>
     */
    private function extractTextBlocks(array $result): array
    {
        $texts = [];
        /** @var array<mixed> $contentBlocks */
        $contentBlocks = is_array($result['content'] ?? null) ? $result['content'] : [];
        foreach ($contentBlocks as $block) {
            if (!is_array($block)) {
                continue;
            }
            /** @var array<string, mixed> $blockData */
            $blockData = $block;
            if (($blockData['type'] ?? '') === 'text') {
                $texts[] = is_string($blockData['text'] ?? null) ? $blockData['text'] : '';
            }
        }

        return $texts;
    }

    public function disconnect(): void
    {
        foreach ($this->connections as $connection) {
            $connection->close();
        }
        $this->connections = [];
        $this->toolIndex = [];
        $this->activeServers = [];
        // Cache is NOT cleared — persists in cache framework
    }

    /**
     * Returns the active server rows loaded during getToolDefinitions().
     *
     * @return list<array<string, mixed>>
     */
    public function getActiveServers(): array
    {
        return $this->activeServers;
    }

    /**
     * Opens a new McpConnection for the given server record.
     *
     * @param array<string, mixed> $server
     */
    private function openConnection(array $server): McpConnection
    {
        $transport = is_string($server['transport'] ?? null) ? $server['transport'] : 'stdio';

        if ($transport === 'sse') {
            // SSE transport is not yet implemented in McpConnection
            throw new McpException('SSE transport is not yet supported');
        }

        $command = is_string($server['command'] ?? null) ? $server['command'] : '';
        if ($command === '') {
            $command = Environment::getProjectPath() . '/vendor/bin/typo3';
        }

        $argsRaw = is_string($server['arguments'] ?? null) ? $server['arguments'] : '';
        $args = $argsRaw !== '' ? array_values(array_filter(
            array_map(trim(...), explode("\n", $argsRaw)),
            static fn(string $line): bool => $line !== '',
        )) : [];

        $connection = new McpConnection();
        $connection->open($command, $args, Environment::getProjectPath());

        return $connection;
    }

    /**
     * Finds a server row by key from the active servers list.
     *
     * @return array<string, mixed>|null
     */
    private function findServerByKey(string $serverKey): ?array
    {
        foreach ($this->activeServers as $server) {
            if (($server['server_key'] ?? '') === $serverKey) {
                return $server;
            }
        }
        return null;
    }

    /**
     * Builds a cache key for a server's tool list.
     *
     * @param array<string, mixed> $server
     */
    private function buildCacheKey(array $server): string
    {
        $serverKey = is_string($server['server_key'] ?? null) ? $server['server_key'] : '';
        $command = is_string($server['command'] ?? null) ? $server['command'] : '';
        $arguments = is_string($server['arguments'] ?? null) ? $server['arguments'] : '';
        $url = is_string($server['url'] ?? null) ? $server['url'] : '';

        return 'mcp_tools_' . $serverKey . '_' . md5($command . '|' . $arguments . '|' . $url);
    }

    /**
     * Normalize an MCP inputSchema to a valid OpenAI function parameters schema.
     *
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private function normalizeToolSchema(array $schema): array
    {
        if ($schema === [] || !isset($schema['type'])) {
            return ['type' => 'object', 'properties' => new stdClass()];
        }

        if (isset($schema['properties']) && is_array($schema['properties']) && $schema['properties'] === []) {
            $schema['properties'] = new stdClass();
        }

        return $schema;
    }

    private function toUid(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }
        return is_string($value) || is_float($value) ? (int) $value : 0;
    }
}
