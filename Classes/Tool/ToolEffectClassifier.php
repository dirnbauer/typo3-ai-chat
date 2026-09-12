<?php

declare(strict_types=1);

namespace Webconsulting\Typo3AiChat\Tool;

use Hn\McpServer\MCP\Tool\Attribute\AdminOnly;
use Hn\McpServer\Service\CapabilityManifestService;
use Netresearch\NrLlm\Domain\Enum\ToolDataClass;
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

    /**
     * What a subsystem's output IS, for nr-llm's egress gate (ADR-094).
     *
     * A different axis from the effect, and both are needed: the effect says
     * whether the tool changes the installation, this says how sensitive what
     * it hands BACK is — because that is the half that crosses the provider
     * wire. A tool that only writes still echoes the record it wrote.
     *
     * Without this declaration every projected tool would fall to nr-llm's
     * fail-closed default for an unknown group, SECRET_ADJACENT, and be
     * withheld from every provider that is not maximally trusted. That is the
     * right default for a tool nobody has classified; it is the wrong answer
     * for a catalogue whose sensitivity the capability manifest already states.
     *
     * @var array<string, ToolDataClass>
     */
    private const SUBSYSTEM_DATA_CLASSES = [
        'render:frontend' => ToolDataClass::PUBLIC_CONTENT,
        'database:read' => ToolDataClass::EDITOR_CONTENT,
        'database:write' => ToolDataClass::EDITOR_CONTENT,
        'file:read' => ToolDataClass::EDITOR_CONTENT,
        'file:write' => ToolDataClass::EDITOR_CONTENT,
        'workspace:read' => ToolDataClass::EDITOR_CONTENT,
        'workspace:write' => ToolDataClass::EDITOR_CONTENT,
        'database:schema' => ToolDataClass::INTERNAL_CONFIGURATION,
        'typoscript:provider' => ToolDataClass::INTERNAL_CONFIGURATION,
        'site:middleware' => ToolDataClass::INTERNAL_CONFIGURATION,
        'site:write' => ToolDataClass::INTERNAL_CONFIGURATION,
        'cache:write' => ToolDataClass::INTERNAL_CONFIGURATION,
        'scheduler:task' => ToolDataClass::INTERNAL_CONFIGURATION,
        'network:scheduler' => ToolDataClass::INTERNAL_CONFIGURATION,
        'network:package-manager' => ToolDataClass::INTERNAL_CONFIGURATION,
        'x402:payments' => ToolDataClass::INTERNAL_CONFIGURATION,
        'log:read' => ToolDataClass::SYSTEM_DIAGNOSTICS,
        // A shell and a package installer can put anything at all on the wire.
        'cli:safe' => ToolDataClass::SECRET_ADJACENT,
        'extension:install' => ToolDataClass::SECRET_ADJACENT,
        'project:write' => ToolDataClass::SECRET_ADJACENT,
    ];

    public function __construct(
        private ?CapabilityManifestService $capabilityManifest = null,
    ) {}

    /**
     * How sensitive this tool's OUTPUT is.
     *
     * The strictest class among the tool's subsystems wins, because a tool that
     * touches two subsystems can return either one's data. A tool that declares
     * nothing describes the installation rather than its content, so it lands
     * on INTERNAL_CONFIGURATION; a subsystem this version does not recognise
     * falls to SECRET_ADJACENT, the same fail-closed direction the effect
     * classification takes for the unknown.
     */
    public function dataClass(string $mcpToolName): ToolDataClass
    {
        $subsystems = $this->requiredSubsystems($mcpToolName);
        if ($subsystems === []) {
            return ToolDataClass::INTERNAL_CONFIGURATION;
        }

        $strictest = ToolDataClass::PUBLIC_CONTENT;
        foreach ($subsystems as $subsystem) {
            $class = self::SUBSYSTEM_DATA_CLASSES[$subsystem] ?? ToolDataClass::SECRET_ADJACENT;
            if (self::rank($class) > self::rank($strictest)) {
                $strictest = $class;
            }
        }

        return $strictest;
    }

    /**
     * The ordering the enum deliberately does not carry: nr-llm's cases are a
     * named set, not a scale, so "strictest of these two" has to be spelled out
     * where it is needed rather than assumed from declaration order.
     */
    private static function rank(ToolDataClass $class): int
    {
        return match ($class) {
            ToolDataClass::PUBLIC_CONTENT => 0,
            ToolDataClass::EDITOR_CONTENT => 1,
            ToolDataClass::INTERNAL_CONFIGURATION => 2,
            ToolDataClass::SOURCE_CODE => 3,
            ToolDataClass::SYSTEM_DIAGNOSTICS => 4,
            ToolDataClass::SECRET_ADJACENT => 5,
        };
    }

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
