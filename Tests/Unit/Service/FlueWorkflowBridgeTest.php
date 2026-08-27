<?php

declare(strict_types=1);

namespace Webconsulting\Typo3AiChat\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionProperty;
use RuntimeException;
use stdClass;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Webconsulting\Typo3AiChat\Configuration\ExtensionConfiguration;
use Webconsulting\Typo3AiChat\Domain\Model\Conversation;
use Webconsulting\Typo3AiChat\Domain\Repository\ConversationRepository;
use Webconsulting\Typo3AiChat\Enum\ConversationStatus;
use Webconsulting\Typo3AiChat\Service\FlueWorkflowBridge;

final class FlueWorkflowBridgeTest extends TestCase
{
    private const TRIGGER_SERVICE = 'Webconsulting\\Flue\\Service\\FlowTriggerService';
    private const RUN_STORE = 'Webconsulting\\Flue\\Service\\RunStore';

    private mixed $previousContainer;

    public static function setUpBeforeClass(): void
    {
        if (!class_exists(self::TRIGGER_SERVICE, false)) {
            class_alias(FakeFlueTriggerService::class, self::TRIGGER_SERVICE);
        }
        if (!class_exists(self::RUN_STORE, false)) {
            class_alias(FakeFlueRunStore::class, self::RUN_STORE);
        }
    }

    protected function setUp(): void
    {
        $containerProperty = new ReflectionProperty(GeneralUtility::class, 'container');
        $this->previousContainer = $containerProperty->getValue();
    }

    protected function tearDown(): void
    {
        $containerProperty = new ReflectionProperty(GeneralUtility::class, 'container');
        $containerProperty->setValue(null, $this->previousContainer);
    }

    #[Test]
    public function availabilityRequiresEnabledConfigurationAndFlowUid(): void
    {
        $repository = $this->createMock(ConversationRepository::class);

        self::assertFalse((new FlueWorkflowBridge($this->configuration(false, 42), $repository))->isAvailable());
        self::assertFalse((new FlueWorkflowBridge($this->configuration(true, 0), $repository))->isAvailable());
        self::assertTrue((new FlueWorkflowBridge($this->configuration(true, 42), $repository))->isAvailable());
    }

