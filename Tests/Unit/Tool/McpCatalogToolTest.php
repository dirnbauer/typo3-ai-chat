<?php

declare(strict_types=1);

namespace Webconsulting\Typo3AiChat\Tests\Unit\Tool;

use Hn\McpServer\MCP\Tool\ToolInterface as McpToolInterface;
use Hn\McpServer\MCP\ToolRegistry;
use Hn\McpServer\Service\CapabilityManifestService;
use Hn\McpServer\Service\McpToolCatalogService;
use Hn\McpServer\Service\ToolResultNormalizer;
use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;
use Netresearch\NrLlm\Domain\Enum\ArtifactType;
use Netresearch\NrLlm\Domain\Enum\ToolDataClass;
use Netresearch\NrLlm\Domain\Enum\ToolEffect;
use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Webconsulting\Typo3AiChat\Tool\McpCatalogTool;
use Webconsulting\Typo3AiChat\Tool\ToolResultConverter;

/**
 * The adapter bridging nr-llm's explicit-identity model to MCP tools that read
 * the ambient backend user.
 *
 * Two things are worth a test here. The first is the mapping, because the MCP
 * and nr-llm result shapes differ in a way that matters for egress. The second
 * is the identity check — the thing that makes this bridge safe at all, and the
 * thing a future refactor is most likely to quietly weaken.
 */
final class McpCatalogToolTest extends TestCase
{
    /** @var list<string> */
    private array $manifestFiles = [];

