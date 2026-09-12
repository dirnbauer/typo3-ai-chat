<?php

declare(strict_types=1);

namespace Webconsulting\Typo3AiChat\Service;

use Webconsulting\Typo3AiChat\Domain\Model\Message;

/**
 * Everything one turn produced, as the API surface reports it.
 */
final readonly class TurnResult
{
    /**
     * @param list<Message>                                                  $messages        the rows this turn appended
     * @param array<string, mixed>                                           $pendingApproval empty unless the run suspended
     * @param array{promptTokens: int, completionTokens: int, totalTokens: int} $usage
     */
    public function __construct(
        public string $runUuid,
        public TurnOutcome $outcome,
        public array $messages,
        public array $pendingApproval,
        public array $usage,
    ) {}

    /**
     * The last assistant message with prose in it — what a non-streaming client
     * renders as "the answer".
     */
    public function finalMessage(): ?Message
    {
        for ($index = count($this->messages) - 1; $index >= 0; --$index) {
            $message = $this->messages[$index];
            if ($message->toolCalls === [] && $message->content !== '') {
                return $message;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'runUuid' => $this->runUuid,
            'outcome' => $this->outcome->outcome,
            'status' => $this->outcome->status->value,
            'message' => $this->outcome->message,
            'messages' => array_map(static fn(Message $m): array => $m->toArray(), $this->messages),
            'pendingApproval' => $this->pendingApproval,
            'usage' => $this->usage,
        ];
    }
}
