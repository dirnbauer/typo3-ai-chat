<?php

declare(strict_types=1);

namespace Webconsulting\Typo3AiChat\Configuration;

use TYPO3\CMS\Core\Configuration\ExtensionConfiguration as Typo3ExtensionConfiguration;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Typed reader for this extension's settings.
 *
 * Everything TYPO3 stores here is a string, and every consumer wants an int, a
 * bool or a list. Reading it in one place keeps the defaults in one place too —
 * they are stated here AND in ext_conf_template.txt, and the pair is what a
 * fresh install and an upgraded one respectively see.
 */
class ExtensionConfiguration
{
    /** @var array<string, mixed> */
    private array $config;

    public function __construct()
    {
        /** @var array<string, mixed> $config */
        $config = (array)GeneralUtility::makeInstance(Typo3ExtensionConfiguration::class)
            ->get('webconsulting_ai_chat');
        $this->config = $config;
    }

    /**
     * The nr-llm Task whose LLM configuration the chat runs under. 0 means the
     * chat is not configured, and every surface says so rather than guessing a
     * provider.
     */
    public function getLlmTaskUid(): int
    {
        return $this->getInt('llmTaskUid', 0);
    }

    public function getMaxIterations(): int
    {
        return max(1, $this->getInt('maxIterations', 8));
    }

    /**
     * @return list<int>
     */
    public function getAllowedGroupIds(): array
    {
        $groups = $this->getString('allowedGroups', '');
        if (trim($groups) === '') {
            return [];
        }

        $ids = [];
        foreach (explode(',', $groups) as $candidate) {
            $uid = (int)trim($candidate);
            if ($uid > 0) {
                $ids[] = $uid;
            }
        }

        return array_values(array_unique($ids));
    }

    public function getTurnsPerMinute(): int
    {
        return max(0, $this->getInt('turnsPerMinute', 10));
    }

    public function getMaxConversationsPerUser(): int
    {
        return max(0, $this->getInt('maxConversationsPerUser', 50));
    }

    public function getMaxActiveConversationsPerUser(): int
    {
        return max(0, $this->getInt('maxActiveConversationsPerUser', 3));
    }

    public function getMaxMessageLength(): int
    {
        return max(0, $this->getInt('maxMessageLength', 10000));
    }

    /**
     * The FAL folder attachments live under, always with a trailing slash so
     * callers can append a per-user and per-conversation segment.
     */
    public function getUploadFolder(): string
    {
        $folder = trim($this->getString('uploadFolder', '1:/ai_chat/'));
        if ($folder === '') {
            $folder = '1:/ai_chat/';
        }

        return rtrim($folder, '/') . '/';
    }

    public function getAutoArchiveDays(): int
    {
        return max(0, $this->getInt('autoArchiveDays', 30));
    }

    public function getAttachmentRetentionDays(): int
    {
        return max(0, $this->getInt('attachmentRetentionDays', 90));
    }

    private function getInt(string $key, int $default): int
    {
        $value = $this->config[$key] ?? null;

        return is_numeric($value) ? (int)$value : $default;
    }

    private function getString(string $key, string $default): string
    {
        $value = $this->config[$key] ?? $default;

        return is_scalar($value) ? (string)$value : $default;
    }
}