    protected function tearDown(): void
    {
        unset($GLOBALS['BE_USER']);
        GeneralUtility::purgeInstances();
        foreach ($this->manifestFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        $this->manifestFiles = [];

        parent::tearDown();
    }

    /**
     * The MCP server enforces its capability manifest inside AbstractTool, and
     * it resolves that manifest through GeneralUtility — which finds nothing in
     * a unit test, so every tool would be denied for a reason that has nothing
     * to do with what is being tested here.
     *
     * Rather than route around the gate, the test supplies a manifest that
     * permits the one tool it uses. The gate still runs, and a change that
     * broke it would still be visible.
     */
    private function permitTool(string $name): void
    {
        $path = tempnam(sys_get_temp_dir(), 'wcaichat-manifest-') . '.yaml';
        $this->manifestFiles[] = $path;
        file_put_contents($path, Yaml::dump([
            'capabilities' => [
                'version' => '1.0',
                'extension' => 'mcp_server',
                'x-mcp' => ['tools' => [$name => []]],
            ],
        ], 6));

        GeneralUtility::addInstance(CapabilityManifestService::class, new CapabilityManifestService(
            self::createStub(ExtensionConfiguration::class),
            self::createStub(SiteFinder::class),
            null,
            $path,
        ));
    }

    #[Test]
    public function theModelFacingNameIsThePrefixedMcpName(): void
    {
        self::assertSame('typo3_WriteTable', McpCatalogTool::toolName('WriteTable'));
        self::assertSame('WriteTable', McpCatalogTool::mcpName('typo3_WriteTable'));
    }

    #[Test]
    public function aNameThatIsNotOursHasNoMcpNameBehindIt(): void
    {
        self::assertNull(McpCatalogTool::mcpName('read_records'));
        self::assertNull(McpCatalogTool::mcpName('typo3_'), 'An empty remainder names no tool.');
    }

    #[Test]
    public function textContentBecomesTheProviderFacingResult(): void
    {
        $this->signIn(7);
        $this->permitTool('WriteTable');
        $tool = $this->tool(new CallToolResult([new TextContent('Page 42 is called "Home".')]));

        $result = $tool->execute([], $this->context(7));

        self::assertFalse($result->isError);
        self::assertSame('Page 42 is called "Home".', $result->content);
        self::assertSame([], $result->artifacts);
    }

    #[Test]
    public function anMcpErrorBecomesAnErrorResult(): void
    {
        $this->signIn(7);
        $this->permitTool('WriteTable');
        $tool = $this->tool(new CallToolResult([new TextContent('You may not edit page 42.')], true));

        $result = $tool->execute([], $this->context(7));

        self::assertTrue($result->isError);
        self::assertSame('You may not edit page 42.', $result->content);
        self::assertSame([], $result->artifacts, 'An error result never carries artifacts.');
    }

    #[Test]
    public function structuredContentBecomesARunOnlyArtifact(): void
    {
        $this->signIn(7);
        $this->permitTool('WriteTable');
        $tool = $this->tool(new CallToolResult(
            [new TextContent('two rows')],
            false,
            null,
            [
                ['uid' => 1, 'title' => 'Home'],
                ['uid' => 2, 'title' => 'About'],
            ],
        ));

        $result = $tool->execute([], $this->context(7));

        self::assertCount(1, $result->artifacts);
        $artifact = $result->artifacts[0];
        self::assertSame(ArtifactType::TABLE, $artifact->type);
        self::assertSame(['uid', 'title'], $artifact->data['columns']);
        self::assertSame([['1', 'Home'], ['2', 'About']], $artifact->data['rows']);
        self::assertSame('two rows', $result->content, 'The wire string is unchanged by the artifact.');
    }

    #[Test]
    public function structuredContentThatIsNotATableBecomesJson(): void
    {
        $this->signIn(7);
        $this->permitTool('WriteTable');
        $tool = $this->tool(new CallToolResult(
            [new TextContent('ok')],
            false,
            null,
            ['nested' => ['deep' => true]],
        ));

        $result = $tool->execute([], $this->context(7));

        self::assertCount(1, $result->artifacts);
        self::assertSame(ArtifactType::TEXT, $result->artifacts[0]->type);
        $text = $result->artifacts[0]->data['text'] ?? null;
        self::assertIsString($text);
        self::assertStringContainsString('"nested"', $text);
    }

    /**
     * The check that makes the whole bridge safe: the MCP tool is about to read
     * $GLOBALS['BE_USER'], so the run's actor must BE that user.
     */
    #[Test]
    public function executionIsRefusedWhenTheAmbientUserIsSomebodyElse(): void
    {
        $this->signIn(9);
        $this->permitTool('WriteTable');
        $tool = $this->tool(new CallToolResult([new TextContent('should never run')]));

        $result = $tool->execute([], $this->context(7));

        self::assertTrue($result->isError);
        self::assertStringContainsString('belongs to somebody else', $result->content);
    }

    #[Test]
    public function executionIsRefusedWhenThereIsNoAmbientUserAtAll(): void
    {
        unset($GLOBALS['BE_USER']);
        $this->permitTool('WriteTable');
        $tool = $this->tool(new CallToolResult([new TextContent('should never run')]));

        $result = $tool->execute([], $this->context(7));

        self::assertTrue($result->isError);
        self::assertStringContainsString('no backend user session is active', $result->content);
    }

    #[Test]
    public function executionIsRefusedForAnAnonymousActor(): void
    {
        $this->signIn(7);
        $this->permitTool('WriteTable');
        $tool = $this->tool(new CallToolResult([new TextContent('should never run')]));

        $result = $tool->execute([], new ToolExecutionContext(AiActorContext::anonymous()));

        self::assertTrue($result->isError);
        self::assertStringContainsString('no backend user', $result->content);
    }

    #[Test]
    public function anAdminOnlyToolIsRefusedForANonAdmin(): void
    {
        $this->signIn(7);
        $this->permitTool('WriteTable');
        $tool = $this->tool(new CallToolResult([new TextContent('should never run')]), requiresAdmin: true);

        $result = $tool->execute([], $this->context(7));

        self::assertTrue($result->isError);
        self::assertStringContainsString('restricted to administrators', $result->content);
    }

    #[Test]
    public function itReportsItsEffectAndItsGroup(): void
    {
        $this->permitTool('WriteTable');
        $tool = $this->tool(new CallToolResult([]), effect: ToolEffect::NON_IDEMPOTENT_WRITE);

        self::assertSame(ToolEffect::NON_IDEMPOTENT_WRITE, $tool->getEffect());
        self::assertSame(McpCatalogTool::GROUP, $tool->getGroup());
        self::assertSame('typo3_WriteTable', $tool->getSpec()->name);
        self::assertSame(
            ToolDataClass::EDITOR_CONTENT,
            $tool->getDataClass(),
            'A declared class is what keeps the catalogue off nr-llm\'s fail-closed default for an unknown group.',
        );
    }

    private function tool(
        CallToolResult $result,
        ToolEffect $effect = ToolEffect::READ_ONLY,
        bool $requiresAdmin = false,
    ): McpCatalogTool {
        return new McpCatalogTool(
            'WriteTable',
            new ToolSpec('typo3_WriteTable', 'Write a record.', ['type' => 'object', 'properties' => []]),
            $effect,
            ToolDataClass::EDITOR_CONTENT,
            $requiresAdmin,
            $effect === ToolEffect::READ_ONLY,
            $this->catalogReturning($result),
            new ToolResultConverter(),
        );
    }

    /**
     * A real McpToolCatalogService over one stub tool. The catalogue is final —
     * correctly, it is the execution path — so the seam is the tool it
     * dispatches to, which is also what production substitutes.
     */
    private function catalogReturning(CallToolResult $result): McpToolCatalogService
    {
        $tool = new class ($result) implements McpToolInterface {
            public function __construct(private readonly CallToolResult $result) {}

            public function getName(): string
            {
                return 'WriteTable';
            }

            public function getSchema(): array
            {
                return ['description' => 'Write a record.', 'inputSchema' => ['type' => 'object']];
            }

            public function execute(array $params): CallToolResult
            {
                return $this->result;
            }
        };

        return new McpToolCatalogService(new ToolRegistry([$tool]), new ToolResultNormalizer());
    }

    private function context(int $actorUid, bool $admin = false): ToolExecutionContext
    {
        $user = new BackendUserAuthentication();
        $user->user = ['uid' => $actorUid, 'admin' => $admin ? 1 : 0];

        return new ToolExecutionContext(AiActorContext::backendUser($actorUid, $admin), $user);
    }

    private function signIn(int $uid, bool $admin = false): void
    {
        $user = new BackendUserAuthentication();
        $user->user = ['uid' => $uid, 'admin' => $admin ? 1 : 0];
        $GLOBALS['BE_USER'] = $user;
    }
}