    #[Test]
    public function triggerRejectsUnavailableBridge(): void
    {
        $subject = new FlueWorkflowBridge(
            $this->configuration(false, 42),
            $this->createMock(ConversationRepository::class),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(1764175501);
        $subject->trigger(new Conversation(), 'Do it', 5, 0, 7);
    }

    #[Test]
    public function triggerRequiresPositivePageUid(): void
    {
        $subject = new FlueWorkflowBridge(
            $this->configuration(true, 42),
            $this->createMock(ConversationRepository::class),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(1764175502);
        $subject->trigger(new Conversation(), 'Do it', 0, 0, 7);
    }

    #[Test]
    public function triggerPersistsDurableRunAndNormalizesWorkspace(): void
    {
        $triggerService = new FakeFlueTriggerService();
        $triggerService->triggerResult = ['runUid' => '17', 'status' => 123];
        $this->setContainer([
            self::TRIGGER_SERVICE => $triggerService,
            self::RUN_STORE => new FakeFlueRunStore(),
        ]);

        $conversation = Conversation::fromRow(['uid' => 9, 'error_message' => 'old error']);
        $repository = $this->createMock(ConversationRepository::class);
        $repository->expects(self::once())->method('update')->with($conversation);
        $subject = new FlueWorkflowBridge($this->configuration(true, 42), $repository);

        self::assertSame(
            ['runUid' => 17, 'status' => 'submitted'],
            $subject->trigger($conversation, 'Do it', 55, -3, 7),
        );
        self::assertSame([42, 'pages', 55, 0, 'Do it', 7], $triggerService->triggerArguments);
        self::assertSame(17, $conversation->getFlueRunUid());
        self::assertSame(ConversationStatus::FlueRunning, $conversation->getStatus());
        self::assertSame('', $conversation->getErrorMessage());
        self::assertSame('Do it', $conversation->getDecodedMessages()[0]['content']);
    }

    #[Test]
    public function triggerReturnsStatusProvidedByFlue(): void
    {
        $triggerService = new FakeFlueTriggerService();
        $triggerService->triggerResult = ['runUid' => 18, 'status' => 'queued'];
        $this->setContainer([self::TRIGGER_SERVICE => $triggerService]);

        $subject = new FlueWorkflowBridge(
            $this->configuration(true, 42),
            $this->createStub(ConversationRepository::class),
        );

        self::assertSame(
            ['runUid' => 18, 'status' => 'queued'],
            $subject->trigger(new Conversation(), 'Do it', 55, 3, 7),
        );
        self::assertSame(3, $triggerService->triggerArguments[3]);
    }

    #[Test]
    public function triggerRejectsInvalidRunIdentifier(): void
    {
        $triggerService = new FakeFlueTriggerService();
        $triggerService->triggerResult = ['runUid' => 'not-numeric'];
        $this->setContainer([self::TRIGGER_SERVICE => $triggerService]);
        $subject = new FlueWorkflowBridge(
            $this->configuration(true, 42),
            $this->createStub(ConversationRepository::class),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(1764175503);
        $subject->trigger(new Conversation(), 'Do it', 55, 0, 7);
    }

    #[Test]
    public function synchronizeSettledRunStoresTraceAndAssistantMessage(): void
    {
        $triggerService = new FakeFlueTriggerService();
        $runStore = new FakeFlueRunStore();
        $runStore->run = (object) [
            'status' => (object) ['value' => 'settled'],
            'output' => 'Published draft',
            'errorMessage' => '',
            'targetUid' => '55',
            'workspaceUid' => '3',
            'events' => [
                new FakeFlueEvent(['message' => 'done', 0 => 'ignored']),
                new stdClass(),
                new FakeFlueEvent('invalid'),
            ],
        ];
        $this->setContainer([
            self::TRIGGER_SERVICE => $triggerService,
            self::RUN_STORE => $runStore,
        ]);

        $conversation = Conversation::fromRow([
            'uid' => 9,
            'status' => ConversationStatus::FlueRunning->value,
            'flue_run_uid' => 17,
            'execution_trace' => json_encode([['name' => 'existing']], JSON_THROW_ON_ERROR),
        ]);
        $repository = $this->createMock(ConversationRepository::class);
        $repository->expects(self::once())->method('update')->with($conversation);
        $subject = new FlueWorkflowBridge($this->configuration(true, 42), $repository);

        $result = $subject->synchronize($conversation);

        self::assertSame([17, null, 1, false], $triggerService->drainArguments);
        self::assertSame('settled', $result['status']);
        self::assertSame('Published draft', $result['output']);
        self::assertSame([['message' => 'done']], $result['events']);
        self::assertSame(ConversationStatus::Idle, $conversation->getStatus());
        self::assertSame('Published draft', $conversation->getDecodedMessages()[0]['content']);
        self::assertSame('flue.workflow', $conversation->getExecutionTrace()[1]['name']);
        self::assertSame(55, $conversation->getExecutionTrace()[1]['arguments']['targetPageUid']);
        self::assertSame(3, $conversation->getExecutionTrace()[1]['arguments']['workspaceUid']);
        self::assertFalse($conversation->getExecutionTrace()[1]['isError']);
    }

    #[Test]
    public function synchronizeFailedRunUsesSafeFallbacks(): void
    {
        $runStore = new FakeFlueRunStore();
        $runStore->run = (object) [
            'status' => (object) ['value' => 'failed'],
            'output' => '',
            'errorMessage' => '',
            'targetUid' => 'invalid',
            'workspaceUid' => null,
        ];
        $this->setContainer([
            self::TRIGGER_SERVICE => new FakeFlueTriggerService(),
            self::RUN_STORE => $runStore,
        ]);

        $conversation = Conversation::fromRow([
            'uid' => 9,
            'status' => ConversationStatus::FlueRunning->value,
            'flue_run_uid' => 17,
        ]);
        $repository = $this->createMock(ConversationRepository::class);
        $repository->expects(self::once())->method('update')->with($conversation);
        $subject = new FlueWorkflowBridge($this->configuration(true, 42), $repository);

        $result = $subject->synchronize($conversation);

        self::assertSame('failed', $result['status']);
        self::assertSame([], $result['events']);
        self::assertSame(ConversationStatus::Failed, $conversation->getStatus());
        self::assertSame('The Flue workflow failed.', $conversation->getErrorMessage());
        self::assertSame(0, $conversation->getExecutionTrace()[0]['arguments']['targetPageUid']);
        self::assertSame(0, $conversation->getExecutionTrace()[0]['arguments']['workspaceUid']);
        self::assertTrue($conversation->getExecutionTrace()[0]['isError']);
    }

    #[Test]
    public function synchronizeRunningRunDoesNotPersistConversation(): void
    {
        $runStore = new FakeFlueRunStore();
        $runStore->run = (object) [
            'status' => new stdClass(),
            'output' => 123,
            'errorMessage' => false,
            'events' => 'invalid',
        ];
        $this->setContainer([
            self::TRIGGER_SERVICE => new FakeFlueTriggerService(),
            self::RUN_STORE => $runStore,
        ]);

        $conversation = Conversation::fromRow([
            'status' => ConversationStatus::FlueRunning->value,
            'flue_run_uid' => 17,
        ]);
        $repository = $this->createMock(ConversationRepository::class);
        $repository->expects(self::never())->method('update');
        $subject = new FlueWorkflowBridge($this->configuration(true, 42), $repository);

        self::assertSame(
            ['status' => 'running', 'output' => '', 'error' => '', 'events' => []],
            $subject->synchronize($conversation),
        );
        self::assertSame(ConversationStatus::FlueRunning, $conversation->getStatus());
    }

    #[Test]
    public function synchronizeRejectsConversationWithoutRun(): void
    {
        $subject = new FlueWorkflowBridge(
            $this->configuration(true, 42),
            $this->createStub(ConversationRepository::class),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(1764175504);
        $subject->synchronize(new Conversation());
    }

    #[Test]
    public function synchronizeRejectsMissingRun(): void
    {
        $runStore = new FakeFlueRunStore();
        $runStore->run = null;
        $this->setContainer([
            self::TRIGGER_SERVICE => new FakeFlueTriggerService(),
            self::RUN_STORE => $runStore,
        ]);
        $subject = new FlueWorkflowBridge(
            $this->configuration(true, 42),
            $this->createStub(ConversationRepository::class),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(1764175505);
        $subject->synchronize(Conversation::fromRow(['flue_run_uid' => 17]));
    }

    #[Test]
    public function triggerRejectsMissingOptionalService(): void
    {
        $this->setContainer([]);
        $subject = new FlueWorkflowBridge(
            $this->configuration(true, 42),
            $this->createStub(ConversationRepository::class),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(1764175506);
        $subject->trigger(new Conversation(), 'Do it', 55, 0, 7);
    }

    #[Test]
    public function triggerRejectsInvalidOptionalService(): void
    {
        $this->setContainer([self::TRIGGER_SERVICE => 'invalid']);
        $subject = new FlueWorkflowBridge(
            $this->configuration(true, 42),
            $this->createStub(ConversationRepository::class),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(1764175507);
        $subject->trigger(new Conversation(), 'Do it', 55, 0, 7);
    }

    #[Test]
    public function triggerRejectsMissingOptionalMethod(): void
    {
        $this->setContainer([self::TRIGGER_SERVICE => new stdClass()]);
        $subject = new FlueWorkflowBridge(
            $this->configuration(true, 42),
            $this->createStub(ConversationRepository::class),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(1764175508);
        $subject->trigger(new Conversation(), 'Do it', 55, 0, 7);
    }

    private function configuration(bool $enabled, int $flowUid): ExtensionConfiguration
    {
        $configuration = $this->createStub(ExtensionConfiguration::class);
        $configuration->method('isFlueEnabled')->willReturn($enabled);
        $configuration->method('getFlueFlowUid')->willReturn($flowUid);
        return $configuration;
    }

    /** @param array<string, mixed> $services */
    private function setContainer(array $services): void
    {
        $container = $this->createStub(ContainerInterface::class);
        $container->method('has')->willReturnCallback(
            static fn(string $id): bool => array_key_exists($id, $services),
        );
        $container->method('get')->willReturnCallback(
            static fn(string $id): mixed => $services[$id] ?? null,
        );
        GeneralUtility::setContainer($container);
    }
}

final class FakeFlueTriggerService
{
    public mixed $triggerResult = null;

    /** @var list<mixed> */
    public array $triggerArguments = [];

    /** @var list<mixed> */
    public array $drainArguments = [];

    public function trigger(mixed ...$arguments): mixed
    {
        $this->triggerArguments = $arguments;
        return $this->triggerResult;
    }

    public function drainRun(mixed ...$arguments): void
    {
        $this->drainArguments = $arguments;
    }
}

final class FakeFlueRunStore
{
    public mixed $run = null;

    public function load(int $runUid): mixed
    {
        return $this->run;
    }
}

final readonly class FakeFlueEvent
{
    public function __construct(private mixed $data) {}

    public function toArray(): mixed
    {
        return $this->data;
    }
}
