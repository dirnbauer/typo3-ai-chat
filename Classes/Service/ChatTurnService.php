<?php

declare(strict_types=1);

namespace Webconsulting\Typo3AiChat\Service;

use Closure;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Task;
use Netresearch\NrLlm\Domain\Repository\TaskRepository;
use Netresearch\NrLlm\Domain\ValueObject\RunStep;
use Netresearch\NrLlm\Service\Agent\AgentRunRequest;
use Netresearch\NrLlm\Service\Agent\AgentRunResult;
use Netresearch\NrLlm\Service\Agent\AgentRuntimeInterface;
use Netresearch\NrLlm\Service\Option\ToolOptions;
use Throwable;
use Webconsulting\Typo3AiChat\Configuration\ExtensionConfiguration;
use Webconsulting\Typo3AiChat\Domain\Model\Conversation;
use Webconsulting\Typo3AiChat\Domain\Model\Message;
use Webconsulting\Typo3AiChat\Domain\Repository\ConversationRepository;
use Webconsulting\Typo3AiChat\Domain\Repository\MessageRepository;
use Webconsulting\Typo3AiChat\Enum\ConversationStatus;
use Webconsulting\Typo3AiChat\Enum\MessageRole;
use Webconsulting\Typo3AiChat\Exception\ChatException;
use Webconsulting\Typo3AiChat\Utility\ErrorMessageSanitizer;

/**
 * Runs one chat turn.
 *
 * SYNCHRONOUS, always — never `AgentRuntime::enqueue()`. The MCP tools read the
 * ambient `$GLOBALS['BE_USER']` and {@see \Webconsulting\Typo3AiChat\Tool\McpCatalogTool}
 * refuses to run when that user is not the run's actor. A queue worker has no
 * ambient user, so every tool call in a queued turn would fail closed. Running
 * inside the request that asked for it is what makes the tools usable at all.
 *
 * What this class owns is the seam between a conversation and an agent run:
 * build the request, drive the runtime, turn the recorded steps back into
 * message rows, and settle the conversation. It owns neither the loop (nr-llm
 * does) nor the transport (the controller does).
 */
