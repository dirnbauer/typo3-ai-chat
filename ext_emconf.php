<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'Webconsulting TYPO3 AI Chat',
    'description' => 'Modern governed TYPO3 operator chat with MCP tools, approvals, rich attachments and optional Flue workflows. Inspired by nr-mcp-agent — thank you, Netresearch.',
    'category' => 'module',
    'version' => '1.0.0',
    'state' => 'alpha',
    'author' => 'Webconsulting; inspired by Netresearch DTT GmbH',
    'author_email' => '',
    'author_company' => 'Webconsulting',
    'constraints' => [
        'depends' => [
            'php' => '8.2.0-8.99.99',
            'typo3' => '13.4.0-14.99.99',
            'nr_llm' => '0.25.0-0.99.99',
        ],
    ],
];
