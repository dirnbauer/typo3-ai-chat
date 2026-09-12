<?php

declare(strict_types=1);

namespace Webconsulting\Typo3AiChat\Service;

use Symfony\Component\RateLimiter\RateLimiterFactory;
use TYPO3\CMS\Core\RateLimiter\Storage\CachingFrameworkStorage;
use Webconsulting\Typo3AiChat\Configuration\ExtensionConfiguration;

/**
 * Caps how many turns one backend user may start per minute.
 *
 * The limit is per USER and not per conversation, because the cost it protects
 * against is per user: every turn is a paid provider call, and opening a second
 * conversation must not double the spend. The per-conversation limit is a
 * different mechanism entirely — the `status=processing` claim, which allows
 * exactly one in-flight turn per conversation.
 *
 * State lives in TYPO3's caching framework rather than in a table: the window
 * is a minute, it is worthless after that, and it must not survive a cache
 * flush as a lockout.
 */
final class TurnRateLimiter
{
    private ?RateLimiterFactory $factory = null;

    public function __construct(
        private readonly ExtensionConfiguration $config,
        private readonly CachingFrameworkStorage $storage,
    ) {}

    /**
     * Consume one token for this user.
     *
     * @return bool true when the turn may proceed
     */
    public function consume(int $beUserUid): bool
    {
        $factory = $this->factory();
        if ($factory === null) {
            return true;
        }

        return $factory->create((string)$beUserUid)->consume()->isAccepted();
    }

    /**
     * How many turns are left in the current window, without consuming one.
     * -1 when no limit is configured.
     */
    public function remaining(int $beUserUid): int
    {
        $factory = $this->factory();
        if ($factory === null) {
            return -1;
        }

        return $factory->create((string)$beUserUid)->consume(0)->getRemainingTokens();
    }

    public function limit(): int
    {
        return $this->config->getTurnsPerMinute();
    }

    private function factory(): ?RateLimiterFactory
    {
        $limit = $this->config->getTurnsPerMinute();
        if ($limit <= 0) {
            return null;
        }

        if ($this->factory instanceof RateLimiterFactory) {
            return $this->factory;
        }

        // A sliding window rather than a fixed one: a fixed window lets a user
        // spend the whole allowance at 11:59:59 and the whole next allowance at
        // 12:00:00, which is exactly twice the limit at the moment it matters.
        $this->factory = new RateLimiterFactory(
            [
                'id' => 'webconsulting_ai_chat_turn',
                'policy' => 'sliding_window',
                'limit' => $limit,
                'interval' => '1 minute',
            ],
            $this->storage,
        );

        return $this->factory;
    }
}