final readonly class ChatTurnService
{
    public function __construct(
        private AgentRuntimeInterface $agentRuntime,
        private TaskRepository $taskRepository,
        private ExtensionConfiguration $config,
        private TranscriptBuilder $transcriptBuilder,
        private ToolAccessService $toolAccess,
        private ContextSnapshotService $contextSnapshot,
        private RunOutcomeMapper $outcomeMapper,
        private TurnPersister $persister,
        private BackendUserContext $backendUser,
        private ConversationRepository $conversationRepository,
        private MessageRepository $messageRepository,
        private ToolEffectLookup $effectLookup,
    ) {}

    /**
     * Whether the extension is configured well enough to run anything.
     */
    public function isConfigured(): bool
    {
        return $this->resolveTask() !== null;
    }

    public function configuration(): ?LlmConfiguration
    {
        return $this->resolveTask()?->getConfiguration();
    }

    /**
     * Append the user's message and run one turn.
     *
     * @param list<array<string, mixed>>       $attachments
     * @param array<string, mixed>             $clientContext {module, pageUid, workspaceId}
     * @param (Closure(string, array<string, mixed>): void)|null $emit receives one turn event at a time
     *
     * @throws ChatException when the extension is not configured
     */
    public function run(
        Conversation $conversation,
        string $userText,
        array $attachments,
        array $clientContext,
        ?Closure $emit = null,
    ): TurnResult {
        $emit = self::emitter($emit);
        $task = $this->resolveTask();
        $configuration = $task?->getConfiguration();
        if ($task === null || !$configuration instanceof LlmConfiguration) {
            throw new ChatException(
                'TYPO3 AI Chat is not configured: extension configuration "llmTaskUid" must point at an nr-llm task that has an LLM configuration.',
                1794000101,
            );
        }

        $userMessage = $this->persister->appendUserMessage($conversation, $userText, $attachments);
        $emit('run.started', [
            'runUuid' => $conversation->getRunUuid(),
            'userMessageUid' => $userMessage->uid,
        ]);

        $request = $this->buildRequest($conversation, $task, $configuration, $clientContext);

        $recorder = new TurnStepRecorder($this->effectLookup);
        $onStep = static function (RunStep $step) use ($recorder, $emit): void {
            $recorder->record($step);
            foreach ($recorder->drainEvents() as [$name, $payload]) {
                $emit($name, $payload);
            }
        };

        try {
            $result = $this->agentRuntime->run($request, $onStep);
        } catch (Throwable $exception) {
            // AgentRuntime::run() does not throw for a run OUTCOME, so anything
            // arriving here is infrastructure — a gone configuration, a dead
            // database. The conversation must not stay claimed because of it.
            return $this->failHard($conversation, $exception, $emit);
        }

        // A run driven without a live emitter still has steps to replay, and a
        // run whose emitter was attached late may have missed the first ones.
        $recorder->recordAll($result->steps);

        return $this->settle($conversation, $result, $recorder, $emit);
    }

    /**
     * Settle a finished or suspended run: persist what it produced and move the
     * conversation into the state the outcome demands.
     *
     * @param (Closure(string, array<string, mixed>): void)|null $emit
     */
    public function settle(
        Conversation $conversation,
        AgentRunResult $result,
        TurnStepRecorder $recorder,
        ?Closure $emit = null,
    ): TurnResult {
        $emit = self::emitter($emit);
        $runUuid = $result->runUuid !== '' ? $result->runUuid : $conversation->getRunUuid();
        $outcome = $this->outcomeMapper->map($result);

        $persisted = $this->persister->persistSteps($conversation, $recorder->steps(), $runUuid);
        foreach ($persisted as $message) {
            if ($message->role === MessageRole::Assistant && $message->toolCalls === []) {
                $emit('message.final', ['messageUid' => $message->uid, 'content' => $message->content]);
            }
        }

        $pendingApproval = [];
        if ($outcome->isAwaitingApproval() && $result->suspendedState !== null) {
            $pendingApproval = $this->persister->describeSuspension($runUuid, $result->suspendedState);
            $emit('approval.required', $pendingApproval);
        }

        if ($outcome->settles) {
            $this->conversationRepository->settle(
                $conversation->getUid(),
                $conversation->getBeUser(),
                $outcome->status,
                $outcome->isFailure() ? $outcome->message : '',
                $pendingApproval,
                $outcome->finished ? '' : $runUuid,
            );
            $this->persister->refreshCounters($conversation);
        }

        $usage = $this->persister->usage($result);
        $emit('run.finished', ['outcome' => $outcome->outcome, 'usage' => $usage]);

        return new TurnResult(
            runUuid: $runUuid,
            outcome: $outcome,
            messages: $persisted,
            pendingApproval: $pendingApproval,
            usage: $usage,
        );
    }

    /**
     * @param array<string, mixed> $clientContext
     */
    private function buildRequest(
        Conversation $conversation,
        Task $task,
        LlmConfiguration $configuration,
        array $clientContext,
    ): AgentRunRequest {
        $messages = $this->transcriptBuilder->build(
            $this->messageRepository->findTail($conversation->getUid(), TranscriptBuilder::DEFAULT_WINDOW),
            $task->getPromptTemplate(),
            $conversation->getSystemPrompt(),
            $this->contextSnapshot->fromArray($clientContext),
        );

        $beUserUid = $this->backendUser->uid();
        $options = (new ToolOptions(beUserUid: $beUserUid))
            ->withCallerSource('webconsulting_ai_chat', 'turn');

        return new AgentRunRequest(
            configuration: $configuration,
            messages: $messages,
            actor: $this->backendUser->actor(),
            allowedToolNames: $this->toolAccess->allowedToolNames(),
            options: $options,
            maxIterations: $this->config->getMaxIterations(),
        );
    }

    /**
     * A caller that does not want the events still has to be callable, so the
     * nullable parameter becomes a no-op here rather than an `if` at every
     * single emission site.
     *
     * @param (Closure(string, array<string, mixed>): void)|null $emit
     *
     * @return Closure(string, array<string, mixed>): void
     */
    private static function emitter(?Closure $emit): Closure
    {
        return $emit ?? static function (string $name, array $payload): void {};
    }

    /**
     * @param Closure(string, array<string, mixed>): void $emit
     */
    private function failHard(Conversation $conversation, Throwable $exception, Closure $emit): TurnResult
    {
        $message = ErrorMessageSanitizer::sanitize($exception->getMessage());

        $this->conversationRepository->settle(
            $conversation->getUid(),
            $conversation->getBeUser(),
            ConversationStatus::Failed,
            $message,
        );
        $this->persister->refreshCounters($conversation);

        $emit('run.error', ['message' => $message]);

        return new TurnResult(
            runUuid: $conversation->getRunUuid(),
            outcome: new TurnOutcome(ConversationStatus::Failed, true, 'failed', $message),
            messages: [],
            pendingApproval: [],
            usage: ['promptTokens' => 0, 'completionTokens' => 0, 'totalTokens' => 0],
        );
    }

    private function resolveTask(): ?Task
    {
        $uid = $this->config->getLlmTaskUid();
        if ($uid <= 0) {
            return null;
        }

        try {
            $task = $this->taskRepository->findByUid($uid);
        } catch (Throwable) {
            return null;
        }

        return $task instanceof Task ? $task : null;
    }

    /**
     * @return list<Message>
     */
    public function transcript(int $conversationUid, int $afterSequence = 0): array
    {
        return $this->messageRepository->findByConversation($conversationUid, $afterSequence);
    }
}
