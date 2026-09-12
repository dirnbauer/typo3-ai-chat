<?php

declare(strict_types=1);

namespace Webconsulting\Typo3AiChat\Tests\Functional;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use Webconsulting\Typo3AiChat\Testing\ScriptedProvider;

/**
 * The shared setup for every functional chat test.
 *
 * Two things are worth spelling out.
 *
 * The scripted provider's environment flag is set BEFORE `parent::setUp()`,
 * because that is when the test instance's container is compiled and
 * Configuration/Services.php reads the flag. Setting it afterwards would leave
 * the container without a provider and produce a failure that looks like a
 * wiring bug.
 *
 * The ambient `$GLOBALS['BE_USER']` is genuinely required rather than
 * convenient: the MCP tools read it, and McpCatalogTool refuses to run when it
 * is not the run's actor. A functional test of a turn that did not sign anybody
 * in would exercise only the refusal path.
 */
abstract class AbstractChatFunctionalTestCase extends FunctionalTestCase
{
    protected const BE_USER_UID = 1;

    /**
     * `workspaces` is not optional here: the MCP server declares it as a
     * dependency because its record tools go through workspace overlays, and
     * without it the package graph refuses to build at all.
     */
    protected array $coreExtensionsToLoad = [
        'extbase',
        'fluid',
        'workspaces',
    ];

    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
        'hn/typo3-mcp-server',
        'webconsulting/typo3-ai-chat',
    ];

    protected array $configurationToUseInTestInstance = [
        'EXTENSIONS' => [
            'webconsulting_ai_chat' => [
                'llmTaskUid' => '1',
                'maxIterations' => '4',
                'turnsPerMinute' => '10',
                'allowedGroups' => '',
                'maxMessageLength' => '10000',
                'maxConversationsPerUser' => '50',
                'maxActiveConversationsPerUser' => '0',
                'uploadFolder' => '1:/ai_chat/',
                'autoArchiveDays' => '30',
                'attachmentRetentionDays' => '90',
            ],
        ],
    ];

    protected function setUp(): void
    {
        putenv(ScriptedProvider::ENV_FLAG . '=1');
        putenv(ScriptedProvider::ENV_SCRIPT . '=' . sys_get_temp_dir() . '/wcaichat-script-' . getmypid() . '.json');
        ScriptedProvider::reset();

        parent::setUp();

        self::assertTrue(
            Environment::getContext()->isTesting(),
            'The scripted provider is only registered outside production, so the suite must run in a testing context.',
        );

        // The rate limiter keeps its window in TYPO3's caching framework, which
        // is NOT part of the per-test database reset. Without this, the eleventh
        // turn of one test is the first refusal of the next.
        $this->get(CacheManager::class)->getCache('ratelimiter')->flush();

        $this->importCSVDataSet(__DIR__ . '/Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/Fixtures/tx_nrllm_provider.csv');
        $this->importCSVDataSet(__DIR__ . '/Fixtures/tx_nrllm_model.csv');
        $this->importCSVDataSet(__DIR__ . '/Fixtures/tx_nrllm_configuration.csv');
        $this->importCSVDataSet(__DIR__ . '/Fixtures/tx_nrllm_task.csv');
    }

    protected function tearDown(): void
    {
        ScriptedProvider::reset();
        putenv(ScriptedProvider::ENV_FLAG);
        putenv(ScriptedProvider::ENV_SCRIPT);
        unset($GLOBALS['BE_USER']);

        parent::tearDown();
    }

    /**
     * Sign a backend user in the way the chat actually sees one: as the ambient
     * user the MCP tools will read.
     */
    protected function signIn(int $uid = self::BE_USER_UID, bool $admin = true): BackendUserAuthentication
    {
        $user = $this->setUpBackendUser($uid);
        $user->user['admin'] = $admin ? 1 : 0;
        $GLOBALS['BE_USER'] = $user;

        return $user;
    }
}
