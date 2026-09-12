<?php

declare(strict_types=1);

namespace Webconsulting\Typo3AiChat\Domain\Model;

use Webconsulting\Typo3AiChat\Enum\ConversationStatus;

/**
 * A conversation's own state — its identity, its lifecycle and the decision it
 * may be waiting on. NOT its messages: those are rows of their own
 * ({@see Message}). NOT its tool trace either: nr-llm persists every step of a
 * run and `run_uuid` is the join to it, so nothing is copied here.
 *
 * A plain value object over a database row, hydrated with {@see fromRow()}.
 */
final class Conversation
{
    private int $uid = 0;
    private int $beUser = 0;
    private string $title = '';
    private int $messageCount = 0;
    private string $status = 'idle';
    private string $runUuid = '';
    private string $pendingApproval = '';
    private string $systemPrompt = '';
    private bool $autoApproveTools = false;
    private bool $archived = false;
    private bool $pinned = false;
    private string $errorMessage = '';
    private int $lastMessageAt = 0;
    private int $tstamp = 0;
    private int $crdate = 0;

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $conversation = new self();
        $conversation->uid = self::int($row, 'uid');
        $conversation->beUser = self::int($row, 'be_user');
        $conversation->title = self::string($row, 'title');
        $conversation->messageCount = self::int($row, 'message_count');
        $conversation->status = self::string($row, 'status', ConversationStatus::Idle->value);
        $conversation->runUuid = self::string($row, 'run_uuid');
        $conversation->pendingApproval = self::string($row, 'pending_approval');
        $conversation->systemPrompt = self::string($row, 'system_prompt');
        $conversation->autoApproveTools = self::int($row, 'auto_approve_tools') === 1;
        $conversation->archived = self::int($row, 'archived') === 1;
        $conversation->pinned = self::int($row, 'pinned') === 1;
        $conversation->errorMessage = self::string($row, 'error_message');
        $conversation->lastMessageAt = self::int($row, 'last_message_at');
        $conversation->tstamp = self::int($row, 'tstamp');
        $conversation->crdate = self::int($row, 'crdate');

        return $conversation;
    }

    /**
     * @return array<string, int|string>
     */
    public function toRow(): array
    {
        return [
            'be_user' => $this->beUser,
            'title' => $this->title,
            'message_count' => $this->messageCount,
            'status' => $this->status,
            'run_uuid' => $this->runUuid,
            'pending_approval' => $this->pendingApproval,
            'system_prompt' => $this->systemPrompt,
            'auto_approve_tools' => (int)$this->autoApproveTools,
            'archived' => (int)$this->archived,
            'pinned' => (int)$this->pinned,
            'error_message' => $this->errorMessage,
            'last_message_at' => $this->lastMessageAt,
        ];
    }

    /**
     * The shape the JSON API hands to the client.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'uid' => $this->uid,
            'title' => $this->title,
            'status' => $this->status,
            'messageCount' => $this->messageCount,
            'pinned' => $this->pinned,
            'archived' => $this->archived,
            'autoApproveTools' => $this->autoApproveTools,
            'runUuid' => $this->runUuid,
            'pendingApproval' => $this->getPendingApproval(),
            'errorMessage' => $this->errorMessage,
            'lastMessageAt' => $this->lastMessageAt,
            'createdAt' => $this->crdate,
        ];
    }

    public function getUid(): int
    {
        return $this->uid;
    }

    public function getBeUser(): int
    {
        return $this->beUser;
    }

    public function setBeUser(int $beUser): void
    {
        $this->beUser = $beUser;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): void
    {
        $this->title = mb_substr(trim($title), 0, 255);
    }

    public function getMessageCount(): int
    {
        return $this->messageCount;
    }

    public function setMessageCount(int $count): void
    {
        $this->messageCount = max(0, $count);
    }

    public function getStatus(): ConversationStatus
    {
        return ConversationStatus::tryFrom($this->status) ?? ConversationStatus::Idle;
    }

    public function setStatus(ConversationStatus $status): void
    {
        $this->status = $status->value;
    }

    public function getRunUuid(): string
    {
        return $this->runUuid;
    }

    public function setRunUuid(string $runUuid): void
    {
        $this->runUuid = mb_substr($runUuid, 0, 64);
    }

    /**
     * The tool calls this conversation is suspended on, in the shape the
     * approval surface renders and the approval route echoes back. Empty unless
     * the status is awaiting_approval.
     *
     * @return array<string, mixed>
     */
    public function getPendingApproval(): array
    {
        if ($this->pendingApproval === '') {
            return [];
        }

        $decoded = json_decode($this->pendingApproval, true);
        if (!is_array($decoded)) {
            return [];
        }

        $normalised = [];
        foreach ($decoded as $key => $value) {
            if (is_string($key)) {
                $normalised[$key] = $value;
            }
        }

        return $normalised;
    }

    /**
     * @param array<string, mixed> $pending
     */
    public function setPendingApproval(array $pending): void
    {
        $encoded = $pending === [] ? '' : json_encode($pending, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        $this->pendingApproval = is_string($encoded) ? $encoded : '';
    }

    public function getSystemPrompt(): string
    {
        return $this->systemPrompt;
    }

    public function setSystemPrompt(string $prompt): void
    {
        $this->systemPrompt = mb_substr($prompt, 0, 10000);
    }

    /**
     * Whether the user asked for permitted write tools to run without a
     * per-turn decision in THIS conversation. It never widens what the tool
     * policy allows — it only skips the pause for tools already permitted.
     */
    public function isAutoApproveTools(): bool
    {
        return $this->autoApproveTools;
    }

    public function setAutoApproveTools(bool $autoApprove): void
    {
        $this->autoApproveTools = $autoApprove;
    }

    public function isArchived(): bool
    {
        return $this->archived;
    }

    public function setArchived(bool $archived): void
    {
        $this->archived = $archived;
    }

    public function isPinned(): bool
    {
        return $this->pinned;
    }

    public function setPinned(bool $pinned): void
    {
        $this->pinned = $pinned;
    }

    public function getErrorMessage(): string
    {
        return $this->errorMessage;
    }

    public function setErrorMessage(string $message): void
    {
        $this->errorMessage = $message;
    }

    public function getLastMessageAt(): int
    {
        return $this->lastMessageAt;
    }

    public function setLastMessageAt(int $timestamp): void
    {
        $this->lastMessageAt = max(0, $timestamp);
    }

    public function getTstamp(): int
    {
        return $this->tstamp;
    }

    public function getCrdate(): int
    {
        return $this->crdate;
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
    private static function string(array $row, string $key, string $default = ''): string
    {
        $value = $row[$key] ?? $default;

        return is_scalar($value) ? (string)$value : $default;
    }
}
