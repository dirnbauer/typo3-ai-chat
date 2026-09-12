<?php

declare(strict_types=1);

$config = TYPO3\CodingStandards\CsFixerConfig::create();

$config
    ->setRiskyAllowed(true)
    ->setRules(array_merge($config->getRules(), [
        'declare_strict_types' => true,
        'no_unused_imports' => true,
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'single_line_empty_body' => true,
        'global_namespace_import' => [
            'import_classes' => true,
            'import_functions' => false,
            'import_constants' => false,
        ],
    ]))
    ->getFinder()
    ->in(__DIR__ . '/Classes')
    ->in(__DIR__ . '/Configuration')
    ->in(__DIR__ . '/Tests');

return $config;
