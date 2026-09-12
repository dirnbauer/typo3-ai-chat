<?php

declare(strict_types=1);

namespace Webconsulting\Typo3AiChat\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Page\PageRenderer;
use Webconsulting\Typo3AiChat\Domain\Repository\ConversationRepository;
use Webconsulting\Typo3AiChat\Service\BackendUserContext;

/**
 * The full-size chat module.
 *
 * The same custom element as the toolbar panel, told it has more room. Two
 * surfaces, one implementation: a module that re-implemented the panel would be
 * a second chat to keep in step with the first.
 */
final readonly class ChatModuleController
{
    public function __construct(
        private ModuleTemplateFactory $moduleTemplateFactory,
        private PageRenderer $pageRenderer,
        private ConversationRepository $conversations,
        private BackendUserContext $backendUser,
    ) {}

    public function indexAction(ServerRequestInterface $request): ResponseInterface
    {
        $this->pageRenderer->loadJavaScriptModule('@webconsulting/typo3-ai-chat/Dist/app.js');

        $requested = $request->getQueryParams()['conversation'] ?? null;
        $conversationUid = 0;
        if (is_numeric($requested)) {
            // A uid in a URL is a request, not an entitlement: it only survives
            // if it resolves to a conversation this user owns.
            $conversationUid = $this->conversations
                ->findOneByUidAndBeUser((int)$requested, $this->backendUser->uid())
                ?->getUid() ?? 0;
        }

        $view = $this->moduleTemplateFactory->create($request);
        $view->setTitle('TYPO3 AI Chat');
        $view->assign('conversationUid', $conversationUid);

        return $view->renderResponse('Chat/Index');
    }
}
