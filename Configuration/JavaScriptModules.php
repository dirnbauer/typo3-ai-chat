<?php

declare(strict_types=1);

return [
    'dependencies' => ['backend'],
    'tags' => [
        'backend.module',
    ],
    'imports' => [
        '@webconsulting/typo3-ai-chat/' => 'EXT:webconsulting_ai_chat/Resources/Public/JavaScript/',
        'marked' => 'EXT:webconsulting_ai_chat/Resources/Public/JavaScript/Vendor/marked.esm.js',
        'dompurify' => 'EXT:webconsulting_ai_chat/Resources/Public/JavaScript/Vendor/dompurify.esm.js',
    ],
];
