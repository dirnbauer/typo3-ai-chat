<?php

declare(strict_types=1);

defined('TYPO3') or die();

// Tool catalogue cache — the MCP catalogue projection (name, description,
// JSON schema, effect) only changes when code or the capability manifest
// changes, so it is resolved once per cache lifetime rather than on every
// agent run. Cleared by a normal "flush system caches".
$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['webconsulting_ai_chat_tools'] ??= [
    'frontend' => \TYPO3\CMS\Core\Cache\Frontend\VariableFrontend::class,
    'backend' => \TYPO3\CMS\Core\Cache\Backend\Typo3DatabaseBackend::class,
    'options' => ['defaultLifetime' => 3600],
    'groups' => ['system'],
];
