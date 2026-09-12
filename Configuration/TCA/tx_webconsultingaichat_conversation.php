<?php

declare(strict_types=1);

return [
    'ctrl' => [
        'title' => 'AI Chat conversation',
        'label' => 'title',
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'delete' => 'deleted',
        // Conversations are written by the chat API, never through FormEngine:
        // the record is an audit surface for an administrator, not an editing
        // one.
        'readOnly' => true,
        'adminOnly' => true,
        'rootLevel' => 1,
        'iconfile' => 'EXT:webconsulting_ai_chat/Resources/Public/Icons/ModuleIcon.svg',
        'searchFields' => 'title',
        'default_sortby' => 'last_message_at DESC',
    ],
    'types' => [
        '0' => [
            'showitem' => 'title, be_user, status, message_count, run_uuid, auto_approve_tools, error_message, archived, pinned, last_message_at',
        ],
    ],
    'columns' => [
        'title' => [
            'label' => 'Title',
            'config' => ['type' => 'input', 'readOnly' => true],
        ],
        'be_user' => [
            'label' => 'Backend user',
            'config' => ['type' => 'group', 'allowed' => 'be_users', 'maxitems' => 1, 'readOnly' => true],
        ],
        'status' => [
            'label' => 'Status',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'readOnly' => true,
                'items' => [
                    ['label' => 'Idle', 'value' => 'idle'],
                    ['label' => 'Processing', 'value' => 'processing'],
                    ['label' => 'Awaiting approval', 'value' => 'awaiting_approval'],
                    ['label' => 'Failed', 'value' => 'failed'],
                ],
                'default' => 'idle',
            ],
        ],
        'message_count' => [
            'label' => 'Messages',
            'config' => ['type' => 'number', 'readOnly' => true],
        ],
        'run_uuid' => [
            'label' => 'nr-llm run',
            'description' => 'The agent run this conversation is bound to. Its steps live in nr-llm, not here.',
            'config' => ['type' => 'input', 'readOnly' => true],
        ],
        'auto_approve_tools' => [
            'label' => 'Auto-approve permitted write tools',
            'config' => ['type' => 'check', 'readOnly' => true],
        ],
        'error_message' => [
            'label' => 'Error',
            'config' => ['type' => 'text', 'readOnly' => true],
        ],
        'archived' => [
            'label' => 'Archived',
            'config' => ['type' => 'check', 'readOnly' => true],
        ],
        'pinned' => [
            'label' => 'Pinned',
            'config' => ['type' => 'check', 'readOnly' => true],
        ],
        'last_message_at' => [
            'label' => 'Last message',
            'config' => ['type' => 'datetime', 'readOnly' => true],
        ],
    ],
];
