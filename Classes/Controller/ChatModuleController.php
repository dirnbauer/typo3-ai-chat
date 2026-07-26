<?php

declare(strict_types=1);

namespace Webconsulting\Typo3AiChat\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Page\PageRenderer;
use Webconsulting\Typo3AiChat\Configuration\ExtensionConfiguration;

final readonly class ChatModuleController
{
    public function __construct(
        private ModuleTemplateFactory $moduleTemplateFactory,
        private PageRenderer $pageRenderer,
        private ExtensionConfiguration $config,
    ) {}

    public function indexAction(ServerRequestInterface $request): ResponseInterface
    {
        $this->pageRenderer->loadJavaScriptModule('@webconsulting/typo3-ai-chat/Dist/operator.js');
        $this->pageRenderer->addCssFile('EXT:webconsulting_ai_chat/Resources/Public/JavaScript/Dist/operator.css');

        $view = $this->moduleTemplateFactory->create($request);
        $view->setTitle('TYPO3 AI Chat', 'Operator console');
        $view->assignMultiple([
            'maxMessageLength' => $this->config->getMaxMessageLength(),
        ]);

        return $view->renderResponse('Chat/Index');
    }
}
