<?php

declare(strict_types=1);

return [
    'webconsulting_ai_chat_chat' => [
        'parent' => 'tools',
        'position' => ['after' => '*'],
        'access' => 'user',
        'iconIdentifier' => 'module-typo3-ai-chat',
        'labels' => 'LLL:EXT:webconsulting_ai_chat/Resources/Private/Language/locallang_mod.xlf',
        'routes' => [
            '_default' => [
                'target' => \Webconsulting\Typo3AiChat\Controller\ChatModuleController::class . '::indexAction',
            ],
        ],
    ],
];
