<?php

declare(strict_types=1);

namespace Webconsulting\Typo3AiChat\Tool;

use Hn\McpServer\MCP\Tool\Attribute\AdminOnly;
use Hn\McpServer\Service\CapabilityManifestService;
use Netresearch\NrLlm\Domain\Enum\ToolEffect;
use ReflectionClass;

/**
 * Decides what an MCP tool DOES to the installation, from two sources the MCP
 * server already maintains — never from configuration.
 *
 * nr-llm needs the effect for two guarantees it cannot make otherwise: a write
 * gets a durable audit step, and a non-idempotent write is never auto-retried
 * after a lease loss (nr-llm ADR-111). The value is therefore a property of the
 * tool's CODE, and an operator must not be able to relabel a write as a read.
 *
 * Two sources, in order:
 *
 * 1. The tool's own MCP ``annotations`` (``readOnlyHint`` / ``destructiveHint``
 *    / ``idempotentHint``). This is what the tool author wrote down and it wins
 *    whenever it is present.
 * 2. The capability manifest's ``x-mcp.tools`` map — the subsystems the tool
 *    needs in order to run at all. A tool that needs ``database:write`` writes,
 *    whatever its schema forgot to say.
 *
 * Fail-safe direction differs per question, deliberately:
 *
 * - **Effect** falls back to READ_ONLY only when NOTHING is known (no
 *   annotations, no manifest entry, no subsystems) — an empty declaration is a
 *   tool that touches nothing, like ``GetCapabilities`` and ``ListEvents``.
 *   An UNKNOWN subsystem — one this classifier has never heard of — counts as a
 *   write, because the safe guess about an unrecognised capability is that it
 *   changes something.
 * - **Admin-only** is granted by the ``#[AdminOnly]`` attribute the MCP server
 *   itself enforces, plus the subsystems no non-admin should ever reach.
 */
final readonly class ToolEffectClassifier
{
    /**
     * Subsystems that, on their own, make a tool a write.
     *
     * Everything ending in ``:write`` is covered by the suffix rule below; this
     * list names the ones whose write nature is not in their name.
     */
    private const WRITE_SUBSYSTEMS = [
        'cli:safe',
        'extension:install',
        'project:write',
        'scheduler:task',
        'site:write',
        'cache:write',
        'x402:payments',
    ];

    /**
     * Subsystems that are read-only. Anything outside this list — and outside
     * the write list — is treated as a write (fail-safe for the unknown).
     */
    private const READ_SUBSYSTEMS = [
        'database:read',
        'database:schema',
        'file:read',
        'log:read',
        'workspace:read',
        'typoscript:provider',
        'site:middleware',
        'render:frontend',
    ];

    /**
     * Subsystems whose reach is the whole installation or the host, so only a
     * TYPO3 administrator may ever have them offered.
     */
    private const ADMIN_SUBSYSTEMS = [
        'cli:safe',
        'extension:install',
        'project:write',
        'scheduler:task',
        'site:write',
        'x402:payments',
    ];

    public function __construct(
        private ?CapabilityManifestService $capabilityManifest = null,
    ) {}

    /**
     * @param array<string, mixed> $schema the MCP tool schema as returned by
     *                                     McpToolCatalogService::describe()
     */
    public function classify(string $mcpToolName, array $schema): ToolEffect
    {
        $annotations = $this->annotations($schema);
        if ($annotations !== []) {
            return $this->fromAnnotations($annotations);
        }

        return $this->fromSubsystems($this->requiredSubsystems($mcpToolName));
    }

    /**
     * Whether the tool may only be offered to a backend administrator.
     *
     * @param array<string, mixed> $schema
     */
    public function requiresAdmin(string $mcpToolName, array $schema, ?object $toolInstance = null): bool
    {
        if ($toolInstance !== null && (new ReflectionClass($toolInstance))->getAttributes(AdminOnly::class) !== []) {
            return true;
        }

        // The schema is accepted so a caller that already has it does not have
        // to resolve the instance; MCP carries no admin hint of its own, so the
        // decision rests on the manifest's subsystems.
        unset($schema);

        foreach ($this->requiredSubsystems($mcpToolName) as $subsystem) {
            if (in_array($subsystem, self::ADMIN_SUBSYSTEMS, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Only read-only tools are offered without an explicit operator decision.
     * A write stays dark until an admin switches it on in nr-llm's Tools
     * module — defence in depth on top of the approval gate.
     */
    public function isEnabledByDefault(ToolEffect $effect): bool
    {
        return $effect === ToolEffect::READ_ONLY;
    }

    /**
     * @param array<string, mixed> $annotations
     */
    private function fromAnnotations(array $annotations): ToolEffect
    {
        if (($annotations['readOnlyHint'] ?? null) === true) {
            return ToolEffect::READ_ONLY;
        }

        if (($annotations['destructiveHint'] ?? null) === true) {
            return ToolEffect::NON_IDEMPOTENT_WRITE;
        }

        if (($annotations['idempotentHint'] ?? null) === false) {
            return ToolEffect::NON_IDEMPOTENT_WRITE;
        }

        if (($annotations['idempotentHint'] ?? null) === true) {
            return ToolEffect::IDEMPOTENT_WRITE;
        }

        // readOnlyHint present but false, with nothing said about idempotency:
        // it writes, and we do not know whether repeating it is safe.
        return ToolEffect::NON_IDEMPOTENT_WRITE;
    }

    /**
     * @param list<string> $subsystems
     */
    private function fromSubsystems(array $subsystems): ToolEffect
    {
        if ($subsystems === []) {
            return ToolEffect::READ_ONLY;
        }

        $write = false;
        foreach ($subsystems as $subsystem) {
            if (in_array($subsystem, self::READ_SUBSYSTEMS, true)) {
                continue;
            }
            if (in_array($subsystem, self::WRITE_SUBSYSTEMS, true)
                || str_ends_with($subsystem, ':write')
                || str_starts_with($subsystem, 'network:')
            ) {
                $write = true;
                continue;
            }

            // An unrecognised subsystem: assume it changes something.
            $write = true;
        }

        return $write ? ToolEffect::NON_IDEMPOTENT_WRITE : ToolEffect::READ_ONLY;
    }

    /**
     * @return list<string>
     */
    private function requiredSubsystems(string $mcpToolName): array
    {
        return $this->capabilityManifest?->getRequiredSubsystemsForTool($mcpToolName) ?? [];
    }

    /**
     * @param array<string, mixed> $schema
     *
     * @return array<string, mixed>
     */
    private function annotations(array $schema): array
    {
        $annotations = $schema['annotations'] ?? null;
        if (!is_array($annotations)) {
            return [];
        }

        $normalised = [];
        foreach ($annotations as $key => $value) {
            if (is_string($key)) {
                $normalised[$key] = $value;
            }
        }

        return $normalised;
    }
}
