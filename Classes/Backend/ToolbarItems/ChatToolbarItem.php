<?php

declare(strict_types=1);

namespace Webconsulting\Typo3AiChat\Backend\ToolbarItems;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Toolbar\RequestAwareToolbarItemInterface;
use TYPO3\CMS\Backend\Toolbar\ToolbarItemInterface;
use TYPO3\CMS\Core\Page\PageRenderer;
use Webconsulting\Typo3AiChat\Configuration\ExtensionConfiguration;
use Webconsulting\Typo3AiChat\Service\BackendUserContext;

/**
 * The toolbar button that opens the chat panel.
 *
 * It loads a LAUNCHER, not the chat. The launcher is a few lines that put a
 * `<wc-ai-chat variant="panel">` element into the top document and import the
 * bundle on first use, so a backend page that never opens the chat pays for
 * nothing — and the panel, living in the top document, survives module
 * navigation instead of being destroyed by every click in the module menu.
 */
final readonly class ChatToolbarItem implements ToolbarItemInterface, RequestAwareToolbarItemInterface
{
    public function __construct(
        private ExtensionConfiguration $config,
        private PageRenderer $pageRenderer,
        private BackendUserContext $backendUser,
    ) {}

    public function setRequest(ServerRequestInterface $request): void
    {
        // Interface-required: this toolbar item does not read the request.
    }

    public function checkAccess(): bool
    {
        if ($this->config->getLlmTaskUid() === 0) {
            return false;
        }

        return $this->backendUser->mayUseChat($this->config->getAllowedGroupIds());
    }

    public function getItem(): string
    {
        $this->pageRenderer->loadJavaScriptModule('@webconsulting/typo3-ai-chat/toolbar/launcher.js');

        return '<span class="toolbar-item-link ai-chat-toolbar-btn" role="button"'
            . ' aria-label="Open TYPO3 AI Chat" aria-expanded="false" title="TYPO3 AI Chat" tabindex="0">'
            . '<typo3-backend-icon identifier="toolbar-typo3-ai-chat" size="small"></typo3-backend-icon>'
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

    /**
     * @return array<string, string>
     */
    public function getAdditionalAttributes(): array
    {
        return ['class' => 'toolbar-item ai-chat-toolbar'];
    }

    public function getIndex(): int
    {
        return 25;
    }
}
