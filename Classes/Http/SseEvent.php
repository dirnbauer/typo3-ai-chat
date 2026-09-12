<?php

declare(strict_types=1);

namespace Webconsulting\Typo3AiChat\Http;

/**
 * One server-sent event, and the rules for putting it on the wire.
 *
 * The format is line-oriented and a newline inside a value would end the frame,
 * so every line of the payload gets its own `data:` prefix — that is what the
 * specification says a multi-line value looks like, and the browser rejoins
 * them with newlines on the other side. JSON encoding already escapes newlines
 * in practice, which is exactly why this is worth writing down: the one payload
 * that would break the stream is the one nobody tests.
 *
 * The blank line at the end is not decoration. It is the frame delimiter; a
 * frame without it is buffered by the browser until the next one arrives.
 */
final readonly class SseEvent
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public string $name,
        public array $payload = [],
        public ?int $id = null,
    ) {}

    public function encode(): string
    {
        $frame = '';
        if ($this->id !== null) {
            $frame .= 'id: ' . $this->id . "\n";
        }
        $frame .= 'event: ' . $this->name . "\n";

        foreach (explode("\n", $this->data()) as $line) {
            $frame .= 'data: ' . rtrim($line, "\r") . "\n";
        }

        return $frame . "\n";
    }

    private function data(): string
    {
        $json = json_encode(
            $this->payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
        );

        // An unencodable payload must not take the stream down with it: the
        // client can render an event it does not understand, but it cannot
        // recover from a truncated frame.
        return $json !== false ? $json : '{}';
    }

    /**
     * The heartbeat.
     *
     * A proxy with an idle timeout closes a connection that has been silent too
     * long, and an agent turn is silent for as long as the model takes to
     * answer. This is a named event rather than the specification's comment
     * line, because the client has a use for it beyond keeping the socket warm:
     * it is the only proof, while a tool is running, that the turn is alive
     * rather than wedged.
     */
    public static function ping(): self
    {
        return new self('ping', ['at' => time()]);
    }
}
