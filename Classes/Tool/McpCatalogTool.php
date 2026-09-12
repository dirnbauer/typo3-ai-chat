<?php

declare(strict_types=1);

namespace Webconsulting\Typo3AiChat\Tool;

use Hn\McpServer\Service\McpToolCatalogService;
use Netresearch\NrLlm\Domain\Enum\ToolEffect;
use Netresearch\NrLlm\Domain\ValueObject\ToolResult;
use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Service\Tool\ToolEffectInterface;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Throwable;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use Webconsulting\Typo3AiChat\Utility\ErrorMessageSanitizer;

/**
 * One MCP tool of this installation, offered to the nr-llm agent loop.
 *
 * The tool is not re-implemented: ``execute()`` calls straight into
 * {@see McpToolCatalogService}, so the model reaches exactly the code an
 * external MCP client would reach — same permission checks, same workspace
 * rules, same capability manifest.
 *
 * AMBIENT-IDENTITY CONTRACT — the reason this adapter exists at all
 * ================================================================
 *
 * nr-llm threads the acting user through {@see ToolExecutionContext} precisely
 * so a run authorises identically in a request and on a queue worker (nr-llm
 * ADR-083). The MCP tools do the opposite: they read ``$GLOBALS['BE_USER']``,
 * and the MCP server's own ``#[AdminOnly]`` gate does too.
 *
 * Bridging the two means the ambient user is load-bearing, so this adapter
 * refuses to run whenever the ambient user is not provably the same person as
 * the run's actor. It is why a turn is executed SYNCHRONOUSLY inside the AJAX
 * request and never through ``AgentRuntime::enqueue()``: in a worker there is
 * no ambient user, this check fails, and every tool call turns into an error
 * result instead of silently running as somebody else — or as nobody.
 *
 * The check fails closed on every branch: no actor, no ambient user, a
 * mismatch, or an unresolvable uid all produce an error {@see ToolResult}.
 */
final readonly class McpCatalogTool implements ToolInterface, ToolEffectInterface
{
    /**
     * The prefix that turns an MCP tool name into a model-facing tool name:
     * ``WriteTable`` becomes ``typo3_WriteTable``.
     *
     * It exists so the model can tell an installation tool from an nr-llm
     * builtin at a glance, and so the two name spaces cannot collide.
     */
    public const NAME_PREFIX = 'typo3_';

    /**
     * The nr-llm tool group every MCP tool joins, so an operator can enable or
     * disable the whole catalogue with one switch in the Tools module.
     */
    public const GROUP = 'typo3_mcp';

    public function __construct(
        private string $mcpName,
        private ToolSpec $spec,
        private ToolEffect $effect,
        private bool $requiresAdmin,
        private bool $enabledByDefault,
        private McpToolCatalogService $catalog,
        private ToolResultConverter $converter,
    ) {}

    /**
     * The model-facing name of an MCP tool.
     */
    public static function toolName(string $mcpName): string
    {
        return self::NAME_PREFIX . $mcpName;
    }

    /**
     * The MCP name behind a model-facing name, or null when the name is not one
     * of ours.
     */
    public static function mcpName(string $toolName): ?string
    {
        if (!str_starts_with($toolName, self::NAME_PREFIX)) {
            return null;
        }

        $mcpName = substr($toolName, strlen(self::NAME_PREFIX));

        return $mcpName === '' ? null : $mcpName;
    }

    public function getSpec(): ToolSpec
    {
        return $this->spec;
    }

    public function getEffect(): ToolEffect
    {
        return $this->effect;
    }

    public function getGroup(): string
    {
        return self::GROUP;
    }

    public function isEnabledByDefault(): bool
    {
        return $this->enabledByDefault;
    }

    public function requiresAdmin(): bool
    {
        return $this->requiresAdmin;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public function execute(array $arguments, ToolExecutionContext $context): ToolResult
    {
        $denial = $this->denyMismatchedIdentity($context);
        if ($denial !== null) {
            return $denial;
        }

        try {
            $result = $this->catalog->execute($this->mcpName, $arguments);
        } catch (Throwable $exception) {
            return ToolResult::error(sprintf(
                'Tool "%s" could not be executed: %s',
                $this->spec->name,
                ErrorMessageSanitizer::sanitize($exception->getMessage()),
            ));
        }

        return $this->converter->convert($result, $this->spec->name);
    }

    /**
     * Refuse the call unless the run's actor IS the ambient backend user the
     * MCP tool is about to read.
     */
    private function denyMismatchedIdentity(ToolExecutionContext $context): ?ToolResult
    {
        $actorUid = $context->actor->backendUserUid;
        if ($actorUid <= 0) {
            return $this->denied('the run has no backend user');
        }

        $acting = $context->actingBackendUser();
        if (!$acting instanceof BackendUserAuthentication || $this->uidOf($acting) !== $actorUid) {
            return $this->denied('the acting backend user could not be resolved');
        }

        $ambient = $GLOBALS['BE_USER'] ?? null;
        if (!$ambient instanceof BackendUserAuthentication) {
            return $this->denied('no backend user session is active in this process');
        }

        if ($this->uidOf($ambient) !== $actorUid) {
            return $this->denied('the active backend user session belongs to somebody else');
        }

        if ($this->requiresAdmin && !$acting->isAdmin()) {
            return $this->denied('the tool is restricted to administrators');
        }

        return null;
    }

    private function denied(string $reason): ToolResult
    {
        return ToolResult::error(sprintf('Tool "%s" was not executed because %s.', $this->spec->name, $reason));
    }

    private function uidOf(BackendUserAuthentication $user): int
    {
        $record = is_array($user->user) ? $user->user : [];
        $uid = $record['uid'] ?? null;

        return is_numeric($uid) ? (int)$uid : 0;
    }
}
