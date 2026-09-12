<?php

declare(strict_types=1);

namespace Webconsulting\Typo3AiChat\Tool;

use Hn\McpServer\MCP\ToolRegistry;
use Hn\McpServer\Service\McpToolCatalogService;
use Netresearch\NrLlm\Domain\Enum\ToolDataClass;
use Netresearch\NrLlm\Domain\Enum\ToolEffect;
use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Service\Tool\ToolProviderInterface;
use Throwable;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use Webconsulting\Typo3AiChat\Service\BackendUserContext;

/**
 * Projects this installation's MCP tool catalogue into the nr-llm agent
 * runtime.
 *
 * nr-llm knows its builtin tools at container-compile time because they are
 * DI-tagged classes. The MCP catalogue cannot be known then: which tools exist
 * depends on which extensions are installed and on what the capability
 * manifest permits, both of which are runtime facts. That is exactly the gap
 * {@see ToolProviderInterface} was made for, so the projection is a provider
 * rather than a pile of generated tool classes.
 *
 * The expensive half is the metadata, not the objects: ``describe()`` asks
 * every tool to build its JSON schema, the manifest is parsed, and each tool
 * class is reflected for ``#[AdminOnly]``. All of that is cached, keyed by a
 * fingerprint of the registered tool NAMES plus the acting backend user —
 * cheap to compute, changing the moment an extension adds, removes or gates a
 * tool, and never shared between users (see {@see definitions()}). A schema
 * edit inside an existing tool is picked up by the ordinary system-cache flush
 * that a deployment performs anyway.
 *
 * Several MCP tools refuse to describe themselves without a backend user at
 * all, so the projection is empty outside an authenticated request — correct,
 * and the reason a turn runs synchronously in one.
 *
 * No network I/O, as the interface requires: every source is in-process.
 */
final readonly class McpCatalogToolProvider implements ToolProviderInterface
{
    private const CACHE_PREFIX = 'catalog_';

    public function __construct(
        private McpToolCatalogService $catalog,
        private ToolRegistry $mcpToolRegistry,
        private ToolEffectClassifier $effectClassifier,
        private ToolResultConverter $resultConverter,
        private FrontendInterface $cache,
        private BackendUserContext $backendUser,
    ) {}

    /**
     * @return iterable<McpCatalogTool>
     */
    public function tools(): iterable
    {
        foreach ($this->definitions() as $definition) {
            yield new McpCatalogTool(
                $definition['mcpName'],
                new ToolSpec(
                    name: McpCatalogTool::toolName($definition['mcpName']),
                    description: $definition['description'],
                    parameters: $definition['parameters'],
                ),
                ToolEffect::from($definition['effect']),
                ToolDataClass::from($definition['dataClass']),
                $definition['requiresAdmin'],
                $definition['enabledByDefault'],
                $this->catalog,
                $this->resultConverter,
            );
        }
    }

    /**
     * @return list<array{
     *     mcpName: string,
     *     description: string,
     *     parameters: array<string, mixed>,
     *     effect: string,
     *     dataClass: string,
     *     requiresAdmin: bool,
     *     enabledByDefault: bool,
     * }>
     */
    private function definitions(): array
    {
        $names = array_keys($this->mcpToolRegistry->getTools());
        /** @var list<string> $names */
        $names = array_values(array_filter($names, is_string(...)));
        sort($names, SORT_STRING);

        if ($names === []) {
            return [];
        }

        // The acting user is part of the key, not decoration: several MCP tools
        // build their JSON schema from what the CURRENT backend user may reach
        // — WriteTable enumerates the tables they can edit — so a schema cached
        // under the tool names alone would serve one editor's table list to
        // another. A per-user key costs one cache entry per active user and
        // makes that impossible.
        $cacheIdentifier = self::CACHE_PREFIX . sha1(implode(',', $names) . '|' . $this->backendUser->uid());
        $cached = $this->cache->get($cacheIdentifier);
        if (is_array($cached)) {
            /** @var list<array{mcpName: string, description: string, parameters: array<string, mixed>, effect: string, dataClass: string, requiresAdmin: bool, enabledByDefault: bool}> $cached */
            return $cached;
        }

        $definitions = [];
        foreach ($names as $mcpName) {
            $definition = $this->describe($mcpName);
            if ($definition !== null) {
                $definitions[] = $definition;
            }
        }

        $this->cache->set($cacheIdentifier, $definitions);

        return $definitions;
    }

    /**
     * @return array{
     *     mcpName: string,
     *     description: string,
     *     parameters: array<string, mixed>,
     *     effect: string,
     *     dataClass: string,
     *     requiresAdmin: bool,
     *     enabledByDefault: bool,
     * }|null
     */
    private function describe(string $mcpName): ?array
    {
        try {
            $schema = $this->catalog->describe($mcpName)['schema'];
        } catch (Throwable) {
            // A tool that cannot describe itself is simply not offered. The
            // registry's own fail-soft contract says one broken tool must not
            // cost the run every other tool.
            return null;
        }

        $effect = $this->effectClassifier->classify($mcpName, $schema);

        return [
            'mcpName' => $mcpName,
            'description' => $this->description($schema, $mcpName),
            'parameters' => $this->parameters($schema),
            'effect' => $effect->value,
            'dataClass' => $this->effectClassifier->dataClass($mcpName)->value,
            'requiresAdmin' => $this->effectClassifier->requiresAdmin(
                $mcpName,
                $schema,
                $this->mcpToolRegistry->getTool($mcpName),
            ),
            'enabledByDefault' => $this->effectClassifier->isEnabledByDefault($effect),
        ];
    }

    /**
     * @param array<string, mixed> $schema
     */
    private function description(array $schema, string $mcpName): string
    {
        $description = $schema['description'] ?? null;
        if (is_string($description) && trim($description) !== '') {
            return trim($description);
        }

        return sprintf('The TYPO3 MCP tool "%s".', $mcpName);
    }

    /**
     * The model-facing parameter schema. MCP nests it under ``inputSchema``;
     * nr-llm's {@see ToolSpec} takes the JSON Schema object directly.
     *
     * @param array<string, mixed> $schema
     *
     * @return array<string, mixed>
     */
    private function parameters(array $schema): array
    {
        $input = $schema['inputSchema'] ?? null;
        if (!is_array($input)) {
            return ['type' => 'object', 'properties' => []];
        }

        $normalised = [];
        foreach ($input as $key => $value) {
            if (is_string($key)) {
                $normalised[$key] = $value;
            }
        }

        if (!isset($normalised['type'])) {
            $normalised['type'] = 'object';
        }

        return $normalised;
    }
}
