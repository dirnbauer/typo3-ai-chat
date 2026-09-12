<?php

declare(strict_types=1);

namespace Webconsulting\Typo3AiChat\Service;

use Netresearch\NrLlm\Domain\ValueObject\RunStep;

/**
 * Collects the steps of one run and turns them into client events.
 *
 * Two jobs that have to be done together:
 *
 * **Deduplication.** Steps arrive twice — once live through the runtime's
 * `$onStep` callback, and again in the settled result's `steps` array. A
 * recorder that kept both would persist every assistant turn twice and stream
 * every event twice. Identity is the object, so the second sighting is free to
 * recognise.
 *
 * **Call correlation.** A tool step carries the tool's NAME but not the id of
 * the call it answers, while the client needs the id to attach a result to the
 * card it already drew. The correlation is positional and that is sound: within
 * a round the loop executes the requested calls in the order the model asked
 * for them, so a queue per round, matched on name, reunites them.
 */
final class TurnStepRecorder
{
    /** @var list<RunStep> */
    private array $steps = [];

    /** @var array<int, true> */
    private array $seen = [];

    /** @var list<array{0: string, 1: array<string, mixed>}> */
    private array $pendingEvents = [];

    /**
     * Requested-but-not-yet-answered calls, oldest first.
     *
     * @var list<array{id: string, name: string}>
     */
    private array $openCalls = [];

    public function __construct(
        private readonly ?ToolEffectLookup $effectLookup = null,
    ) {}

    public function record(RunStep $step): void
    {
        $id = spl_object_id($step);
        if (isset($this->seen[$id])) {
            return;
        }

        $this->seen[$id] = true;
        $this->steps[] = $step;
        $this->emitFor($step);
    }

    /**
     * @param list<RunStep> $steps
     */
    public function recordAll(array $steps): void
    {
        foreach ($steps as $step) {
            $this->record($step);
        }
    }

    /**
     * @return list<RunStep>
     */
    public function steps(): array
    {
        return $this->steps;
    }

    /**
     * The events recorded since the last drain, in order.
     *
     * @return list<array{0: string, 1: array<string, mixed>}>
     */
    public function drainEvents(): array
    {
        $events = $this->pendingEvents;
        $this->pendingEvents = [];

        return $events;
    }

    /**
     * The call id a tool step answers, consuming the correlation.
     */
    public function correlate(string $toolName): string
    {
        $remaining = [];
        $matched = '';
        $consumed = false;
        foreach ($this->openCalls as $call) {
            if (!$consumed && $call['name'] === $toolName) {
                $matched = $call['id'];
                $consumed = true;
                continue;
            }
            $remaining[] = $call;
        }
        $this->openCalls = $remaining;

        return $matched;
    }

    private function emitFor(RunStep $step): void
    {
        match ($step->kind) {
            RunStep::KIND_LLM => $this->emitLlm($step),
            RunStep::KIND_TOOL => $this->emitTool($step),
            default => null,
        };
    }

    private function emitLlm(RunStep $step): void
    {
        $payload = [
            'round' => $step->round,
            'tokens' => [
                'prompt' => $step->promptTokens ?? 0,
                'completion' => $step->completionTokens ?? 0,
                'total' => $step->totalTokens ?? 0,
            ],
        ];
        if (is_string($step->content) && $step->content !== '') {
            $payload['content'] = $step->content;
        }
        if (is_string($step->thinking) && $step->thinking !== '') {
            $payload['thinking'] = $step->thinking;
        }

        $this->pendingEvents[] = ['step.llm', $payload];

        foreach ($step->requestedToolCalls ?? [] as $call) {
            $id = is_string($call['id'] ?? null) ? $call['id'] : '';
            $name = is_string($call['name'] ?? null) ? $call['name'] : '';
            if ($name === '') {
                continue;
            }

            $this->openCalls[] = ['id' => $id, 'name' => $name];
            $this->pendingEvents[] = ['step.tool.call', [
                'round' => $step->round,
                'callId' => $id,
                'name' => $name,
                'arguments' => is_array($call['arguments'] ?? null) ? $call['arguments'] : [],
                'effect' => $this->effectLookup?->effectOf($name)->value ?? 'read_only',
            ]];
        }
    }

    private function emitTool(RunStep $step): void
    {
        $name = $step->toolName ?? '';

        $this->pendingEvents[] = ['step.tool.result', [
            'callId' => $this->correlate($name),
            'name' => $name,
            'isError' => $step->toolIsError ?? false,
            'preview' => $this->preview($step->toolResult ?? ''),
            'durationMs' => round($step->durationMs, 2),
        ]];
    }

    /**
     * A tool result can be a page of JSON. The stream carries a preview, not the
     * payload: the full text is already in the model's context and in nr-llm's
     * persisted run, and pushing it down an SSE connection a second time would
     * cost the browser more than it tells the user.
     */
    private function preview(string $result): string
    {
        $normalised = trim(preg_replace('/\s+/u', ' ', $result) ?? $result);

        return mb_strlen($normalised) > 280
            ? mb_substr($normalised, 0, 279) . "\u{2026}"
            : $normalised;
    }
}
