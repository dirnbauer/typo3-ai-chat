<?php

declare(strict_types=1);

namespace Webconsulting\Typo3AiChat\Http;

use Closure;

/**
 * Where a turn's events go.
 *
 * One sink, two destinations: the SSE stream writes each event to the socket as
 * it happens, and the JSON route collects the identical list into an array.
 * That is deliberate — the two transports must not be able to disagree about
 * what happened, so they share the producer and differ only in the writer they
 * are given.
 *
 * The sink also owns the heartbeat and the abort check, because both are
 * questions about the WRITE and only the thing doing the writing can answer
 * them.
 */
final class TurnEventSink
{
    public const PING_INTERVAL_SECONDS = 15;

    /** @var list<array{event: string, data: array<string, mixed>}> */
    private array $collected = [];

    private int $lastWriteAt;

    private int $sequence = 0;

    private bool $aborted = false;

    /**
     * @param (Closure(SseEvent): void)|null $writer null collects only — the JSON transport
     * @param (Closure(): bool)|null         $abortCheck answers "has the client gone away?"
     * @param (Closure(): void)|null         $onAbort called once, the first time it has
     */
    public function __construct(
        private readonly ?Closure $writer = null,
        private readonly ?Closure $abortCheck = null,
        private readonly ?Closure $onAbort = null,
    ) {
        $this->lastWriteAt = time();
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function emit(string $name, array $payload = []): void
    {
        $this->send(new SseEvent($name, $payload, ++$this->sequence));
    }

    public function send(SseEvent $event): void
    {
        $this->collected[] = ['event' => $event->name, 'data' => $event->payload];

        if ($this->writer === null) {
            return;
        }

        ($this->writer)($event);
        $this->lastWriteAt = time();

        $this->checkAbort();
    }

    /**
     * Write a heartbeat if the connection has been quiet for too long.
     *
     * Called from the producer's step boundaries rather than from a timer:
     * PHP has no timer here, the turn is a single blocking call chain, and a
     * step boundary is the only moment control comes back to us. It means a
     * single provider call longer than the interval still passes without a
     * ping — which is the honest limit of a synchronous design, and why the
     * interval is well under a typical proxy timeout.
     */
    public function pingIfDue(): void
    {
        if ($this->writer === null) {
            return;
        }

        if (time() - $this->lastWriteAt < self::PING_INTERVAL_SECONDS) {
            return;
        }

        ($this->writer)(SseEvent::ping());
        $this->lastWriteAt = time();
        $this->checkAbort();
    }

    public function isAborted(): bool
    {
        return $this->aborted;
    }

    /**
     * Every event this turn produced, in order — the JSON transport's body, and
     * what a functional test asserts against.
     *
     * @return list<array{event: string, data: array<string, mixed>}>
     */
    public function collected(): array
    {
        return $this->collected;
    }

    /**
     * The closure a producer is handed: one name, one payload, nothing about
     * transports.
     *
     * @return Closure(string, array<string, mixed>): void
     */
    public function emitter(): Closure
    {
        return function (string $name, array $payload): void {
            /** @var array<string, mixed> $payload */
            $this->pingIfDue();
            $this->emit($name, $payload);
        };
    }

    private function checkAbort(): void
    {
        if ($this->aborted || $this->abortCheck === null) {
            return;
        }

        if (($this->abortCheck)() !== true) {
            return;
        }

        $this->aborted = true;
        if ($this->onAbort !== null) {
            ($this->onAbort)();
        }
    }
}
