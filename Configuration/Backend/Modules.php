<?php

declare(strict_types=1);

use Webconsulting\Typo3AiChat\Controller\ChatModuleController;

return [
    'webconsulting_ai_chat' => [
        'parent' => 'tools',
        'position' => ['after' => '*'],
        'access' => 'user',
        'path' => '/module/tools/ai-chat',
        'iconIdentifier' => 'module-typo3-ai-chat',
        'labels' => 'LLL:EXT:webconsulting_ai_chat/Resources/Private/Language/locallang_mod.xlf',
        'routes' => [
            '_default' => [
                'target' => ChatModuleController::class . '::indexAction',
            ],
        ],
    ],
];
