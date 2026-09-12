<?php

declare(strict_types=1);

return [
    'ctrl' => [
        'title' => 'AI Chat message',
        'label' => 'role',
        'label_alt' => 'content',
        'label_alt_force' => true,
        'crdate' => 'crdate',
        // No `delete` column on purpose: a transcript row is either there or it
        // is gone. A soft-deleted message would still be part of the context
        // the model was given, which is precisely the thing an audit reader
        // must not have to reason about.
        'readOnly' => true,
        'adminOnly' => true,
        'rootLevel' => 1,
        'iconfile' => 'EXT:webconsulting_ai_chat/Resources/Public/Icons/ModuleIcon.svg',
        'searchFields' => 'content',
        'default_sortby' => 'conversation DESC, sequence ASC',
    ],
    'types' => [
        '0' => [
            'showitem' => 'conversation, sequence, role, content, tool_call_id, run_uuid, prompt_tokens, completion_tokens',
        ],
    ],
    'columns' => [
        'conversation' => [
            'label' => 'Conversation',
            'config' => [
                'type' => 'group',
                'allowed' => 'tx_webconsultingaichat_conversation',
                'maxitems' => 1,
                'readOnly' => true,
            ],
        ],
        'sequence' => [
            'label' => 'Sequence',
            'config' => ['type' => 'number', 'readOnly' => true],
        ],
        'role' => [
            'label' => 'Role',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'readOnly' => true,
                'items' => [
                    ['label' => 'System', 'value' => 'system'],
                    ['label' => 'User', 'value' => 'user'],
                    ['label' => 'Assistant', 'value' => 'assistant'],
                    ['label' => 'Tool', 'value' => 'tool'],
                ],
            ],
        ],
        'content' => [
            'label' => 'Content',
            'config' => ['type' => 'text', 'readOnly' => true],
        ],
        'tool_call_id' => [
            'label' => 'Answers tool call',
            'config' => ['type' => 'input', 'readOnly' => true],
        ],
        'run_uuid' => [
            'label' => 'nr-llm run',
            'config' => ['type' => 'input', 'readOnly' => true],
        ],
        'prompt_tokens' => [
            'label' => 'Prompt tokens',
            'config' => ['type' => 'number', 'readOnly' => true],
        ],
        'completion_tokens' => [
            'label' => 'Completion tokens',
            'config' => ['type' => 'number', 'readOnly' => true],
        ],
    ],
];
