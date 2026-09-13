<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'Webconsulting TYPO3 AI Chat',
    'description' => 'Governed TYPO3 operator chat: nr-llm agent runs execute the installation\'s own MCP tools in-process, with human approval for every write. Inspired by nr-mcp-agent — thank you, Netresearch.',
    'category' => 'module',
    'version' => '2.0.1',
    'state' => 'beta',
    'author' => 'Webconsulting; inspired by Netresearch DTT GmbH',
    'author_email' => '',
    'author_company' => 'Webconsulting',
    'constraints' => [
        'depends' => [
            'php' => '8.4.0-8.99.99',
            'typo3' => '14.3.7-14.99.99',
            'nr_llm' => '0.34.0-0.99.99',
            'mcp_server' => '0.7.0-0.99.99',
        ],
    ],
];
