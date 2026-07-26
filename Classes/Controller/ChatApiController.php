<?php

declare(strict_types=1);

namespace Webconsulting\Typo3AiChat\Controller;

use DateTimeImmutable;
use DateTimeInterface;
use Exception;
use finfo;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Throwable;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Resource\ResourceStorage;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Webconsulting\Typo3AiChat\Configuration\ExtensionConfiguration;
use Webconsulting\Typo3AiChat\Document\DocumentExtractorRegistry;
use Webconsulting\Typo3AiChat\Domain\Model\Conversation;
use Webconsulting\Typo3AiChat\Domain\Repository\ConversationRepository;
use Webconsulting\Typo3AiChat\Enum\ConversationStatus;
use Webconsulting\Typo3AiChat\Enum\MessageRole;
use Webconsulting\Typo3AiChat\Service\ChatCapabilitiesInterface;
use Webconsulting\Typo3AiChat\Service\ChatProcessorInterface;
use Webconsulting\Typo3AiChat\Service\FlueWorkflowBridge;

final readonly class ChatApiController
{
    private const ERROR_FILE_NOT_FOUND = 'File not found';
    private const ERROR_CONVERSATION_PROCESSING = 'Conversation is already processing';

    public function __construct(
        private ConversationRepository $repository,
        private ChatProcessorInterface $processor,
        private ExtensionConfiguration $config,
        private ChatCapabilitiesInterface $chatService,
        private ResourceFactory $resourceFactory,
        private StorageRepository $storageRepository,
        private DocumentExtractorRegistry $documentExtractorRegistry,
        private ?FlueWorkflowBridge $flueWorkflowBridge = null,
    ) {}

    /**
     * GET /ai-chat/status – Check if AI chat is available for current user.
     */
    public function getStatus(): ResponseInterface
    {
        $accessDenied = $this->checkAccess();
        if ($accessDenied !== null) {
            return $accessDenied;
        }
        $taskUid = $this->config->getLlmTaskUid();
        $mcpEnabled = $this->config->isMcpEnabled();
        $issues = [];
        if ($taskUid === 0) {
            $issues[] = 'No nr-llm Task configured. An admin must create an nr-llm Task record and set its UID in Extension Configuration.';
        }
        if ($this->config->hasLegacyMcpFields()) {
            $issues[] = 'Legacy MCP fields (mcpServerCommand/mcpServerArgs) are still set in Extension Configuration. These fields are no longer used. MCP servers are now configured in the List module on PID 0.';
        }
        $capabilities = $this->chatService->getProviderCapabilities();
        return new JsonResponse([
            'available' => $taskUid > 0,
            'mcpEnabled' => $mcpEnabled,
            'flueAvailable' => $this->flueWorkflowBridge?->isAvailable() ?? false,
            'flueFlowUid' => $this->config->getFlueFlowUid(),
            'activeConversationCount' => $this->repository->countActiveByBeUser($this->getBeUserUid()),
            'issues' => $issues,
            ...$capabilities,
        ]);
    }

    /**
     * GET /ai-chat/conversations – List conversations for current user.
     */
    public function listConversations(): ResponseInterface
    {
        $accessDenied = $this->checkAccess();
        if ($accessDenied !== null) {
            return $accessDenied;
        }
        $conversations = $this->repository->findByBeUser($this->getBeUserUid());
        $items = array_map(static fn(Conversation $c): array => [
            'uid' => $c->getUid(),
            'title' => $c->getTitle(),
            'status' => $c->getStatus()->value,
            'messageCount' => $c->getMessageCount(),
            'pinned' => $c->isPinned(),
            'resumable' => $c->isResumable(),
            'errorMessage' => $c->getErrorMessage(),
            'runUuid' => $c->getRunUuid(),
            'pendingApproval' => $c->getPendingApproval(),
            'flueRunUid' => $c->getFlueRunUid(),
            'tstamp' => $c->getTstamp(),
        ], $conversations);
        return new JsonResponse(['conversations' => $items]);
    }

    /**
     * POST /ai-chat/conversations/create – Create new conversation.
     */
    public function createConversation(): ResponseInterface
    {
        $accessDenied = $this->checkAccess();
        if ($accessDenied !== null) {
            return $accessDenied;
        }
        $conversation = new Conversation();
        $conversation->setBeUser($this->getBeUserUid());
        $uid = $this->repository->add($conversation);
        return new JsonResponse([
            'uid' => $uid,
        ], 201);
    }

    /**
     * GET /ai-chat/conversations/messages?conversationUid={uid}&after={index}
     */
    public function getMessages(ServerRequestInterface $request): ResponseInterface
    {
        $accessDenied = $this->checkAccess();
        if ($accessDenied !== null) {
            return $accessDenied;
        }

        /** @var array<string, string> $queryParams */
        $queryParams = $request->getQueryParams();
        $uid = (int) ($queryParams['conversationUid'] ?? 0);
        $afterIndex = (int) ($queryParams['after'] ?? 0);

        // Fast path for polling: check metadata first without loading messages blob
        if ($afterIndex > 0) {
            $meta = $this->repository->findPollStatus($uid, $this->getBeUserUid());
            if ($meta === null) {
                return new JsonResponse(['error' => 'Conversation not found'], 404);
            }
            if ($meta['message_count'] <= $afterIndex) {
                return new JsonResponse([
                    'status' => $meta['status'],
                    'messages' => [],
                    'totalCount' => $meta['message_count'],
                    'errorMessage' => $meta['error_message'],
                    'runUuid' => $meta['run_uuid'] ?? '',
                    'pendingApproval' => $this->decodeJsonList($meta['pending_approval'] ?? ''),
                    'executionTrace' => $this->decodeJsonList($meta['execution_trace'] ?? ''),
                    'flueRunUid' => $meta['flue_run_uid'] ?? 0,
                ]);
            }
        }

        $conversation = $this->findConversationOrFail($request);
        if ($conversation instanceof ResponseInterface) {
            return $conversation;
        }

        $messages = $conversation->getDecodedMessages();
        $newMessages = array_slice($messages, $afterIndex);

        return new JsonResponse([
            'status' => $conversation->getStatus()->value,
            'messages' => $newMessages,
            'totalCount' => count($messages),
            'errorMessage' => $conversation->getErrorMessage(),
            'runUuid' => $conversation->getRunUuid(),
            'pendingApproval' => $conversation->getPendingApproval(),
            'executionTrace' => $conversation->getExecutionTrace(),
            'flueRunUid' => $conversation->getFlueRunUid(),
        ]);
    }

    /**
     * POST /ai-chat/conversations/send
     */
    public function sendMessage(ServerRequestInterface $request): ResponseInterface
    {
        $accessDenied = $this->checkAccess();
        if ($accessDenied !== null) {
            return $accessDenied;
        }

        $body = $this->parseBody($request);
        $conversation = $this->findConversationOrFail($request, $body);
        if ($conversation instanceof ResponseInterface) {
            return $conversation;
        }

        $contentValue = $body['content'] ?? null;
        $content = is_string($contentValue) ? trim($contentValue) : '';

        if ($content === '') {
            return new JsonResponse(['error' => 'Empty message'], 400);
        }

        $maxLength = $this->config->getMaxMessageLength();
        if ($maxLength > 0 && mb_strlen($content) > $maxLength) {
            return new JsonResponse(['error' => sprintf('Message too long (max %d characters)', $maxLength)], 400);
        }

        $requestedFileUids = [];
        if (isset($body['fileUids']) && is_array($body['fileUids'])) {
            foreach ($body['fileUids'] as $requestedFileUid) {
                $fileUid = $this->positiveInteger($requestedFileUid);
                if ($fileUid > 0) {
                    $requestedFileUids[] = $fileUid;
                }
            }
            $requestedFileUids = array_values(array_unique($requestedFileUids));
        } elseif (isset($body['fileUid'])) {
            $requestedFileUids = [$this->positiveInteger($body['fileUid'])];
        }
        $requestedFileUids = array_values(array_filter($requestedFileUids, static fn(int $uid): bool => $uid > 0));

        if ($this->countFilesInConversation($conversation) + count($requestedFileUids) > 5) {
            return new JsonResponse(['error' => 'Maximum 5 files per conversation reached'], 400);
        }

        $attachments = [];
        foreach ($requestedFileUids as $fileUid) {
            try {
                $file = $this->resourceFactory->getFileObject($fileUid);
                if (!$file->checkActionPermission('read')) {
                    return new JsonResponse(['error' => self::ERROR_FILE_NOT_FOUND], 404);
                }
                $attachments[] = [
                    'fileUid' => $fileUid,
                    'fileName' => $file->getName(),
                    'fileMimeType' => $file->getMimeType(),
                ];
            } catch (Exception) {
                return new JsonResponse(['error' => self::ERROR_FILE_NOT_FOUND], 404);
            }
        }

        $currentStatus = $conversation->getStatus();
        if (in_array($currentStatus, [
            ConversationStatus::Processing,
            ConversationStatus::Locked,
            ConversationStatus::ToolLoop,
            ConversationStatus::AwaitingApproval,
            ConversationStatus::FlueRunning,
        ], true)
        ) {
            return new JsonResponse(['error' => self::ERROR_CONVERSATION_PROCESSING], 409);
        }

        $maxActive = $this->config->getMaxActiveConversationsPerUser();
        if ($maxActive > 0) {
            $activeCount = $this->repository->countActiveByBeUser($this->getBeUserUid());
            if ($activeCount >= $maxActive) {
                return new JsonResponse(['error' => sprintf('Too many active conversations (max %d)', $maxActive)], 429);
            }
        }

        if ($attachments !== []) {
            $messages = $conversation->getDecodedMessages();
            $message = [
                'role' => MessageRole::User->value,
                'content' => $content,
                'attachments' => $attachments,
                'createdAt' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
            ];
            if (count($attachments) === 1) {
                $message['fileUid'] = $attachments[0]['fileUid'];
                $message['fileName'] = $attachments[0]['fileName'];
                $message['fileMimeType'] = $attachments[0]['fileMimeType'];
            }
            $messages[] = $message;
            $conversation->setMessages($messages);
            if ($conversation->getTitle() === '') {
                $conversation->setTitle($content);
            }
        } else {
            $conversation->appendMessage(MessageRole::User, $content);
        }

        $conversation->setStatus(ConversationStatus::Processing);
        $conversation->setErrorMessage('');

        // Atomic CAS: write full row only if status still matches,
        // preventing race conditions with concurrent requests or worker dequeue.
        $claimed = $this->repository->updateIf($conversation, $currentStatus);
        if (!$claimed) {
            return new JsonResponse(['error' => self::ERROR_CONVERSATION_PROCESSING], 409);
        }

        $this->processor->dispatch($conversation->getUid());

        return new JsonResponse(['status' => 'processing'], 202);
    }

    /**
     * POST /ai-chat/file-upload – Upload a file to FAL for use as chat attachment.
     */
    public function fileUpload(ServerRequestInterface $request): ResponseInterface
    {
        $accessDenied = $this->checkAccess();
        if ($accessDenied !== null) {
            return $accessDenied;
        }

        /** @var array<string, \Psr\Http\Message\UploadedFileInterface> $uploadedFiles */
        $uploadedFiles = $request->getUploadedFiles();
        $file = $uploadedFiles['file'] ?? null;

        if ($file === null || $file->getError() !== UPLOAD_ERR_OK) {
            return new JsonResponse(['error' => 'No file uploaded'], 400);
        }

        $capabilities = $this->chatService->getProviderCapabilities();
        // $capabilities['supportedFormats'] contains file extensions (e.g. 'png', 'jpg') because
        // the frontend uses them for the <input accept> filter.  finfo returns MIME types, so we
        // map extensions to MIME types before comparing.
        $extensionMimeMap = [
            'png'  => 'image/png',
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif'  => 'image/gif',
            'webp' => 'image/webp',
            'pdf'  => 'application/pdf',
        ];
        $providerMimeTypes = array_values(array_filter(array_map(
            static fn(string $ext): ?string => $extensionMimeMap[$ext] ?? null,
            $capabilities['supportedFormats'],
        )));
        $allowedMimeTypes = array_values(array_unique(array_merge(
            $providerMimeTypes,
            $this->documentExtractorRegistry->getAvailableMimeTypes(),
        )));

        $maxSize = 20 * 1024 * 1024; // 20 MB
        if ($file->getSize() > $maxSize) {
            return new JsonResponse(['error' => 'File too large (max 20 MB)'], 400);
        }

        // Validate MIME type server-side via finfo — client-supplied Content-Type is untrusted
        $uri = $file->getStream()->getMetadata('uri');
        $tempPath = is_string($uri) ? $uri : '';
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $detectedMime = $finfo->file($tempPath);
        if (!is_string($detectedMime) || !in_array($detectedMime, $allowedMimeTypes, true)) {
            return new JsonResponse(['error' => 'File type not supported'], 422);
        }

        // For extraction-backed formats, run lightweight validation at upload time
        if ($this->documentExtractorRegistry->canExtract($detectedMime)) {
            try {
                $this->documentExtractorRegistry->validate($tempPath, $detectedMime);
            } catch (RuntimeException $e) {
                return new JsonResponse(['error' => 'File could not be processed: ' . $e->getMessage()], 422);
            }
        }

        $storage = $this->storageRepository->getDefaultStorage();
        if ($storage === null) {
            return new JsonResponse(['error' => 'No default storage configured'], 500);
        }

        $beUserUid = $this->getBeUserUid();
        $targetFolder = $this->getOrCreateUploadFolder($storage, $beUserUid);

        $clientFilename = $file->getClientFilename() ?? 'upload';
        $falFile = $storage->addFile(
            $tempPath,
            $targetFolder,
            $clientFilename,
        );

        return new JsonResponse([
            'fileUid' => $falFile->getUid(),
            'name' => $falFile->getName(),
            'mimeType' => $falFile->getMimeType(),
            'size' => $falFile->getSize(),
        ]);
    }

    /**
     * GET /ai-chat/file-info?fileUid={uid} – Resolve FAL file metadata by UID.
     */
    public function fileInfo(ServerRequestInterface $request): ResponseInterface
    {
        $accessDenied = $this->checkAccess();
        if ($accessDenied !== null) {
            return $accessDenied;
        }

        /** @var array<string, string> $params */
        $params = $request->getQueryParams();
        $rawUid = $params['fileUid'] ?? '';

        if ($rawUid === '' || !ctype_digit((string) $rawUid) || (int) $rawUid <= 0) {
            return new JsonResponse(['error' => 'Invalid fileUid'], 400);
        }

        try {
            $file = $this->resourceFactory->getFileObject((int) $rawUid);
        } catch (Exception) {
            return new JsonResponse(['error' => self::ERROR_FILE_NOT_FOUND], 404);
        }

        if (!$file->checkActionPermission('read')) {
            return new JsonResponse(['error' => 'Access denied'], 403);
        }

        $supported = array_values(array_unique(array_merge(
            $this->chatService->getProviderCapabilities()['supportedFormats'],
            $this->documentExtractorRegistry->getAvailableExtensions(),
        )));
        if (!in_array(strtolower($file->getExtension()), $supported, true)) {
            return new JsonResponse(['error' => 'Unsupported file type'], 422);
        }

        return new JsonResponse([
            'fileUid'  => $file->getUid(),
            'name'     => $file->getName(),
            'mimeType' => $file->getMimeType(),
            'size'     => $file->getSize(),
        ]);
    }

    /**
     * POST /ai-chat/conversations/resume
     */
    public function resumeConversation(ServerRequestInterface $request): ResponseInterface
    {
        $accessDenied = $this->checkAccess();
        if ($accessDenied !== null) {
            return $accessDenied;
        }

        $conversation = $this->findConversationOrFail($request);
        if ($conversation instanceof ResponseInterface) {
            return $conversation;
        }

        if (!$conversation->isResumable()) {
            return new JsonResponse(['error' => 'Conversation is not resumable'], 400);
        }

        $currentStatus = $conversation->getStatus();

        $conversation->setStatus(ConversationStatus::Processing);
        $conversation->setErrorMessage('');

        // Atomic CAS: write full row only if status still matches.
        $claimed = $this->repository->updateIf($conversation, $currentStatus);
        if (!$claimed) {
            return new JsonResponse(['error' => self::ERROR_CONVERSATION_PROCESSING], 409);
        }

        $this->processor->dispatch($conversation->getUid());

        return new JsonResponse(['status' => 'processing'], 202);
    }

    /**
     * POST /ai-chat/conversations/approval
     */
    public function decideApproval(ServerRequestInterface $request): ResponseInterface
    {
        $accessDenied = $this->checkAccess();
        if ($accessDenied !== null) {
            return $accessDenied;
        }

        $body = $this->parseBody($request);
        $conversation = $this->findConversationOrFail($request, $body);
        if ($conversation instanceof ResponseInterface) {
            return $conversation;
        }
        if ($conversation->getStatus() !== ConversationStatus::AwaitingApproval) {
            return new JsonResponse(['error' => 'Conversation is not awaiting approval'], 409);
        }

        try {
            $this->chatService->decideApproval(
                $conversation,
                ($body['approved'] ?? false) === true,
                $this->getBeUserUid(),
            );
        } catch (Throwable $exception) {
            return new JsonResponse(['error' => $exception->getMessage()], 409);
        }

        return new JsonResponse(['status' => $conversation->getStatus()->value]);
    }

    /**
     * POST /ai-chat/flue/trigger
     */
    public function triggerFlue(ServerRequestInterface $request): ResponseInterface
    {
        $accessDenied = $this->checkAccess();
        if ($accessDenied !== null) {
            return $accessDenied;
        }

        $body = $this->parseBody($request);
        $conversation = $this->findConversationOrFail($request, $body);
        if ($conversation instanceof ResponseInterface) {
            return $conversation;
        }

        $contentValue = $body['content'] ?? null;
        $content = is_string($contentValue) ? trim($contentValue) : '';
        $pageUid = $this->positiveInteger($body['pageUid'] ?? null);
        if ($content === '') {
            return new JsonResponse(['error' => 'Empty workflow request'], 400);
        }
        if ($conversation->getStatus() !== ConversationStatus::Idle
            && $conversation->getStatus() !== ConversationStatus::Failed
        ) {
            return new JsonResponse(['error' => self::ERROR_CONVERSATION_PROCESSING], 409);
        }
        if ($this->flueWorkflowBridge === null) {
            return new JsonResponse(['error' => 'Flue is not available.'], 409);
        }

        try {
            $result = $this->flueWorkflowBridge->trigger(
                $conversation,
                $content,
                $pageUid,
                (int) ($this->getBackendUser()['workspace_id'] ?? 0),
                $this->getBeUserUid(),
            );
        } catch (Throwable $exception) {
            return new JsonResponse(['error' => $exception->getMessage()], 409);
        }

        return new JsonResponse($result, 202);
    }

    /**
     * GET /ai-chat/flue/status?conversationUid={uid}
     */
    public function flueStatus(ServerRequestInterface $request): ResponseInterface
    {
        $accessDenied = $this->checkAccess();
        if ($accessDenied !== null) {
            return $accessDenied;
        }

        $conversation = $this->findConversationOrFail($request);
        if ($conversation instanceof ResponseInterface) {
            return $conversation;
        }
        if ($this->flueWorkflowBridge === null) {
            return new JsonResponse(['error' => 'Flue is not available.'], 409);
        }

        try {
            $flue = $this->flueWorkflowBridge->synchronize($conversation);
        } catch (Throwable $exception) {
            return new JsonResponse(['error' => $exception->getMessage()], 409);
        }

        return new JsonResponse([
            'flue' => $flue,
            'status' => $conversation->getStatus()->value,
            'messages' => $conversation->getDecodedMessages(),
            'totalCount' => $conversation->getMessageCount(),
            'errorMessage' => $conversation->getErrorMessage(),
            'executionTrace' => $conversation->getExecutionTrace(),
            'pendingApproval' => $conversation->getPendingApproval(),
            'flueRunUid' => $conversation->getFlueRunUid(),
        ]);
    }

    /**
     * POST /ai-chat/conversations/archive
     */
    public function archiveConversation(ServerRequestInterface $request): ResponseInterface
    {
        $accessDenied = $this->checkAccess();
        if ($accessDenied !== null) {
            return $accessDenied;
        }

        $conversation = $this->findConversationOrFail($request);
        if ($conversation instanceof ResponseInterface) {
            return $conversation;
        }

        $this->repository->updateArchived($conversation->getUid(), true, $this->getBeUserUid());

        return new JsonResponse(['status' => 'archived']);
    }

    /**
     * POST /ai-chat/conversations/pin
     */
    public function togglePin(ServerRequestInterface $request): ResponseInterface
    {
        $accessDenied = $this->checkAccess();
        if ($accessDenied !== null) {
            return $accessDenied;
        }

        $conversation = $this->findConversationOrFail($request);
        if ($conversation instanceof ResponseInterface) {
            return $conversation;
        }

        $newPinned = !$conversation->isPinned();
        $this->repository->updatePinned($conversation->getUid(), $newPinned, $this->getBeUserUid());

        return new JsonResponse(['pinned' => $newPinned]);
    }

    /**
     * POST /ai-chat/conversations/rename
     */
    public function renameConversation(ServerRequestInterface $request): ResponseInterface
    {
        $accessDenied = $this->checkAccess();
        if ($accessDenied !== null) {
            return $accessDenied;
        }

        // Parse body once — PSR-7 streams are one-shot; passing $body to
        // findConversationOrFail avoids reading the stream a second time.
        $body = $this->parseBody($request);
        $conversation = $this->findConversationOrFail($request, $body);
        if ($conversation instanceof ResponseInterface) {
            return $conversation;
        }

        $titleValue = $body['title'] ?? null;
        $title = is_string($titleValue) ? trim($titleValue) : '';
        if ($title === '') {
            return new JsonResponse(['error' => 'Title must not be empty'], 400);
        }

        $this->repository->updateTitle($conversation->getUid(), $title, $this->getBeUserUid());

        return new JsonResponse(['title' => $title]);
    }

    /**
     * @param array<string, mixed>|null $parsedBody
     */
    private function findConversationOrFail(ServerRequestInterface $request, ?array $parsedBody = null): Conversation|ResponseInterface
    {
        $body = $parsedBody ?? $this->parseBody($request);
        /** @var array<string, string> $queryParams */
        $queryParams = $request->getQueryParams();
        $uid = $this->positiveInteger($queryParams['conversationUid'] ?? $body['conversationUid'] ?? null);

        $conversation = $this->repository->findOneByUidAndBeUser($uid, $this->getBeUserUid());

        if ($conversation === null) {
            return new JsonResponse(['error' => 'Conversation not found'], 404);
        }

        return $conversation;
    }

    private function checkAccess(): ?ResponseInterface
    {
        $allowedGroups = $this->config->getAllowedGroupIds();
        if ($allowedGroups === []) {
            return null;
        }

        $beUser = $this->getBackendUser();

        if (((int) ($beUser['admin'] ?? 0)) === 1) {
            return null;
        }

        $userGroups = GeneralUtility::intExplode(
            ',',
            (string) ($beUser['usergroup'] ?? ''),
            true,
        );

        if (array_intersect($allowedGroups, $userGroups) !== []) {
            return null;
        }

        return new JsonResponse(['error' => 'Access denied'], 403);
    }

    /**
     * @return array<string, mixed>
     */
    private function parseBody(ServerRequestInterface $request): array
    {
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $request->getBody(), true) ?? [];
        return $body;
    }

    private function getBeUserUid(): int
    {
        return (int) ($this->getBackendUser()['uid'] ?? 0);
    }

    private function countFilesInConversation(Conversation $conversation): int
    {
        $messages = $conversation->getDecodedMessages();
        $count = 0;
        foreach ($messages as $message) {
            if (isset($message['attachments']) && is_array($message['attachments'])) {
                $count += count($message['attachments']);
            } elseif (isset($message['fileUid'])) {
                ++$count;
            }
        }

        return $count;
    }

    private function getOrCreateUploadFolder(ResourceStorage $storage, int $beUserUid): Folder
    {
        $basePath = 'typo3-ai-chat/' . $beUserUid;
        if (!$storage->hasFolder($basePath)) {
            return $storage->createFolder($basePath);
        }
        return $storage->getFolder($basePath);
    }

    /**
     * @return array<string, string|int>
     */
    private function getBackendUser(): array
    {
        // BE_USER is always set for authenticated backend requests; no DI alternative exists.
        /** @var object{user: array<string, string|int>} $beUser */
        $beUser = $GLOBALS['BE_USER'];
        return $beUser->user;
    }

    /** @return list<array<string, mixed>> */
    private function decodeJsonList(string $json): array
    {
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }

        $result = [];
        foreach ($decoded as $item) {
            if (!is_array($item)) {
                continue;
            }
            $normalized = [];
            foreach ($item as $key => $value) {
                if (is_string($key)) {
                    $normalized[$key] = $value;
                }
            }
            $result[] = $normalized;
        }

        return $result;
    }

    private function positiveInteger(mixed $value): int
    {
        if (is_int($value)) {
            return max(0, $value);
        }
        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        return 0;
    }
}
