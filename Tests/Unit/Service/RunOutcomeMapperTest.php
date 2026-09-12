<?php

declare(strict_types=1);

namespace Webconsulting\Typo3AiChat\Tests\Unit\Service;

use Netresearch\NrLlm\Domain\Enum\AgentRunOutcome;
use Netresearch\NrLlm\Service\Agent\AgentRunResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;
use Webconsulting\Typo3AiChat\Enum\ConversationStatus;
use Webconsulting\Typo3AiChat\Service\RunOutcomeMapper;

/**
 * The mapper is the only place that names an {@see AgentRunOutcome} case, and
 * that is a maintenance contract: when nr-llm adds a case, this test is where
 * it must be noticed.
 *
 * So the coverage check below is not decoration. It walks the enum and asserts
 * every case is handled, which means a new nr-llm release either keeps the
 * suite green or points at the one file that needs a decision.
 */
final class RunOutcomeMapperTest extends TestCase
{
    #[Test]
    #[DataProvider('outcomes')]
    public function everyOutcomeMapsToAStateAUserCanActuponOrRecoverFrom(
        AgentRunOutcome $outcome,
        ConversationStatus $expectedStatus,
        string $expectedLabel,
        bool $expectedFinished,
        bool $expectedSettles,
    ): void {
        $result = $this->resultFor($outcome);

        $mapped = (new RunOutcomeMapper())->map($result);

        self::assertSame($expectedStatus, $mapped->status);
        self::assertSame($expectedLabel, $mapped->outcome);
        self::assertSame($expectedFinished, $mapped->finished);
        self::assertSame($expectedSettles, $mapped->settles);
    }

    /**
     * @return iterable<string, array{0: AgentRunOutcome, 1: ConversationStatus, 2: string, 3: bool, 4: bool}>
     */
    public static function outcomes(): iterable
    {
        yield 'completed' => [
            AgentRunOutcome::COMPLETED, ConversationStatus::Idle, 'completed', true, true,
        ];
        yield 'awaiting approval' => [
            AgentRunOutcome::AWAITING_APPROVAL, ConversationStatus::AwaitingApproval, 'awaiting_approval', false, true,
        ];
        yield 'awaiting input' => [
            AgentRunOutcome::AWAITING_INPUT, ConversationStatus::AwaitingApproval, 'awaiting_input', false, true,
        ];
        yield 'guardrail blocked' => [
            AgentRunOutcome::GUARDRAIL_BLOCKED, ConversationStatus::Failed, 'guardrail_blocked', true, true,
        ];
        yield 'guardrail approval required' => [
            AgentRunOutcome::GUARDRAIL_APPROVAL_REQUIRED,
            ConversationStatus::Failed,
            'guardrail_approval_required',
            true,
            true,
        ];
        yield 'suspend failed' => [
            AgentRunOutcome::SUSPEND_FAILED, ConversationStatus::Failed, 'suspend_failed', true, true,
        ];
        yield 'cancelled' => [
            AgentRunOutcome::CANCELLED, ConversationStatus::Idle, 'cancelled', true, true,
        ];
        yield 'failed' => [
            AgentRunOutcome::FAILED, ConversationStatus::Failed, 'failed', true, true,
        ];

        // The two outcomes that belong to somebody else's executor. This
        // request must not settle the conversation, or it would overwrite the
        // state the run's real owner is maintaining.
        yield 'lease lost' => [
            AgentRunOutcome::LEASE_LOST, ConversationStatus::Processing, 'lease_lost', false, false,
        ];
        yield 'requeued' => [
            AgentRunOutcome::REQUEUED, ConversationStatus::Processing, 'requeued', false, false,
        ];
    }

    /**
     * If this fails, nr-llm has added an outcome and somebody must decide what
     * it means for a conversation — rather than discovering the default arm in
     * production.
     */
    #[Test]
    public function everyEnumCaseIsCoveredByThisTest(): void
    {
        $covered = [];
        foreach (self::outcomes() as $case) {
            $covered[] = $case[0];
        }

        $missing = array_values(array_filter(
            AgentRunOutcome::cases(),
            static fn(AgentRunOutcome $outcome): bool => !in_array($outcome, $covered, true),
        ));

        self::assertSame(
            [],
            array_map(static fn(AgentRunOutcome $o): string => $o->value, $missing),
            'nr-llm added an agent run outcome. Decide what it means for a conversation in RunOutcomeMapper.',
        );
    }

    #[Test]
    public function aFailureCarriesTheSanitizedReason(): void
    {
        $result = $this->resultFor(
            AgentRunOutcome::FAILED,
            new RuntimeException('Provider refused: Bearer sk-abcdefgh12345678 at https://api.example.com/v1'),
        );

        $mapped = (new RunOutcomeMapper())->map($result);

        self::assertStringNotContainsString('sk-abcdefgh12345678', $mapped->message);
        self::assertStringNotContainsString('api.example.com', $mapped->message);
        self::assertStringContainsString('[REDACTED]', $mapped->message);
    }

    #[Test]
    public function aFailureWithoutAnExceptionStillExplainsItself(): void
    {
        $mapped = (new RunOutcomeMapper())->map($this->resultFor(AgentRunOutcome::FAILED));

        self::assertSame('The turn failed.', $mapped->message);
    }

    private function resultFor(AgentRunOutcome $outcome, ?Throwable $error = null): AgentRunResult
    {
        return new AgentRunResult(
            outcome: $outcome,
            runUuid: 'run-1',
            steps: [],
            error: $error,
        );
    }
}
