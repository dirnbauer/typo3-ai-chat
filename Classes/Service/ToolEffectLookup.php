<?php

declare(strict_types=1);

namespace Webconsulting\Typo3AiChat\Service;

use Netresearch\NrLlm\Domain\Enum\ToolEffect;
use Netresearch\NrLlm\Service\Tool\ToolApprovalRule;
use Netresearch\NrLlm\Service\Tool\ToolEffectInterface;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;

/**
 * What the runtime will do with a tool, asked by name.
 *
 * The chat UI has to answer two questions before anything runs — "does this
 * change my site?" and "will I be asked first?" — and both answers must be the
 * ones the runtime itself will act on. So they are read from nr-llm's own
 * registry and its own approval rule rather than recomputed here: a badge that
 * disagrees with the gate is worse than no badge.
 *
 * The fallbacks match nr-llm's: a tool that declares no effect is read-only
 * (nr-llm ADR-111), and a name the registry does not know is read-only too,
 * because an unknown name is not offered to the model in the first place.
 */
final readonly class ToolEffectLookup
{
    public function __construct(
        private ToolRegistry $toolRegistry,
    ) {}

    public function effectOf(string $toolName): ToolEffect
    {
        $tool = $this->toolRegistry->get($toolName);
        if ($tool instanceof ToolEffectInterface) {
            return $tool->getEffect();
        }

        return ToolEffect::READ_ONLY;
    }

    public function requiresApproval(string $toolName): bool
    {
        return ToolApprovalRule::requiresApproval($this->toolRegistry->get($toolName));
    }

    /**
     * Effect and approval for a set of names, in the shape the status endpoint
     * and the SSE frames use.
     *
     * @param list<string> $toolNames
     *
     * @return array<string, array{effect: string, requiresApproval: bool}>
     */
    public function describe(array $toolNames): array
    {
        $described = [];
        foreach ($toolNames as $name) {
            $described[$name] = [
                'effect' => $this->effectOf($name)->value,
                'requiresApproval' => $this->requiresApproval($name),
            ];
        }

        return $described;
    }
}
