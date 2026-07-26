<?php

declare(strict_types=1);

use Webconsulting\Typo3AiChat\Controller\ChatApiController;

return [
    'webconsulting_ai_chat_status' => [
        'path' => '/webconsulting/ai-chat/status',
        'target' => ChatApiController::class . '::getStatus',
        'methods' => ['GET'],
    ],
    'webconsulting_ai_chat_conversations' => [
        'path' => '/webconsulting/ai-chat/conversations',
        'target' => ChatApiController::class . '::listConversations',
        'methods' => ['GET'],
    ],
    'webconsulting_ai_chat_conversation_create' => [
        'path' => '/webconsulting/ai-chat/conversations/create',
        'target' => ChatApiController::class . '::createConversation',
        'methods' => ['POST'],
    ],
    'webconsulting_ai_chat_conversation_messages' => [
        'path' => '/webconsulting/ai-chat/conversations/messages',
        'target' => ChatApiController::class . '::getMessages',
        'methods' => ['GET'],
    ],
    'webconsulting_ai_chat_conversation_send' => [
        'path' => '/webconsulting/ai-chat/conversations/send',
        'target' => ChatApiController::class . '::sendMessage',
        'methods' => ['POST'],
    ],
    'webconsulting_ai_chat_conversation_resume' => [
        'path' => '/webconsulting/ai-chat/conversations/resume',
        'target' => ChatApiController::class . '::resumeConversation',
        'methods' => ['POST'],
    ],
    'webconsulting_ai_chat_conversation_approval' => [
        'path' => '/webconsulting/ai-chat/conversations/approval',
        'target' => ChatApiController::class . '::decideApproval',
        'methods' => ['POST'],
    ],
    'webconsulting_ai_chat_conversation_archive' => [
        'path' => '/webconsulting/ai-chat/conversations/archive',
        'target' => ChatApiController::class . '::archiveConversation',
        'methods' => ['POST'],
    ],
    'webconsulting_ai_chat_conversation_pin' => [
        'path' => '/webconsulting/ai-chat/conversations/pin',
        'target' => ChatApiController::class . '::togglePin',
        'methods' => ['POST'],
    ],
    'webconsulting_ai_chat_conversation_rename' => [
        'path'    => '/webconsulting/ai-chat/conversations/rename',
        'target'  => ChatApiController::class . '::renameConversation',
        'methods' => ['POST'],
    ],
    'webconsulting_ai_chat_file_upload' => [
        'path' => '/webconsulting/ai-chat/file-upload',
        'target' => ChatApiController::class . '::fileUpload',
        'methods' => ['POST'],
    ],
    'webconsulting_ai_chat_file_info' => [
        'path' => '/webconsulting/ai-chat/file-info',
        'target' => ChatApiController::class . '::fileInfo',
        'methods' => ['GET'],
    ],
    'webconsulting_ai_chat_flue_trigger' => [
        'path' => '/webconsulting/ai-chat/flue/trigger',
        'target' => ChatApiController::class . '::triggerFlue',
        'methods' => ['POST'],
    ],
    'webconsulting_ai_chat_flue_status' => [
        'path' => '/webconsulting/ai-chat/flue/status',
        'target' => ChatApiController::class . '::flueStatus',
        'methods' => ['GET'],
    ],
];
