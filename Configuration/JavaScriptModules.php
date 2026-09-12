<?php

declare(strict_types=1);

/**
 * One import specifier, and no vendored libraries.
 *
 * 1.x published `marked` and `dompurify` here as import-map entries so a
 * build-free UI could reach them. The 2.0 UI is bundled, so its dependencies
 * belong in the bundle, where a bundler can pin, tree-shake and audit them. An
 * import-map entry is a global on the backend's own module graph: every
 * extension that adds one is another way for two extensions to disagree about
 * which copy of a library the whole backend runs.
 */
return [
    'dependencies' => ['backend'],
    'tags' => [
        'backend.module',
    ],
    'imports' => [
        '@webconsulting/typo3-ai-chat/' => 'EXT:webconsulting_ai_chat/Resources/Public/JavaScript/',
    ],
];
