<?php

declare(strict_types=1);

namespace Webconsulting\Typo3AiChat\Backend\ToolbarItems;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Toolbar\RequestAwareToolbarItemInterface;
use TYPO3\CMS\Backend\Toolbar\ToolbarItemInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Page\PageRenderer;
use Webconsulting\Typo3AiChat\Configuration\ExtensionConfiguration;

final readonly class ChatToolbarItem implements ToolbarItemInterface, RequestAwareToolbarItemInterface
{
    public function __construct(
        private ExtensionConfiguration $config,
        private PageRenderer $pageRenderer,
    ) {}

    public function setRequest(ServerRequestInterface $request): void
    {
        // Interface-required no-op: this toolbar item does not use the server request
    }

    public function checkAccess(): bool
    {
        if ($this->config->getLlmTaskUid() === 0) {
            return false;
        }
        $allowed = $this->config->getAllowedGroupIds();
        if ($allowed === []) {
            return true;
        }
        $beUser = $this->getBackendUser();
        if ($beUser === null) {
            return false;
        }
        // Admin users always have access (consistent with ChatApiController)
        if ($beUser->isAdmin()) {
            return true;
        }
        return $this->userIsInAllowedGroup($beUser, $allowed);
    }

    /**
     * @param list<int> $allowed
     */
    private function userIsInAllowedGroup(BackendUserAuthentication $beUser, array $allowed): bool
    {
        $usergroup = $beUser->user['usergroup'] ?? null;
        $userGroups = array_map(intval(...), explode(',', is_string($usergroup) ? $usergroup : ''));
        return array_intersect($allowed, $userGroups) !== [];
    }

    public function getItem(): string
    {
        $this->pageRenderer->loadJavaScriptModule('@webconsulting/typo3-ai-chat/toolbar/chat-panel.js');
        $this->pageRenderer->addCssFile('EXT:webconsulting_ai_chat/Resources/Public/JavaScript/Dist/operator.css');
        $this->pageRenderer->addInlineLanguageLabelFile('EXT:webconsulting_ai_chat/Resources/Private/Language/locallang_chat.xlf');

        // Badge count is updated client-side from the status endpoint
        // to avoid a DB query on every backend page load.
        return '<span class="toolbar-item-link ai-chat-toolbar-btn" role="button" aria-label="Open TYPO3 AI Chat" aria-expanded="false" title="TYPO3 AI Chat" tabindex="0">'
            . '<typo3-backend-icon identifier="toolbar-typo3-ai-chat" size="small"></typo3-backend-icon>'
            . '<span class="badge badge-warning ai-chat-badge" style="display:none">0</span>'
            . '</span>';
    }

    public function hasDropDown(): bool
    {
        return false;
    }

    public function getDropDown(): string
    {
        return '';
    }

    /** @return array<string, string> */
    public function getAdditionalAttributes(): array
    {
        return ['class' => 'toolbar-item ai-chat-toolbar'];
    }

    public function getIndex(): int
    {
        return 25;
    }

    private function getBackendUser(): ?BackendUserAuthentication
    {
        $user = $GLOBALS['BE_USER'] ?? null;
        return $user instanceof BackendUserAuthentication ? $user : null;
    }
}
