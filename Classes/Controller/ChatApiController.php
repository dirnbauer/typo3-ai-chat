<?php

declare(strict_types=1);

namespace Webconsulting\Typo3AiChat\Controller;

use Closure;
use finfo;
use Netresearch\NrLlm\Domain\ValueObject\AgentRunEvent;
use Netresearch\NrLlm\Service\Agent\AgentRuntimeInterface;
use Netresearch\NrLlm\Service\BudgetServiceInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use RuntimeException;
use Throwable;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Resource\Exception\FileDoesNotExistException;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use Webconsulting\Typo3AiChat\Configuration\ExtensionConfiguration;
use Webconsulting\Typo3AiChat\Document\DocumentExtractorRegistry;
use Webconsulting\Typo3AiChat\Domain\Model\Conversation;
use Webconsulting\Typo3AiChat\Domain\Model\Message;
use Webconsulting\Typo3AiChat\Domain\Repository\ConversationRepository;
use Webconsulting\Typo3AiChat\Domain\Repository\MessageRepository;
use Webconsulting\Typo3AiChat\Enum\ConversationStatus;
use Webconsulting\Typo3AiChat\Exception\ChatException;
use Webconsulting\Typo3AiChat\Http\ServerSentEventStream;
use Webconsulting\Typo3AiChat\Http\TurnEventSink;
use Webconsulting\Typo3AiChat\Service\ApprovalService;
use Webconsulting\Typo3AiChat\Service\AttachmentStorage;
use Webconsulting\Typo3AiChat\Service\BackendUserContext;
use Webconsulting\Typo3AiChat\Service\ChatTurnService;
use Webconsulting\Typo3AiChat\Service\ToolAccessService;
use Webconsulting\Typo3AiChat\Service\ToolEffectLookup;
use Webconsulting\Typo3AiChat\Service\TurnRateLimiter;
use Webconsulting\Typo3AiChat\Tool\McpCatalogTool;
use Webconsulting\Typo3AiChat\Utility\ErrorMessageSanitizer;

/**
 * The chat API.
 *
 * Deliberately thin: it authorises, parses, hands off, and shapes a response.
 * Every decision that matters — which tools, which identity, what an outcome
 * means, what gets persisted — belongs to a service, because those decisions
 * must come out the same whichever transport asked.
 */
