<?php

declare(strict_types=1);

namespace Webconsulting\Typo3AiChat\Tests\Unit\Service;

use Netresearch\NrLlm\Domain\Enum\ToolGroup;
use Netresearch\NrLlm\Domain\ValueObject\EditorAction;
use Netresearch\NrLlm\Service\Tool\ToolAvailabilityServiceInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration as Typo3ExtensionConfiguration;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Webconsulting\Typo3AiChat\Configuration\ExtensionConfiguration;
use Webconsulting\Typo3AiChat\Service\BackendUserContext;
use Webconsulting\Typo3AiChat\Service\ToolAccessService;

/**
 * Three gates intersect here, and the interesting cases are the ones where they
 * disagree: deny beating allow, and — the subtle one — an ABSENT allow list
 * meaning "do not narrow" while an EMPTY one means "allow nothing".
 */
final class ToolAccessServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['BE_USER']);
        GeneralUtility::purgeInstances();
        parent::tearDown();
    }

    #[Test]
    public function withoutTsConfigTheGloballyEnabledSetIsOffered(): void
    {
        $service = $this->service(['typo3_GetPage', 'typo3_WriteTable'], []);

        self::assertSame(['typo3_GetPage', 'typo3_WriteTable'], $service->allowedToolNames());
    }

    #[Test]
    public function anAllowListNarrowsToItsIntersection(): void
    {
        $service = $this->service(
            ['typo3_GetPage', 'typo3_WriteTable', 'read_records'],
            ['allow' => 'typo3_GetPage, read_records, typo3_NotEnabled'],
        );

        self::assertSame(
            ['typo3_GetPage', 'read_records'],
            $service->allowedToolNames(),
            'An allow list can only narrow; naming a tool nr-llm has disabled does not enable it.',
        );
    }

    #[Test]
    public function aDenyListRemovesEvenWhatAnAllowListNamed(): void
    {
        $service = $this->service(
            ['typo3_GetPage', 'typo3_WriteTable'],
            ['allow' => 'typo3_GetPage,typo3_WriteTable', 'deny' => 'typo3_WriteTable'],
        );

        self::assertSame(['typo3_GetPage'], $service->allowedToolNames());
    }

    /**
     * The distinction a collapsed implementation would lose — and it would lose
     * it in the permissive direction.
     */
    #[Test]
    public function anEmptyAllowListAllowsNothingWhileAnAbsentOneAllowsEverything(): void
    {
        $absent = $this->service(['typo3_GetPage'], []);
        self::assertSame(['typo3_GetPage'], $absent->allowedToolNames());

        $empty = $this->service(['typo3_GetPage'], ['allow' => '']);
        self::assertSame([], $empty->allowedToolNames());
    }

    #[Test]
    public function aUserOutsideTheAllowedGroupsGetsNoToolsAtAll(): void
    {
        $service = $this->service(
            ['typo3_GetPage'],
            [],
            allowedGroups: '5',
            userGroups: [1, 2],
        );

        self::assertSame([], $service->allowedToolNames());
    }

    #[Test]
    public function anAdminIsNeverLockedOutByTheGroupGate(): void
    {
        $service = $this->service(
            ['typo3_GetPage'],
            [],
            allowedGroups: '5',
            userGroups: [1],
            admin: true,
        );

        self::assertSame(['typo3_GetPage'], $service->allowedToolNames());
    }

    #[Test]
    public function theMcpNamesAreReportedWithoutTheirPrefix(): void
    {
        $service = $this->service(['typo3_GetPage', 'read_records', 'typo3_WriteTable'], []);

        self::assertSame(['GetPage', 'WriteTable'], $service->allowedMcpToolNames());
    }

    /**
     * @param list<string>         $enabled
     * @param array<string, mixed> $toolsTsConfig
     * @param list<int>            $userGroups
     */
    private function service(
        array $enabled,
        array $toolsTsConfig,
        string $allowedGroups = '',
        array $userGroups = [1],
        bool $admin = false,
    ): ToolAccessService {
        $availability = new class($enabled) implements ToolAvailabilityServiceInterface {
            /** @param list<string> $enabled */
            public function __construct(private readonly array $enabled) {}

            public function enabledNames(): array
            {
                return $this->enabled;
            }

            public function states(): array
            {
                return [];
            }

            public function editorActions(): array
            {
                return [];
            }

            public function groupStates(): array
            {
                return [];
            }
        };

        $user = new class($toolsTsConfig) extends BackendUserAuthentication {
            /** @param array<string, mixed> $toolsTsConfig */
            public function __construct(private readonly array $toolsTsConfig)
            {
                parent::__construct();
            }

            public function getTSConfig(): array
            {
                return $this->toolsTsConfig === []
                    ? []
                    : ['tx_webconsultingaichat.tools.' => $this->toolsTsConfig];
            }
        };
        $user->user = ['uid' => 7, 'admin' => $admin ? 1 : 0];
        $user->userGroupsUID = $userGroups;
        $GLOBALS['BE_USER'] = $user;

        $typo3Config = $this->createStub(Typo3ExtensionConfiguration::class);
        $typo3Config->method('get')->willReturn(['allowedGroups' => $allowedGroups]);
        GeneralUtility::addInstance(Typo3ExtensionConfiguration::class, $typo3Config);

        return new ToolAccessService($availability, new ExtensionConfiguration(), new BackendUserContext());
    }

    /**
     * Guards an assumption this test file makes about nr-llm's own vocabulary:
     * if ToolGroup or EditorAction moved, the stub above would silently stop
     * matching the interface it claims to implement.
     */
    #[Test]
    public function nrLlmStillDefinesTheVocabularyThisStubImplements(): void
    {
        self::assertTrue(enum_exists(ToolGroup::class));
        self::assertTrue(class_exists(EditorAction::class));
    }
}
