<?php

declare(strict_types=1);

namespace Webconsulting\Typo3AiChat\Domain\Model;

use JsonException;
use Webconsulting\Typo3AiChat\Enum\MessageRole;

/**
 * One row of a conversation transcript.
 *
 * A plain value object over a database row — no Extbase — because the chat API
 * reads and writes these on a hot path and an ORM identity map buys nothing
 * here.
 *
 * The row is deliberately the WHOLE message and nothing more. Tool traces are
 * NOT copied in: nr-llm already persists every step of a run and exposes them
 * through `AgentRuntime::events()`, and a second copy here would be a second
 * thing to keep correct, to purge, and to get out of sync. `run_uuid` is the
 * join to that record.
 */
final readonly class Message
{
    /**
     * @param list<array<string, mixed>> $toolCalls   assistant tool-call requests, in the provider wire shape
     * @param list<array<string, mixed>> $attachments FAL references carried by a user message
     */
    public function __construct(
        public int $uid,
        public int $conversation,
        public int $sequence,
        public MessageRole $role,
        public string $content,
        public array $toolCalls = [],
        public string $toolCallId = '',
        public array $attachments = [],
        public string $runUuid = '',
        public int $promptTokens = 0,
        public int $completionTokens = 0,
        public int $crdate = 0,
    ) {}

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            uid: self::int($row, 'uid'),
            conversation: self::int($row, 'conversation'),
            sequence: self::int($row, 'sequence'),
            role: MessageRole::tryFrom(self::string($row, 'role')) ?? MessageRole::User,
            content: self::string($row, 'content'),
            toolCalls: self::jsonList($row, 'tool_calls'),
            toolCallId: self::string($row, 'tool_call_id'),
            attachments: self::jsonList($row, 'attachments'),
            runUuid: self::string($row, 'run_uuid'),
            promptTokens: self::int($row, 'prompt_tokens'),
            completionTokens: self::int($row, 'completion_tokens'),
            crdate: self::int($row, 'crdate'),
        );
    }

    /**
     * The insertable row. `uid` and `sequence` are left out: the sequence is
     * assigned by the repository inside the same statement that inserts, so a
     * caller cannot accidentally choose one.
     *
     * @return array<string, int|string|null>
     */
    public function toRow(): array
    {
        return [
            'pid' => 0,
            'conversation' => $this->conversation,
            'role' => $this->role->value,
            'content' => $this->content,
            'tool_calls' => $this->toolCalls === [] ? null : self::encode($this->toolCalls),
            'tool_call_id' => $this->toolCallId,
            'attachments' => $this->attachments === [] ? null : self::encode($this->attachments),
            'run_uuid' => $this->runUuid,
            'prompt_tokens' => $this->promptTokens,
            'completion_tokens' => $this->completionTokens,
        ];
    }

    /**
     * The shape the JSON API and the SSE frames hand to the client.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'uid' => $this->uid,
            'sequence' => $this->sequence,
            'role' => $this->role->value,
            'content' => $this->content,
            'createdAt' => $this->crdate,
        ];

        if ($this->toolCalls !== []) {
            $data['toolCalls'] = $this->toolCalls;
        }
        if ($this->toolCallId !== '') {
            $data['toolCallId'] = $this->toolCallId;
        }
        if ($this->attachments !== []) {
            $data['attachments'] = $this->attachments;
        }
        if ($this->runUuid !== '') {
            $data['runUuid'] = $this->runUuid;
        }
        if ($this->promptTokens > 0 || $this->completionTokens > 0) {
            $data['tokens'] = [
                'prompt' => $this->promptTokens,
                'completion' => $this->completionTokens,
            ];
        }

        return $data;
    }

    /**
     * @param list<array<string, mixed>> $value
     */
    private static function encode(array $value): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        } catch (JsonException) {
            return '[]';
        }
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return list<array<string, mixed>>
     */
    private static function jsonList(array $row, string $key): array
    {
        $raw = $row[$key] ?? null;
        if (!is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $list = [];
        foreach ($decoded as $item) {
            if (!is_array($item)) {
                continue;
            }
            $entry = [];
            foreach ($item as $itemKey => $itemValue) {
                if (is_string($itemKey)) {
                    $entry[$itemKey] = $itemValue;
                }
            }
            $list[] = $entry;
        }

        return $list;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function int(array $row, string $key): int
    {
        $value = $row[$key] ?? 0;

        return is_numeric($value) ? (int)$value : 0;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function string(array $row, string $key): string
    {
        $value = $row[$key] ?? '';

        return is_scalar($value) ? (string)$value : '';
    }
}
