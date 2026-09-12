<?php

declare(strict_types=1);

use Webconsulting\Typo3AiChat\Controller\ChatApiController;

/**
 * The chat API.
 *
 * Every route that changes something is POST, and that is a security property
 * rather than a style: TYPO3's backend AJAX routes carry CSRF protection, and a
 * state change reachable by GET is reachable from an <img> tag on any page a
 * logged-in editor happens to visit.
 *
 * `conversations/turn` is one route and not two. It starts a turn and then
 * reports it — as server-sent events when the client asks for
 * `Accept: text/event-stream`, and as one JSON document otherwise — with the
 * SAME event list either way, so the two transports cannot drift into
 * disagreeing about what happened.
 */
return [
    // --- reads -------------------------------------------------------------
    'webconsulting_ai_chat_status' => [
        'path' => '/webconsulting/ai-chat/status',
        'target' => ChatApiController::class . '::status',
        'methods' => ['GET'],
    ],
    'webconsulting_ai_chat_conversations' => [
        'path' => '/webconsulting/ai-chat/conversations',
        'target' => ChatApiController::class . '::listConversations',
        'methods' => ['GET'],
    ],
    'webconsulting_ai_chat_conversation_get' => [
        'path' => '/webconsulting/ai-chat/conversations/get',
        'target' => ChatApiController::class . '::getConversation',
        'methods' => ['GET'],
    ],
    // The execution trace of a run, read from nr-llm. This extension keeps no
    // copy of it, so this route is the only place it comes from.
    'webconsulting_ai_chat_conversation_events' => [
        'path' => '/webconsulting/ai-chat/conversations/events',
        'target' => ChatApiController::class . '::runEvents',
        'methods' => ['GET'],
    ],
    'webconsulting_ai_chat_file_info' => [
        'path' => '/webconsulting/ai-chat/files/info',
        'target' => ChatApiController::class . '::fileInfo',
        'methods' => ['GET'],
    ],

    // --- writes ------------------------------------------------------------
    'webconsulting_ai_chat_conversation_create' => [
        'path' => '/webconsulting/ai-chat/conversations/create',
        'target' => ChatApiController::class . '::createConversation',
        'methods' => ['POST'],
    ],
    'webconsulting_ai_chat_conversation_turn' => [
        'path' => '/webconsulting/ai-chat/conversations/turn',
        'target' => ChatApiController::class . '::turn',
        'methods' => ['POST'],
    ],
    'webconsulting_ai_chat_conversation_approval' => [
        'path' => '/webconsulting/ai-chat/conversations/approval',
        'target' => ChatApiController::class . '::approval',
        'methods' => ['POST'],
    ],
    'webconsulting_ai_chat_conversation_cancel' => [
        'path' => '/webconsulting/ai-chat/conversations/cancel',
        'target' => ChatApiController::class . '::cancel',
        'methods' => ['POST'],
    ],
    'webconsulting_ai_chat_conversation_archive' => [
        'path' => '/webconsulting/ai-chat/conversations/archive',
        'target' => ChatApiController::class . '::archive',
        'methods' => ['POST'],
    ],
    'webconsulting_ai_chat_conversation_pin' => [
        'path' => '/webconsulting/ai-chat/conversations/pin',
        'target' => ChatApiController::class . '::pin',
        'methods' => ['POST'],
    ],
    'webconsulting_ai_chat_conversation_rename' => [
        'path' => '/webconsulting/ai-chat/conversations/rename',
        'target' => ChatApiController::class . '::rename',
        'methods' => ['POST'],
    ],
    'webconsulting_ai_chat_conversation_delete' => [
        'path' => '/webconsulting/ai-chat/conversations/delete',
        'target' => ChatApiController::class . '::delete',
        'methods' => ['POST'],
    ],
    'webconsulting_ai_chat_file_upload' => [
        'path' => '/webconsulting/ai-chat/files/upload',
        'target' => ChatApiController::class . '::fileUpload',
        'methods' => ['POST'],
    ],
];
