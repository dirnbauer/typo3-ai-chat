<?php

declare(strict_types=1);

use Webconsulting\Typo3AiChat\Controller\ChatApiController;

return [
    'ai_chat_status' => [
        'path' => '/ai-chat/status',
        'target' => ChatApiController::class . '::getStatus',
        'methods' => ['GET'],
    ],
    'ai_chat_conversations' => [
        'path' => '/ai-chat/conversations',
        'target' => ChatApiController::class . '::listConversations',
        'methods' => ['GET'],
    ],
    'ai_chat_conversation_create' => [
        'path' => '/ai-chat/conversations/create',
        'target' => ChatApiController::class . '::createConversation',
        'methods' => ['POST'],
    ],
    'ai_chat_conversation_messages' => [
        'path' => '/ai-chat/conversations/messages',
        'target' => ChatApiController::class . '::getMessages',
        'methods' => ['GET'],
    ],
    'ai_chat_conversation_send' => [
        'path' => '/ai-chat/conversations/send',
        'target' => ChatApiController::class . '::sendMessage',
        'methods' => ['POST'],
    ],
    'ai_chat_conversation_resume' => [
        'path' => '/ai-chat/conversations/resume',
        'target' => ChatApiController::class . '::resumeConversation',
        'methods' => ['POST'],
    ],
    'ai_chat_conversation_approval' => [
        'path' => '/ai-chat/conversations/approval',
        'target' => ChatApiController::class . '::decideApproval',
        'methods' => ['POST'],
    ],
    'ai_chat_conversation_archive' => [
        'path' => '/ai-chat/conversations/archive',
        'target' => ChatApiController::class . '::archiveConversation',
        'methods' => ['POST'],
    ],
    'ai_chat_conversation_pin' => [
        'path' => '/ai-chat/conversations/pin',
        'target' => ChatApiController::class . '::togglePin',
        'methods' => ['POST'],
    ],
    'ai_chat_conversation_rename' => [
        'path'    => '/ai-chat/conversations/rename',
        'target'  => ChatApiController::class . '::renameConversation',
        'methods' => ['POST'],
    ],
    'ai_chat_file_upload' => [
        'path' => '/ai-chat/file-upload',
        'target' => ChatApiController::class . '::fileUpload',
        'methods' => ['POST'],
    ],
    'ai_chat_file_info' => [
        'path' => '/ai-chat/file-info',
        'target' => ChatApiController::class . '::fileInfo',
        'methods' => ['GET'],
    ],
    'ai_chat_flue_trigger' => [
        'path' => '/ai-chat/flue/trigger',
        'target' => ChatApiController::class . '::triggerFlue',
        'methods' => ['POST'],
    ],
    'ai_chat_flue_status' => [
        'path' => '/ai-chat/flue/status',
        'target' => ChatApiController::class . '::flueStatus',
        'methods' => ['GET'],
    ],
];
