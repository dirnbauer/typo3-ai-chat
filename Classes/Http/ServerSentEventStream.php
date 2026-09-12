<?php

declare(strict_types=1);

namespace Webconsulting\Typo3AiChat\Http;

use Closure;
use RuntimeException;
use TYPO3\CMS\Core\Http\SelfEmittableStreamInterface;

/**
 * A response body that IS the turn: the producer runs while the stream is being
 * emitted, and each event reaches the browser as it happens.
 *
 * TYPO3's {@see SelfEmittableStreamInterface} exists for exactly this. The
 * normal emitter reads a body and writes it out, which requires the body to
 * exist first — and a turn's body does not exist until the turn is over, which
 * is the one thing streaming is for. A self-emittable stream takes over the
 * writing instead.
 *
 * Every other {@see \Psr\Http\Message\StreamInterface} method is unsupported on
 * purpose. There is nothing to seek in, nothing to read back, and no size to
 * report before the fact; answering those questions with plausible lies would
 * let a middleware silently buffer the stream and undo the streaming.
 *
 * Client disconnects are noticed through `connection_aborted()`, which only
 * updates after a write — so the abort check runs after each flush, and the
 * turn is cancelled from there.
 */
final class ServerSentEventStream implements SelfEmittableStreamInterface
{
    /**
     * @param Closure(TurnEventSink): void $producer runs the turn, emitting into the sink
     * @param (Closure(): void)|null       $onAbort  called when the client goes away
     */
    public function __construct(
        private readonly Closure $producer,
        private readonly ?Closure $onAbort = null,
    ) {}

    /**
     * The headers that make a stream a stream.
     *
     * `no-cache` keeps a browser or a CDN from serving an old turn.
     * `X-Accel-Buffering: no` is for nginx, which otherwise buffers a proxied
     * response and delivers every event at once at the end — the failure that
     * looks exactly like "streaming does not work" and has nothing to do with
     * this code.
     *
     * @return array<string, string>
     */
    public static function headers(): array
    {
        return [
            'Content-Type' => 'text/event-stream; charset=utf-8',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Pragma' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ];
    }

    public function emit(): void
    {
        // PHP's own output buffers would hold the frames back just as nginx
        // would. Unwinding them here rather than trusting php.ini is the
        // difference between streaming and appearing to stream.
        while (ob_get_level() > 0) {
            ob_end_flush();
        }

        $this->produce(new TurnEventSink(
            writer: static function (SseEvent $event): void {
                echo $event->encode();
                flush();
            },
            abortCheck: static fn(): bool => connection_aborted() === 1,
            onAbort: $this->onAbort,
        ));
    }

    /**
     * The stream's own contribution to the event list, separated from the
     * socket it normally writes to.
     *
     * Its own method because {@see emit()} tears down every output buffer in
     * the process — correct in production, and impossible for a test to observe
     * through, since the buffer the test opened to capture the output is one of
     * the ones being torn down. The ordering rule lives here so it can be
     * checked against a sink that simply collects.
     */
    public function produce(TurnEventSink $sink): void
    {
        // An immediate ping opens the stream: until the first byte arrives the
        // browser's EventSource has not fired `open`, and a turn that thinks for
        // ten seconds before its first token would look like a failed request.
        $sink->send(SseEvent::ping());

        ($this->producer)($sink);
    }

    public function __toString(): string
    {
        return '';
    }

    public function close(): void {}

    public function detach(): mixed
    {
        return null;
    }

    public function getSize(): ?int
    {
        return null;
    }

    public function tell(): int
    {
        return 0;
    }

    public function eof(): bool
    {
        return true;
    }

    public function isSeekable(): bool
    {
        return false;
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        throw new RuntimeException('A server-sent event stream cannot be seeked.', 1794000301);
    }

    public function rewind(): void
    {
        throw new RuntimeException('A server-sent event stream cannot be rewound.', 1794000302);
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write(string $string): int
    {
        throw new RuntimeException('A server-sent event stream is written by its producer only.', 1794000303);
    }

    public function isReadable(): bool
    {
        return false;
    }

    public function read(int $length): string
    {
        throw new RuntimeException('A server-sent event stream cannot be read.', 1794000304);
    }

    public function getContents(): string
    {
        throw new RuntimeException('A server-sent event stream has no contents until it has been emitted.', 1794000305);
    }

    public function getMetadata(?string $key = null): mixed
    {
        return $key === null ? [] : null;
    }
}
