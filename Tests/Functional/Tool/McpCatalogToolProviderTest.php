<?php

declare(strict_types=1);

namespace Webconsulting\Typo3AiChat\Tests\Functional\Tool;

use Netresearch\NrLlm\Domain\Enum\ToolEffect;
use Netresearch\NrLlm\Service\Tool\ToolEffectInterface;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use PHPUnit\Framework\Attributes\Test;
use Webconsulting\Typo3AiChat\Tests\Functional\AbstractChatFunctionalTestCase;
use Webconsulting\Typo3AiChat\Tool\McpCatalogTool;
use Webconsulting\Typo3AiChat\Tool\McpCatalogToolProvider;

/**
 * The projection, against the REAL MCP catalogue of a real installation.
 *
 * A unit test can prove the classifier's rules; only this can prove they
 * produce the right answer for the tools that actually ship — and those are the
 * ones an operator will see in the Tools module and decide about.
 */
final class McpCatalogToolProviderTest extends AbstractChatFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Several MCP tools build their schema from what the CURRENT user may
        // reach — WriteTable enumerates their editable tables — and refuse
        // outright without one. The projection is therefore only meaningful
        // inside an authenticated request, which is where it always runs.
        $this->signIn();
    }

    #[Test]
    public function everyMcpToolIsOfferedUnderAPrefixedName(): void
    {
        $names = array_keys($this->projectedTools());

        self::assertContains('typo3_GetPage', $names);
        self::assertContains('typo3_WriteTable', $names);
        self::assertContains('typo3_GetPageTree', $names);

        foreach ($names as $name) {
            self::assertStringStartsWith(
                McpCatalogTool::NAME_PREFIX,
                $name,
                'An unprefixed name could collide with an nr-llm builtin.',
            );
        }
    }

    #[Test]
    public function aReadingToolIsReadOnlyAndOfferedByDefault(): void
    {
        $tool = $this->projectedTools()['typo3_GetPage'] ?? null;

        self::assertInstanceOf(ToolEffectInterface::class, $tool);
        self::assertSame(ToolEffect::READ_ONLY, $tool->getEffect());
        self::assertInstanceOf(ToolInterface::class, $tool);
        self::assertTrue($tool->isEnabledByDefault());
        self::assertFalse($tool->requiresAdmin(), 'Reading a page is an editor action.');
    }

    #[Test]
    public function aWritingToolIsAWriteAndStaysDarkUntilAnAdminEnablesIt(): void
    {
        $tool = $this->projectedTools()['typo3_WriteTable'] ?? null;

        self::assertInstanceOf(ToolEffectInterface::class, $tool);
        self::assertTrue($tool->getEffect()->isWrite());
        self::assertInstanceOf(ToolInterface::class, $tool);
        self::assertFalse(
            $tool->isEnabledByDefault(),
            'A write must be switched on deliberately, not inherited from an install.',
        );
    }

    #[Test]
    public function aToolThatReachesTheHostIsAdminOnly(): void
    {
        $tool = $this->projectedTools()['typo3_SafeCli'] ?? null;

        self::assertInstanceOf(ToolInterface::class, $tool);
        self::assertTrue(
            $tool->requiresAdmin(),
            'SafeCli runs commands against the installation; an editor must never be offered it.',
        );
        self::assertInstanceOf(ToolEffectInterface::class, $tool);
        self::assertTrue($tool->getEffect()->isWrite());
    }

    #[Test]
    public function everyProjectedToolJoinsOneGroupSoTheCatalogueCanBeToggledAtOnce(): void
    {
        foreach ($this->projectedTools() as $tool) {
            self::assertSame(McpCatalogTool::GROUP, $tool->getGroup());
        }
    }

    #[Test]
    public function aProjectedToolCarriesTheMcpDescriptionAndSchema(): void
    {
        $spec = ($this->projectedTools()['typo3_GetPage'] ?? null)?->getSpec();

        self::assertNotNull($spec);
        self::assertNotSame('', $spec->description, 'The model needs to know what the tool is for.');
        self::assertSame('object', $spec->parameters['type'] ?? null);
    }

    /**
     * nr-llm's own registry must see the projection, or the model never will.
     */
    #[Test]
    public function nrLlmsRegistryPicksTheProjectionUpThroughTheDiTag(): void
    {
        $registry = $this->get(ToolRegistry::class);
        self::assertInstanceOf(ToolRegistry::class, $registry);

        self::assertContains('typo3_GetPage', $registry->names());
        self::assertNotContains(
            'typo3_GetPage',
            $registry->builtinNames(),
            'It must arrive as a PROVIDED tool, not be mistaken for one of nr-llm\'s own.',
        );
    }

    /**
     * @return array<string, McpCatalogTool>
     */
    private function projectedTools(): array
    {
        $provider = $this->get(McpCatalogToolProvider::class);
        self::assertInstanceOf(McpCatalogToolProvider::class, $provider);

        $tools = [];
        foreach ($provider->tools() as $tool) {
            $tools[$tool->getSpec()->name] = $tool;
        }

        return $tools;
    }
}
