<?php

declare(strict_types=1);

namespace Webconsulting\Typo3AiChat\Tests\Functional;

use PHPUnit\Framework\Assert;
use Psr\Http\Message\ResponseInterface;

/**
 * Reading a JSON API response without lying to the type checker.
 *
 * `json_decode()` returns mixed, and a test that reaches into it with `[...]`
 * is asserting a shape it never checked — which is how a renamed field turns
 * into a confusing null instead of a clear failure. These helpers make the
 * shape assumption explicit and fail on it directly.
 */
trait DecodesApiResponses
{
    /**
     * @return array<string, mixed>
     */
    protected function decodeJson(ResponseInterface $response, int $expectedStatus = 200): array
    {
        Assert::assertSame($expectedStatus, $response->getStatusCode(), (string)$response->getBody());

        $decoded = json_decode((string)$response->getBody(), true);
        Assert::assertIsArray($decoded);

        $body = [];
        foreach ($decoded as $key => $value) {
            if (is_string($key)) {
                $body[$key] = $value;
            }
        }

        return $body;
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return list<string>
     */
    protected function eventNames(array $body): array
    {
        $names = [];
        foreach ($this->eventList($body) as $event) {
            $names[] = $event['event'];
        }

        return $names;
    }

    /**
     * The payload of the first event with this name.
     *
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    protected function eventPayload(array $body, string $name): array
    {
        foreach ($this->eventList($body) as $event) {
            if ($event['event'] === $name) {
                return $event['data'];
            }
        }

        Assert::fail(sprintf('No "%s" event was emitted.', $name));
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return list<array{event: string, data: array<string, mixed>}>
     */
    protected function eventList(array $body): array
    {
        $raw = $body['events'] ?? null;
        Assert::assertIsArray($raw, 'The JSON transport always reports the event list.');

        $events = [];
        foreach ($raw as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $name = $entry['event'] ?? null;
            $data = $entry['data'] ?? [];
            if (!is_string($name)) {
                continue;
            }

            $payload = [];
            if (is_array($data)) {
                foreach ($data as $key => $value) {
                    if (is_string($key)) {
                        $payload[$key] = $value;
                    }
                }
            }

            $events[] = ['event' => $name, 'data' => $payload];
        }

        return $events;
    }

    /**
     * A string somewhere in a decoded body, asserted rather than assumed.
     *
     * @param array<string, mixed> $body
     */
    protected function stringAt(array $body, string ...$path): string
    {
        $value = $this->valueAt($body, ...$path);
        Assert::assertIsString($value, sprintf('"%s" is a string in the response.', implode('.', $path)));

        return $value;
    }

    /**
     * @param array<string, mixed> $body
     */
    protected function intAt(array $body, string ...$path): int
    {
        $value = $this->valueAt($body, ...$path);
        Assert::assertIsInt($value, sprintf('"%s" is an integer in the response.', implode('.', $path)));

        return $value;
    }

    /**
     * @param array<string, mixed> $body
     */
    protected function valueAt(array $body, string ...$path): mixed
    {
        $value = $body;
        foreach ($path as $segment) {
            Assert::assertIsArray($value, sprintf('"%s" is reachable in the response.', implode('.', $path)));
            Assert::assertArrayHasKey($segment, $value);
            $value = $value[$segment];
        }

        return $value;
    }
}
