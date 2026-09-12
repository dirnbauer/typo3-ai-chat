<?php

declare(strict_types=1);

namespace Webconsulting\Typo3AiChat\Tests\Unit\Configuration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration as Typo3ExtensionConfiguration;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Webconsulting\Typo3AiChat\Configuration\ExtensionConfiguration;

/**
 * Every value TYPO3 stores here is a string, and every consumer wants something
 * else. The interesting cases are therefore the ones where a wrong cast would
 * be silently PERMISSIVE: a blank group list that must mean "everybody", and a
 * limit whose absence must mean the documented default rather than zero.
 */
final class ExtensionConfigurationTest extends TestCase
{
    protected function tearDown(): void
    {
        GeneralUtility::purgeInstances();
        parent::tearDown();
    }

    #[Test]
    public function valuesAreReadAsTheTypesTheirConsumersNeed(): void
    {
        $config = $this->configWith([
            'llmTaskUid' => '42',
            'maxIterations' => '12',
            'turnsPerMinute' => '5',
            'maxMessageLength' => '5000',
            'maxConversationsPerUser' => '20',
            'maxActiveConversationsPerUser' => '2',
            'autoArchiveDays' => '14',
            'attachmentRetentionDays' => '30',
        ]);

        self::assertSame(42, $config->getLlmTaskUid());
        self::assertSame(12, $config->getMaxIterations());
        self::assertSame(5, $config->getTurnsPerMinute());
        self::assertSame(5000, $config->getMaxMessageLength());
        self::assertSame(20, $config->getMaxConversationsPerUser());
        self::assertSame(2, $config->getMaxActiveConversationsPerUser());
        self::assertSame(14, $config->getAutoArchiveDays());
        self::assertSame(30, $config->getAttachmentRetentionDays());
    }

    #[Test]
    public function missingKeysFallBackToTheDocumentedDefaults(): void
    {
        $config = $this->configWith([]);

        self::assertSame(0, $config->getLlmTaskUid(), 'Unconfigured means unavailable, not "task 1".');
        self::assertSame(8, $config->getMaxIterations());
        self::assertSame(10, $config->getTurnsPerMinute());
        self::assertSame(10000, $config->getMaxMessageLength());
        self::assertSame(50, $config->getMaxConversationsPerUser());
        self::assertSame(3, $config->getMaxActiveConversationsPerUser());
        self::assertSame(30, $config->getAutoArchiveDays());
        self::assertSame(90, $config->getAttachmentRetentionDays());
        self::assertSame('1:/ai_chat/', $config->getUploadFolder());
    }

    /**
     * @param list<int> $expected
     */
    #[Test]
    #[DataProvider('groupLists')]
    public function theAllowedGroupListIsParsedIntoUids(string $raw, array $expected): void
    {
        self::assertSame($expected, $this->configWith(['allowedGroups' => $raw])->getAllowedGroupIds());
    }

    /**
     * @return iterable<string, array{0: string, 1: list<int>}>
     */
    public static function groupLists(): iterable
    {
        yield 'empty means everybody' => ['', []];
        yield 'whitespace also means everybody' => ['   ', []];
        yield 'a plain list' => ['1,3,5', [1, 3, 5]];
        yield 'spaces are tolerated' => ['1, 3 ,5', [1, 3, 5]];
        yield 'duplicates collapse' => ['3,3,7', [3, 7]];
        yield 'zero and nonsense are dropped' => ['0,abc,4', [4]];
    }

    #[Test]
    public function theUploadFolderAlwaysEndsInASlashSoCallersCanAppend(): void
    {
        self::assertSame('1:/chat/', $this->configWith(['uploadFolder' => '1:/chat'])->getUploadFolder());
        self::assertSame('2:/x/y/', $this->configWith(['uploadFolder' => ' 2:/x/y/ '])->getUploadFolder());
        self::assertSame(
            '1:/ai_chat/',
            $this->configWith(['uploadFolder' => ''])->getUploadFolder(),
            'A blank folder falls back rather than writing to the storage root.',
        );
    }

    #[Test]
    public function aTurnMustBeAllowedAtLeastOneRound(): void
    {
        self::assertSame(1, $this->configWith(['maxIterations' => '0'])->getMaxIterations());
        self::assertSame(1, $this->configWith(['maxIterations' => '-5'])->getMaxIterations());
    }

    #[Test]
    public function limitsCannotBeNegative(): void
    {
        $config = $this->configWith([
            'turnsPerMinute' => '-1',
            'maxMessageLength' => '-1',
            'autoArchiveDays' => '-1',
        ]);

        self::assertSame(0, $config->getTurnsPerMinute(), '0 is the documented "no limit".');
        self::assertSame(0, $config->getMaxMessageLength());
        self::assertSame(0, $config->getAutoArchiveDays());
    }

    /**
     * @param array<string, mixed> $values
     */
    private function configWith(array $values): ExtensionConfiguration
    {
        $typo3Config = $this->createStub(Typo3ExtensionConfiguration::class);
        $typo3Config->method('get')->willReturn($values);
        GeneralUtility::addInstance(Typo3ExtensionConfiguration::class, $typo3Config);

        return new ExtensionConfiguration();
    }
}
