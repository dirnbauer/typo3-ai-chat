<?php

declare(strict_types=1);

namespace Webconsulting\Typo3AiChat\Service;

use RuntimeException;
use Webconsulting\Typo3AiChat\Configuration\ExtensionConfiguration;
use Webconsulting\Typo3AiChat\Domain\Model\Conversation;
use Webconsulting\Typo3AiChat\Domain\Repository\ConversationRepository;
use Webconsulting\Typo3AiChat\Enum\ConversationStatus;
use Webconsulting\Typo3AiChat\Enum\MessageRole;

/**
 * Optional adapter to Webconsulting Flue without making it a hard dependency.
 *
 * Flue remains the authority for MCP credentials, durable run state, tool
 * allowlists and draft-workspace safety. This bridge only links a conversation
 * to a configured flow and mirrors its terminal result into the chat.
 */
final readonly class FlueWorkflowBridge
{
    private const TRIGGER_SERVICE = 'Webconsulting\\Flue\\Service\\FlowTriggerService';
    private const RUN_STORE = 'Webconsulting\\Flue\\Service\\RunStore';

    public function __construct(
        private ExtensionConfiguration $configuration,
        private ConversationRepository $conversationRepository,
    ) {}

    public function isAvailable(): bool
    {
        return $this->configuration->isFlueEnabled()
            && $this->configuration->getFlueFlowUid() > 0
            && class_exists(self::TRIGGER_SERVICE)
            && class_exists(self::RUN_STORE);
    }

    /**
     * @return array{runUid: int, status: string}
     */
    public function trigger(
        Conversation $conversation,
        string $instructions,
        int $pageUid,
        int $workspaceId,
        int $backendUserUid,
    ): array {
        if (!$this->isAvailable()) {
            throw new RuntimeException('Flue is not enabled or no Flue flow UID is configured.', 1764175501);
        }
        if ($pageUid <= 0) {
            throw new RuntimeException('A target page UID is required for a Flue workflow.', 1764175502);
        }

        $service = $this->optionalService(self::TRIGGER_SERVICE);
        $result = $this->invoke(
            $service,
            'trigger',
            $this->configuration->getFlueFlowUid(),
            'pages',
            $pageUid,
            max(0, $workspaceId),
            $instructions,
            $backendUserUid,
        );
        if (!is_array($result) || !is_numeric($result['runUid'] ?? null)) {
            throw new RuntimeException('Flue did not return a durable run identifier.', 1764175503);
        }

        $runUid = (int) $result['runUid'];
        $conversation->appendMessage(MessageRole::User, $instructions);
        $conversation->setFlueRunUid($runUid);
        $conversation->setStatus(ConversationStatus::FlueRunning);
        $conversation->setErrorMessage('');
        $this->conversationRepository->update($conversation);

        return [
            'runUid' => $runUid,
            'status' => is_string($result['status'] ?? null) ? $result['status'] : 'submitted',
        ];
    }

    /**
     * @return array{status: string, output: string, error: string, events: list<array<string, mixed>>}
     */
    public function synchronize(Conversation $conversation): array
    {
        if (!$this->isAvailable() || $conversation->getFlueRunUid() <= 0) {
            throw new RuntimeException('No Flue run is linked to this conversation.', 1764175504);
        }

        $triggerService = $this->optionalService(self::TRIGGER_SERVICE);
        $this->invoke($triggerService, 'drainRun', $conversation->getFlueRunUid(), null, 1, false);

        $runStore = $this->optionalService(self::RUN_STORE);
        $run = $this->invoke($runStore, 'load', $conversation->getFlueRunUid());
        if (!is_object($run)) {
            throw new RuntimeException('The linked Flue run could not be loaded.', 1764175505);
        }

        $values = get_object_vars($run);
        $statusObject = $values['status'] ?? null;
        $statusValues = is_object($statusObject) ? get_object_vars($statusObject) : [];
        $status = is_string($statusValues['value'] ?? null) ? $statusValues['value'] : 'running';
        $output = is_string($values['output'] ?? null) ? $values['output'] : '';
        $error = is_string($values['errorMessage'] ?? null) ? $values['errorMessage'] : '';
        $events = [];
        foreach (is_array($values['events'] ?? null) ? $values['events'] : [] as $event) {
            if (is_object($event) && method_exists($event, 'toArray')) {
                $eventArray = $event->toArray();
                if (is_array($eventArray)) {
                    $normalized = [];
                    foreach ($eventArray as $key => $value) {
                        if (is_string($key)) {
                            $normalized[$key] = $value;
                        }
                    }
                    $events[] = $normalized;
                }
            }
        }

        if ($conversation->getStatus() === ConversationStatus::FlueRunning && in_array($status, ['settled', 'failed'], true)) {
            $trace = $conversation->getExecutionTrace();
            $trace[] = [
                'name' => 'flue.workflow',
                'arguments' => [
                    'flowUid' => $this->configuration->getFlueFlowUid(),
                    'targetPageUid' => is_numeric($values['targetUid'] ?? null) ? (int) $values['targetUid'] : 0,
                    'workspaceUid' => is_numeric($values['workspaceUid'] ?? null) ? (int) $values['workspaceUid'] : 0,
                ],
                'result' => $output !== '' ? $output : $error,
                'isError' => $status === 'failed',
            ];
            $conversation->setExecutionTrace($trace);

            if ($status === 'settled') {
                $conversation->appendMessage(
                    MessageRole::Assistant,
                    $output !== '' ? $output : 'The Flue workflow settled without textual output.',
                );
                $conversation->setStatus(ConversationStatus::Idle);
            } else {
                $conversation->setStatus(ConversationStatus::Failed);
                $conversation->setErrorMessage($error !== '' ? $error : 'The Flue workflow failed.');
            }
            $this->conversationRepository->update($conversation);
        }

        return compact('status', 'output', 'error', 'events');
    }

    private function optionalService(string $className): object
    {
        $container = \TYPO3\CMS\Core\Utility\GeneralUtility::getContainer();
        if (!$container->has($className)) {
            throw new RuntimeException(sprintf('Optional Flue service "%s" is unavailable.', $className), 1764175506);
        }
        $service = $container->get($className);
        if (!is_object($service)) {
            throw new RuntimeException(sprintf('Optional Flue service "%s" is invalid.', $className), 1764175507);
        }

        return $service;
    }

    private function invoke(object $service, string $method, mixed ...$arguments): mixed
    {
        if (!method_exists($service, $method)) {
            throw new RuntimeException(sprintf('Optional Flue service method "%s" is unavailable.', $method), 1764175508);
        }

        return $service->{$method}(...$arguments);
    }
}