final readonly class ChatApiController
{
    private const MAX_UPLOAD_BYTES = 20 * 1024 * 1024;

    public function __construct(
        private ConversationRepository $conversations,
        private MessageRepository $messages,
        private ChatTurnService $turnService,
        private ApprovalService $approvalService,
        private ToolAccessService $toolAccess,
        private ToolEffectLookup $effectLookup,
        private TurnRateLimiter $rateLimiter,
        private BackendUserContext $backendUser,
        private ExtensionConfiguration $config,
        private AttachmentStorage $attachmentStorage,
        private DocumentExtractorRegistry $documentExtractors,
        private ResourceFactory $resourceFactory,
        private AgentRuntimeInterface $agentRuntime,
        private BudgetServiceInterface $budgetService,
    ) {}

    // ------------------------------------------------------------------ reads

    /**
     * Everything a client needs before it renders anything: whether the chat
     * works at all, what it runs on, which tools it may reach, and what it is
     * allowed to spend.
     */
    public function status(): ResponseInterface
    {
        $denied = $this->denyUnauthorised();
        if ($denied !== null) {
            return $denied;
        }

        $configuration = $this->turnService->configuration();
        $available = $configuration !== null;

        $issues = [];
        if (!$available) {
            $issues[] = 'No nr-llm task is configured. An administrator must create an nr-llm task with an LLM '
                . 'configuration and set its UID as "llmTaskUid" in the extension configuration.';
        }

        $allowedTools = $this->toolAccess->allowedToolNames();
        if ($available && $allowedTools === []) {
            $issues[] = 'No tools are enabled for you. The chat can answer questions but cannot inspect or change '
                . 'this installation.';
        }

        $beUserUid = $this->backendUser->uid();
        $budget = $this->budgetService->check($beUserUid, 0.0, $configuration);

        return new JsonResponse([
            'available' => $available,
            'issues' => $issues,
            'configuration' => $available && $configuration !== null ? [
                'identifier' => $configuration->getIdentifier(),
                'name' => $configuration->getName(),
                'provider' => $configuration->getProviderType(),
                'model' => $configuration->getModelId(),
            ] : null,
            'tools' => $this->describeTools($allowedTools),
            'budget' => [
                'allowed' => $budget->allowed,
                'reason' => $budget->reason,
            ],
            'limits' => [
                'maxMessageLength' => $this->config->getMaxMessageLength(),
                'maxIterations' => $this->config->getMaxIterations(),
                'turnsPerMinute' => $this->rateLimiter->limit(),
                'turnsRemaining' => $this->rateLimiter->remaining($beUserUid),
                'maxConversations' => $this->config->getMaxConversationsPerUser(),
                'maxActiveConversations' => $this->config->getMaxActiveConversationsPerUser(),
                'activeConversations' => $this->conversations->countActiveByBeUser($beUserUid),
            ],
            'suggestions' => $this->suggestions($allowedTools),
            'features' => [
                'sse' => true,
                'approvals' => true,
                'attachments' => true,
            ],
        ]);
    }

    public function listConversations(ServerRequestInterface $request): ResponseInterface
    {
        $denied = $this->denyUnauthorised();
        if ($denied !== null) {
            return $denied;
        }

        $includeArchived = ($request->getQueryParams()['archived'] ?? '') === '1';
        $conversations = $this->conversations->findByBeUser($this->backendUser->uid(), $includeArchived);

        return new JsonResponse([
            'conversations' => array_map(static fn(Conversation $c): array => $c->toArray(), $conversations),
        ]);
    }

    public function getConversation(ServerRequestInterface $request): ResponseInterface
    {
        $conversation = $this->resolveConversation($request);
        if ($conversation instanceof ResponseInterface) {
            return $conversation;
        }

        $after = $this->intParam($request->getQueryParams()['after'] ?? null);
        $messages = $this->messages->findByConversation($conversation->getUid(), $after);

        return new JsonResponse([
            'conversation' => $conversation->toArray(),
            'messages' => array_map(static fn(Message $m): array => $m->toArray(), $messages),
        ]);
    }

    /**
     * The run's execution trace, straight from nr-llm.
     *
     * This extension persists the transcript, not the trace — nr-llm already
     * holds every step against the run uuid, and a second copy would be a
     * second thing to purge and to keep honest. The trace is therefore read
     * back through the runtime under the caller's own actor, which is also what
     * stops a guessed run uuid from opening somebody else's run.
     */
    public function runEvents(ServerRequestInterface $request): ResponseInterface
    {
        $conversation = $this->resolveConversation($request);
        if ($conversation instanceof ResponseInterface) {
            return $conversation;
        }

        $params = $request->getQueryParams();
        $runUuid = is_string($params['runUuid'] ?? null) && $params['runUuid'] !== ''
            ? $params['runUuid']
            : $conversation->getRunUuid();

        if ($runUuid === '') {
            return new JsonResponse(['runUuid' => '', 'events' => []]);
        }

        $after = $this->intParam($params['after'] ?? null, -1);
        $events = $this->agentRuntime->events($this->backendUser->actor(), $runUuid, $after);

        return new JsonResponse([
            'runUuid' => $runUuid,
            'events' => array_map(static fn(AgentRunEvent $event): array => [
                'sequence' => $event->sequence,
                'kind' => $event->kind,
                'round' => $event->round,
                'durationMs' => $event->durationMs,
                'payload' => $event->payload,
                'createdAt' => $event->crdate,
            ], $events),
        ]);
    }

    public function fileInfo(ServerRequestInterface $request): ResponseInterface
    {
        $denied = $this->denyUnauthorised();
        if ($denied !== null) {
            return $denied;
        }

        $fileUid = $this->intParam($request->getQueryParams()['fileUid'] ?? null);
        if ($fileUid <= 0) {
            return $this->error('A file uid is required.', 400);
        }

        try {
            $file = $this->resourceFactory->getFileObject($fileUid);
        } catch (FileDoesNotExistException) {
            return $this->error('File not found.', 404);
        }

        if (!$file->checkActionPermission('read')) {
            return $this->error('You may not read this file.', 403);
        }

        return new JsonResponse($this->attachmentStorage->describe($file));
    }

    // ----------------------------------------------------------------- writes

    public function createConversation(ServerRequestInterface $request): ResponseInterface
    {
        $denied = $this->denyUnauthorised();
        if ($denied !== null) {
            return $denied;
        }

        $beUserUid = $this->backendUser->uid();
        $max = $this->config->getMaxConversationsPerUser();
        if ($max > 0 && count($this->conversations->findByBeUser($beUserUid, true)) >= $max) {
            return $this->error(
                sprintf('You have reached the limit of %d conversations. Archive or delete one first.', $max),
                429,
            );
        }

        $body = $this->parseBody($request);
        $conversation = new Conversation();
        $conversation->setBeUser($beUserUid);
        $conversation->setTitle($this->stringValue($body, 'title'));
        $conversation->setSystemPrompt($this->stringValue($body, 'systemPrompt'));

        $uid = $this->conversations->add($conversation);
        $created = $this->conversations->findOneByUidAndBeUser($uid, $beUserUid);

        return new JsonResponse(['conversation' => $created?->toArray() ?? ['uid' => $uid]], 201);
    }

    /**
     * Start one turn.
     *
     * Content negotiation rather than two routes: the client asks for
     * `text/event-stream` when it wants the turn as it happens, and gets the
     * same events as one JSON document when it does not.
     */
    public function turn(ServerRequestInterface $request): ResponseInterface
    {
        $conversation = $this->resolveConversation($request);
        if ($conversation instanceof ResponseInterface) {
            return $conversation;
        }

        $body = $this->parseBody($request);
        $content = trim($this->stringValue($body, 'content'));
        if ($content === '') {
            return $this->error('A message cannot be empty.', 400);
        }

        $maxLength = $this->config->getMaxMessageLength();
        if ($maxLength > 0 && mb_strlen($content) > $maxLength) {
            return $this->error(sprintf('A message may be at most %d characters long.', $maxLength), 400);
        }

        $beUserUid = $this->backendUser->uid();
        if (!$this->rateLimiter->consume($beUserUid)) {
            return $this->error(
                sprintf('You have started too many turns. The limit is %d per minute.', $this->rateLimiter->limit()),
                429,
            );
        }

        $maxActive = $this->config->getMaxActiveConversationsPerUser();
        if ($maxActive > 0 && $this->conversations->countActiveByBeUser($beUserUid) >= $maxActive) {
            return $this->error(
                sprintf('You already have %d conversations running. Finish or cancel one first.', $maxActive),
                429,
            );
        }

        // The claim IS the per-conversation lock. Two tabs pressing send at the
        // same moment must not both run against the same transcript; the loser
        // is told so rather than silently interleaving with the winner.
        $runToken = $this->newRunToken();
        $claimed = $this->conversations->claimForTurn(
            $conversation->getUid(),
            $beUserUid,
            [ConversationStatus::Idle, ConversationStatus::Failed],
            $runToken,
        );
        if (!$claimed) {
            return $this->error('This conversation is busy. Wait for the current turn to finish.', 409);
        }

        $conversation->setRunUuid($runToken);
        $conversation->setStatus(ConversationStatus::Processing);

        $attachments = $this->attachments($body);
        $context = $this->contextOf($body);

        return $this->respondToTurn(
            $request,
            fn(TurnEventSink $sink): array => $this->turnService
                ->run($conversation, $content, $attachments, $context, $sink->emitter())
                ->toArray(),
            fn(): bool => $this->approvalService->cancel($conversation),
        );
    }

    public function approval(ServerRequestInterface $request): ResponseInterface
    {
        $conversation = $this->resolveConversation($request);
        if ($conversation instanceof ResponseInterface) {
            return $conversation;
        }

        $body = $this->parseBody($request);
        $approved = ($body['approved'] ?? null) === true;
        $turnDigest = $this->stringValue($body, 'turnDigest');

        return $this->respondToTurn(
            $request,
            fn(TurnEventSink $sink): array => $this->approvalService
                ->decide($conversation, $approved, $turnDigest, $sink->emitter())
                ->toArray(),
            fn(): bool => $this->approvalService->cancel($conversation),
        );
    }

    public function cancel(ServerRequestInterface $request): ResponseInterface
    {
        $conversation = $this->resolveConversation($request);
        if ($conversation instanceof ResponseInterface) {
            return $conversation;
        }

        $cancelled = $this->approvalService->cancel($conversation);

        return new JsonResponse(['cancelled' => $cancelled, 'status' => ConversationStatus::Idle->value]);
    }

    public function archive(ServerRequestInterface $request): ResponseInterface
    {
        $conversation = $this->resolveConversation($request);
        if ($conversation instanceof ResponseInterface) {
            return $conversation;
        }

        $body = $this->parseBody($request);
        $archived = ($body['archived'] ?? true) !== false;
        $this->conversations->updateArchived($conversation->getUid(), $archived, $conversation->getBeUser());

        return new JsonResponse(['archived' => $archived]);
    }

    public function pin(ServerRequestInterface $request): ResponseInterface
    {
        $conversation = $this->resolveConversation($request);
        if ($conversation instanceof ResponseInterface) {
            return $conversation;
        }

        $body = $this->parseBody($request);
        $pinned = array_key_exists('pinned', $body) ? $body['pinned'] === true : !$conversation->isPinned();
        $this->conversations->updatePinned($conversation->getUid(), $pinned, $conversation->getBeUser());

        return new JsonResponse(['pinned' => $pinned]);
    }

    public function rename(ServerRequestInterface $request): ResponseInterface
    {
        $conversation = $this->resolveConversation($request);
        if ($conversation instanceof ResponseInterface) {
            return $conversation;
        }

        $body = $this->parseBody($request);
        $title = trim($this->stringValue($body, 'title'));
        if ($title === '') {
            return $this->error('A title cannot be empty.', 400);
        }

        $this->conversations->updateTitle($conversation->getUid(), $title, $conversation->getBeUser());

        if (array_key_exists('autoApproveTools', $body)) {
            $this->conversations->updateAutoApproveTools(
                $conversation->getUid(),
                $body['autoApproveTools'] === true,
                $conversation->getBeUser(),
            );
        }

        return new JsonResponse(['title' => mb_substr($title, 0, 255)]);
    }

    /**
     * Soft-delete: the row survives for the cleanup command, which removes it
     * with its messages and its uploaded files once retention has passed.
     */
    public function delete(ServerRequestInterface $request): ResponseInterface
    {
        $conversation = $this->resolveConversation($request);
        if ($conversation instanceof ResponseInterface) {
            return $conversation;
        }

        $this->conversations->softDelete($conversation->getUid(), $conversation->getBeUser());

        return new JsonResponse(['deleted' => true]);
    }

    public function fileUpload(ServerRequestInterface $request): ResponseInterface
    {
        $conversation = $this->resolveConversation($request);
        if ($conversation instanceof ResponseInterface) {
            return $conversation;
        }

        $uploaded = $request->getUploadedFiles()['file'] ?? null;
        if (!$uploaded instanceof UploadedFileInterface || $uploaded->getError() !== UPLOAD_ERR_OK) {
            return $this->error('No file was uploaded.', 400);
        }

        $size = $uploaded->getSize();
        if ($size !== null && $size > self::MAX_UPLOAD_BYTES) {
            return $this->error('The file is larger than 20 MB.', 400);
        }

        $uri = $uploaded->getStream()->getMetadata('uri');
        $tempPath = is_string($uri) ? $uri : '';
        if ($tempPath === '' || !is_file($tempPath)) {
            return $this->error('The upload could not be read.', 400);
        }

        // The client's Content-Type is a claim, not a fact: the type is detected
        // from the bytes, because it decides which parser runs next.
        $detected = (new finfo(FILEINFO_MIME_TYPE))->file($tempPath);
        if (!is_string($detected) || !$this->documentExtractors->canExtract($detected)) {
            return $this->error('This file type is not supported.', 422);
        }

        try {
            $this->documentExtractors->validate($tempPath, $detected);
        } catch (RuntimeException $exception) {
            return $this->error('The file could not be read: ' . $exception->getMessage(), 422);
        }

        try {
            $folder = $this->attachmentStorage->folderFor($conversation->getBeUser(), $conversation->getUid());
            $file = $folder->getStorage()->addFile(
                $tempPath,
                $folder,
                $uploaded->getClientFilename() ?? 'upload',
            );
        } catch (Throwable $exception) {
            return $this->error(
                'The file could not be stored: ' . ErrorMessageSanitizer::sanitize($exception->getMessage()),
                500,
            );
        }

        return new JsonResponse($this->attachmentStorage->describe($file), 201);
    }

    // ---------------------------------------------------------------- helpers

    /**
     * One producer, two transports.
     *
     * @param Closure(TurnEventSink): array<string, mixed> $producer
     * @param Closure(): bool                              $onAbort
     */
    private function respondToTurn(
        ServerRequestInterface $request,
        Closure $producer,
        Closure $onAbort,
    ): ResponseInterface {
        if ($this->wantsEventStream($request)) {
            $stream = new ServerSentEventStream(
                producer: static function (TurnEventSink $sink) use ($producer): void {
                    try {
                        $producer($sink);
                    } catch (ChatException $exception) {
                        $sink->emit('run.error', ['message' => $exception->getMessage()]);
                    } catch (Throwable $exception) {
                        $sink->emit('run.error', [
                            'message' => ErrorMessageSanitizer::sanitize($exception->getMessage()),
                        ]);
                    }
                },
                onAbort: static function () use ($onAbort): void {
                    $onAbort();
                },
            );

            return new Response($stream, 200, ServerSentEventStream::headers());
        }

        $sink = new TurnEventSink();
        try {
            $result = $producer($sink);
        } catch (ChatException $exception) {
            return $this->error($exception->getMessage(), 409);
        } catch (Throwable $exception) {
            return $this->error(ErrorMessageSanitizer::sanitize($exception->getMessage()), 500);
        }

        return new JsonResponse($result + ['events' => $sink->collected()]);
    }

    private function wantsEventStream(ServerRequestInterface $request): bool
    {
        return str_contains(strtolower($request->getHeaderLine('Accept')), 'text/event-stream');
    }

    private function resolveConversation(ServerRequestInterface $request): Conversation|ResponseInterface
    {
        $denied = $this->denyUnauthorised();
        if ($denied !== null) {
            return $denied;
        }

        $body = $this->parseBody($request);
        $uid = $this->intParam($request->getQueryParams()['conversation'] ?? $body['conversation'] ?? null);

        $conversation = $this->conversations->findOneByUidAndBeUser($uid, $this->backendUser->uid());

        return $conversation ?? $this->error('Conversation not found.', 404);
    }

    private function denyUnauthorised(): ?ResponseInterface
    {
        if ($this->backendUser->mayUseChat($this->config->getAllowedGroupIds())) {
            return null;
        }

        return $this->error('You may not use TYPO3 AI Chat.', 403);
    }

    /**
     * @param list<string> $toolNames
     *
     * @return list<array{name: string, mcpName: string|null, effect: string, requiresApproval: bool}>
     */
    private function describeTools(array $toolNames): array
    {
        $tools = [];
        foreach ($this->effectLookup->describe($toolNames) as $name => $facts) {
            $tools[] = [
                'name' => $name,
                'mcpName' => McpCatalogTool::mcpName($name),
                'effect' => $facts['effect'],
                'requiresApproval' => $facts['requiresApproval'],
            ];
        }

        return $tools;
    }

    /**
     * Openers the chat can honestly offer, given what this user may actually
     * reach. Suggesting an action whose tool is disabled would teach the user
     * to distrust every suggestion after it.
     *
     * @param list<string> $allowedTools
     *
     * @return list<string>
     */
    private function suggestions(array $allowedTools): array
    {
        $mcpNames = [];
        foreach ($allowedTools as $name) {
            $mcpName = McpCatalogTool::mcpName($name);
            if ($mcpName !== null) {
                $mcpNames[] = $mcpName;
            }
        }

        $candidates = [
            'GetPageTree' => 'Show me the page tree below the site root.',
            'Search' => 'Find every page that mentions our old product name.',
            'GetPage' => 'Summarise the content elements on this page.',
            'ReadTable' => 'List the ten most recently changed news records.',
            'GetSystemLog' => 'What errors has this installation logged today?',
        ];

        $suggestions = [];
        foreach ($candidates as $tool => $prompt) {
            if (in_array($tool, $mcpNames, true)) {
                $suggestions[] = $prompt;
            }
        }

        return $suggestions;
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return list<array<string, mixed>>
     */
    private function attachments(array $body): array
    {
        $raw = $body['attachments'] ?? null;
        if (!is_array($raw)) {
            return [];
        }

        $attachments = [];
        foreach ($raw as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $fileUid = is_numeric($entry['fileUid'] ?? null) ? (int)$entry['fileUid'] : 0;
            if ($fileUid <= 0) {
                continue;
            }

            try {
                $file = $this->resourceFactory->getFileObject($fileUid);
            } catch (Throwable) {
                continue;
            }

            // A uid in a request body is a claim about a file, not permission to
            // read it — so the permission is checked here rather than trusted.
            if (!$file->checkActionPermission('read')) {
                continue;
            }

            $attachments[] = $this->attachmentStorage->describe($file);
        }

        return $attachments;
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function contextOf(array $body): array
    {
        $context = $body['context'] ?? null;
        if (!is_array($context)) {
            return [];
        }

        $normalised = [];
        foreach ($context as $key => $value) {
            if (is_string($key)) {
                $normalised[$key] = $value;
            }
        }

        return $normalised;
    }

    /**
     * @return array<string, mixed>
     */
    private function parseBody(ServerRequestInterface $request): array
    {
        $parsed = $request->getParsedBody();
        if (is_array($parsed) && $parsed !== []) {
            return $this->stringKeyed($parsed);
        }

        $decoded = json_decode((string)$request->getBody(), true);

        return is_array($decoded) ? $this->stringKeyed($decoded) : [];
    }

    /**
     * @param array<array-key, mixed> $values
     *
     * @return array<string, mixed>
     */
    private function stringKeyed(array $values): array
    {
        $result = [];
        foreach ($values as $key => $value) {
            if (is_string($key)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function stringValue(array $body, string $key): string
    {
        $value = $body[$key] ?? '';

        return is_string($value) ? $value : '';
    }

    private function intParam(mixed $value, int $default = 0): int
    {
        return is_numeric($value) ? (int)$value : $default;
    }

    private function error(string $message, int $status): JsonResponse
    {
        return new JsonResponse(['error' => $message], $status);
    }

    /**
     * A per-turn token that claims the conversation before the runtime has
     * handed us a run uuid. The runtime's own uuid replaces it as soon as the
     * run is persisted; until then this is what makes the claim unique.
     */
    private function newRunToken(): string
    {
        return 'pending-' . bin2hex(random_bytes(16));
    }
}
