<?php

declare(strict_types=1);

namespace Webconsulting\Typo3AiChat\Tests\Unit\Tool;

use Hn\McpServer\MCP\Tool\Attribute\AdminOnly;
use Hn\McpServer\Service\CapabilityManifestService;
use Netresearch\NrLlm\Domain\Enum\ToolEffect;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Site\SiteFinder;
use Webconsulting\Typo3AiChat\Tool\ToolEffectClassifier;

/**
 * The classifier decides whether a tool can change the installation, and the
 * runtime hangs an audit guarantee and a retry policy off that answer. So the
 * interesting cases here are not the obvious ones — they are the two places the
 * fail-safe direction differs: an EMPTY declaration means "touches nothing",
 * while an UNKNOWN subsystem means "assume it writes".
 */
final class ToolEffectClassifierTest extends TestCase
{
    /**
     * @param array<string, mixed> $annotations
     */
    #[Test]
    #[DataProvider('annotationCases')]
    public function annotationsDecideTheEffect(array $annotations, ToolEffect $expected): void
    {
        $classifier = new ToolEffectClassifier();

        self::assertSame($expected, $classifier->classify('AnyTool', ['annotations' => $annotations]));
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: ToolEffect}>
     */
    public static function annotationCases(): iterable
    {
        yield 'readOnlyHint wins outright' => [
            ['readOnlyHint' => true, 'destructiveHint' => true],
            ToolEffect::READ_ONLY,
        ];
        yield 'destructive is never repeatable' => [
            ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => true],
            ToolEffect::NON_IDEMPOTENT_WRITE,
        ];
        yield 'explicitly non-idempotent' => [
            ['readOnlyHint' => false, 'idempotentHint' => false],
            ToolEffect::NON_IDEMPOTENT_WRITE,
        ];
        yield 'idempotent write' => [
            ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true],
            ToolEffect::IDEMPOTENT_WRITE,
        ];
        yield 'writes, idempotency unstated' => [
            ['readOnlyHint' => false],
            ToolEffect::NON_IDEMPOTENT_WRITE,
        ];
    }

    /**
     * @param list<string> $subsystems
     */
    #[Test]
    #[DataProvider('subsystemCases')]
    public function subsystemsDecideWhenThereAreNoAnnotations(array $subsystems, ToolEffect $expected): void
    {
        $classifier = new ToolEffectClassifier($this->manifestFor(['AnyTool' => $subsystems]));

        self::assertSame($expected, $classifier->classify('AnyTool', []));
    }

    /**
     * @return iterable<string, array{0: list<string>, 1: ToolEffect}>
     */
    public static function subsystemCases(): iterable
    {
        yield 'reads only' => [['database:read', 'file:read'], ToolEffect::READ_ONLY];
        yield 'a :write suffix is a write' => [['database:write'], ToolEffect::NON_IDEMPOTENT_WRITE];
        yield 'site:write is a write' => [['site:write'], ToolEffect::NON_IDEMPOTENT_WRITE];
        yield 'cli:safe is a write despite its name' => [['cli:safe'], ToolEffect::NON_IDEMPOTENT_WRITE];
        yield 'cache:write is a write' => [['cache:write'], ToolEffect::NON_IDEMPOTENT_WRITE];
        yield 'extension:install is a write' => [['extension:install'], ToolEffect::NON_IDEMPOTENT_WRITE];
        yield 'scheduler:task is a write' => [['scheduler:task'], ToolEffect::NON_IDEMPOTENT_WRITE];
        yield 'x402:payments is a write' => [['x402:payments'], ToolEffect::NON_IDEMPOTENT_WRITE];
        yield 'any network:* reaches outside' => [['network:package-manager'], ToolEffect::NON_IDEMPOTENT_WRITE];
        yield 'one write among reads still writes' => [
            ['database:read', 'file:read', 'database:write'],
            ToolEffect::NON_IDEMPOTENT_WRITE,
        ];
    }

    /**
     * The two fail-safe directions, side by side — this is the pair the class
     * exists to get right.
     */
    #[Test]
    public function anEmptyDeclarationIsReadOnlyButAnUnknownSubsystemIsAWrite(): void
    {
        $classifier = new ToolEffectClassifier($this->manifestFor([
            'GetCapabilities' => [],
            'FutureTool' => ['quantum:entangle'],
        ]));

        self::assertSame(
            ToolEffect::READ_ONLY,
            $classifier->classify('GetCapabilities', []),
            'A tool that declares no subsystems really does touch nothing.',
        );
        self::assertSame(
            ToolEffect::NON_IDEMPOTENT_WRITE,
            $classifier->classify('FutureTool', []),
            'A subsystem this version has never heard of must be assumed to change something.',
        );
    }

    #[Test]
    public function aToolWithNoManifestEntryAtAllIsReadOnly(): void
    {
        $classifier = new ToolEffectClassifier($this->manifestFor([]));

        self::assertSame(ToolEffect::READ_ONLY, $classifier->classify('Unlisted', []));
    }

    #[Test]
    public function annotationsBeatTheManifest(): void
    {
        $classifier = new ToolEffectClassifier($this->manifestFor(['Odd' => ['database:write']]));

        self::assertSame(
            ToolEffect::READ_ONLY,
            $classifier->classify('Odd', ['annotations' => ['readOnlyHint' => true]]),
            'What the tool author wrote down about their own tool wins.',
        );
    }

    #[Test]
    public function onlyReadOnlyToolsAreOfferedByDefault(): void
    {
        $classifier = new ToolEffectClassifier();

        self::assertTrue($classifier->isEnabledByDefault(ToolEffect::READ_ONLY));
        self::assertFalse($classifier->isEnabledByDefault(ToolEffect::IDEMPOTENT_WRITE));
        self::assertFalse($classifier->isEnabledByDefault(ToolEffect::NON_IDEMPOTENT_WRITE));
    }

    #[Test]
    public function theAdminOnlyAttributeMakesAToolAdminOnly(): void
    {
        $classifier = new ToolEffectClassifier($this->manifestFor(['Whatever' => ['database:read']]));

        self::assertTrue($classifier->requiresAdmin('Whatever', [], new AdminOnlyFixtureTool()));
        self::assertFalse($classifier->requiresAdmin('Whatever', [], new OrdinaryFixtureTool()));
    }

    #[Test]
    #[DataProvider('adminSubsystemCases')]
    public function installationWideSubsystemsMakeAToolAdminOnly(string $subsystem, bool $expected): void
    {
        $classifier = new ToolEffectClassifier($this->manifestFor(['Tool' => [$subsystem]]));

        self::assertSame($expected, $classifier->requiresAdmin('Tool', []));
    }

    /**
     * @return iterable<string, array{0: string, 1: bool}>
     */
    public static function adminSubsystemCases(): iterable
    {
        yield 'cli:safe' => ['cli:safe', true];
        yield 'extension:install' => ['extension:install', true];
        yield 'project:write' => ['project:write', true];
        yield 'site:write' => ['site:write', true];
        yield 'scheduler:task' => ['scheduler:task', true];
        yield 'x402:payments' => ['x402:payments', true];
        yield 'database:write is an editor capability, not an admin one' => ['database:write', false];
        yield 'database:read' => ['database:read', false];
    }

    /**
     * A REAL manifest service over a temporary manifest file.
     *
     * CapabilityManifestService is final, which is the right call for a
     * security boundary — an operator's capability policy should not be
     * substitutable at runtime. Its constructor takes a manifest path override
     * for exactly this situation, so the test exercises the real parser and the
     * real YAML shape instead of a double that would happily agree with a
     * mistaken assumption about either.
     *
     * @param array<string, list<string>> $tools
     */
    private function manifestFor(array $tools): CapabilityManifestService
    {
        $path = tempnam(sys_get_temp_dir(), 'wcaichat-manifest-') . '.yaml';
        $this->manifestFiles[] = $path;
        file_put_contents($path, Yaml::dump([
            'capabilities' => [
                'version' => '1.0',
                'extension' => 'mcp_server',
                'x-mcp' => ['tools' => $tools],
            ],
        ], 6));

        return new CapabilityManifestService(
            $this->createStub(ExtensionConfiguration::class),
            $this->createStub(SiteFinder::class),
            null,
            $path,
        );
    }

    /** @var list<string> */
    private array $manifestFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->manifestFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        $this->manifestFiles = [];

        parent::tearDown();
    }
}

#[AdminOnly]
final class AdminOnlyFixtureTool {}

final class OrdinaryFixtureTool {}
