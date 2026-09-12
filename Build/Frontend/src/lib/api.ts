import { readEventStream } from '@/lib/sse';
import type {
  AttachmentInfo,
  ChatStatus,
  ConversationSummary,
  MessageRow,
  SequencedEvent,
  TurnJsonResponse,
} from '@/state/types';

/**
 * The chat API, as the browser reaches it.
 *
 * Every URL comes from `TYPO3.settings.ajaxUrls`, never from string
 * concatenation. That is not tidiness: a backend AJAX route's URL carries a
 * per-session token, and a hand-built path arrives without it and is rejected.
 * It is also what keeps this file honest when a route is renamed — a missing
 * key fails loudly here instead of returning a 404 page somewhere downstream.
 */

export const ROUTES = {
  status: 'webconsulting_ai_chat_status',
  conversations: 'webconsulting_ai_chat_conversations',
  conversationGet: 'webconsulting_ai_chat_conversation_get',
  conversationEvents: 'webconsulting_ai_chat_conversation_events',
  fileInfo: 'webconsulting_ai_chat_file_info',
  conversationCreate: 'webconsulting_ai_chat_conversation_create',
  conversationTurn: 'webconsulting_ai_chat_conversation_turn',
  conversationApproval: 'webconsulting_ai_chat_conversation_approval',
  conversationCancel: 'webconsulting_ai_chat_conversation_cancel',
  conversationArchive: 'webconsulting_ai_chat_conversation_archive',
  conversationPin: 'webconsulting_ai_chat_conversation_pin',
  conversationRename: 'webconsulting_ai_chat_conversation_rename',
  conversationDelete: 'webconsulting_ai_chat_conversation_delete',
  fileUpload: 'webconsulting_ai_chat_file_upload',
} as const;

export type RouteName = (typeof ROUTES)[keyof typeof ROUTES];

interface Typo3Global {
  settings?: { ajaxUrls?: Record<string, string> };
  ModuleMenu?: { App?: { showModule?: (name: string, params?: string) => void } };
}

declare global {
  interface Window {
    TYPO3?: Typo3Global;
  }
}

/**
 * The panel lives in the top document and the module inside an iframe, and only
 * one of the two is guaranteed to carry `TYPO3.settings`. Asking the other one
 * is allowed — they are the same origin — but it can still throw if the backend
 * is framed by something else, so it is guarded rather than assumed.
 */
function typo3(): Typo3Global | undefined {
  if (window.TYPO3?.settings?.ajaxUrls) {
    return window.TYPO3;
  }
  try {
    if (window.top?.TYPO3?.settings?.ajaxUrls) {
      return window.top.TYPO3;
    }
  } catch {
    // Cross-origin top frame. The local TYPO3 object is all there is.
  }

  return window.TYPO3;
}

export class ApiError extends Error {
  constructor(
    message: string,
    readonly status: number,
  ) {
    super(message);
    this.name = 'ApiError';
  }

  /**
   * The status codes the API assigns meaning to, turned into the one sentence
   * the user can act on. A bare "409" teaches nobody anything; "the
   * conversation is busy" tells them to wait.
   */
  get hint(): string {
    switch (this.status) {
      case 0:
        return 'The backend could not be reached. Check your connection and try again.';
      case 400:
        return 'The message could not be sent as written.';
      case 403:
        return 'Your account is not allowed to use TYPO3 AI Chat.';
      case 404:
        return 'This conversation no longer exists.';
      case 409:
        return 'This conversation is busy, or the decision no longer applies to the run it was made for.';
      case 422:
        return 'That file cannot be read as text.';
      case 429:
        return 'You have reached a limit. Wait a moment, or finish a running conversation first.';
      default:
        return this.status >= 500 ? 'The backend failed while handling the request.' : '';
    }
  }
}

export function routeUrl(name: RouteName): string {
  const url = typo3()?.settings?.ajaxUrls?.[name];
  if (typeof url !== 'string' || url === '') {
    throw new ApiError(`The backend route "${name}" is not registered.`, 0);
  }

  return url;
}

function withQuery(url: string, query: Record<string, string | number | undefined>): string {
  const entries = Object.entries(query).filter(([, value]) => value !== undefined && value !== '');
  if (entries.length === 0) {
    return url;
  }
  const separator = url.includes('?') ? '&' : '?';

  return (
    url +
    separator +
    entries.map(([key, value]) => `${key}=${encodeURIComponent(String(value))}`).join('&')
  );
}

async function failure(response: Response): Promise<ApiError> {
  let message = `Request failed with status ${response.status}.`;
  try {
    const body = (await response.json()) as { error?: unknown };
    if (typeof body.error === 'string' && body.error !== '') {
      message = body.error;
    }
  } catch {
    // A non-JSON error body (a PHP fatal, an nginx page) has nothing to add.
  }

  return new ApiError(message, response.status);
}

async function getJson<T>(
  name: RouteName,
  query: Record<string, string | number | undefined> = {},
  signal?: AbortSignal,
): Promise<T> {
  const response = await fetch(withQuery(routeUrl(name), query), {
    method: 'GET',
    credentials: 'same-origin',
    headers: { Accept: 'application/json' },
    ...(signal ? { signal } : {}),
  });
  if (!response.ok) {
    throw await failure(response);
  }

  return (await response.json()) as T;
}

async function postJson<T>(
  name: RouteName,
  body: unknown,
  signal?: AbortSignal,
): Promise<T> {
  const response = await fetch(routeUrl(name), {
    method: 'POST',
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    body: JSON.stringify(body),
    ...(signal ? { signal } : {}),
  });
  if (!response.ok) {
    throw await failure(response);
  }

  return (await response.json()) as T;
}

// ------------------------------------------------------------------- reads

export const fetchStatus = (signal?: AbortSignal): Promise<ChatStatus> =>
  getJson<ChatStatus>(ROUTES.status, {}, signal);

export const fetchConversations = (
  includeArchived: boolean,
  signal?: AbortSignal,
): Promise<{ conversations: ConversationSummary[] }> =>
  getJson(ROUTES.conversations, includeArchived ? { archived: '1' } : {}, signal);

export const fetchConversation = (
  conversation: number,
  after = 0,
  signal?: AbortSignal,
): Promise<{ conversation: ConversationSummary; messages: MessageRow[] }> =>
  getJson(ROUTES.conversationGet, { conversation, after: after || undefined }, signal);

export interface RunTraceEvent {
  sequence: number;
  kind: string;
  round: number;
  durationMs: number;
  payload: Record<string, unknown>;
  createdAt: number;
}

export const fetchRunEvents = (
  conversation: number,
  runUuid: string,
  after = -1,
  signal?: AbortSignal,
): Promise<{ runUuid: string; events: RunTraceEvent[] }> =>
  getJson(ROUTES.conversationEvents, { conversation, runUuid, after }, signal);

export const fetchFileInfo = (fileUid: number, signal?: AbortSignal): Promise<AttachmentInfo> =>
  getJson(ROUTES.fileInfo, { fileUid }, signal);

// ------------------------------------------------------------------ writes

export const createConversation = (
  title?: string,
  systemPrompt?: string,
): Promise<{ conversation: ConversationSummary }> =>
  postJson(ROUTES.conversationCreate, { title: title ?? '', systemPrompt: systemPrompt ?? '' });

export const cancelTurn = (conversation: number): Promise<{ cancelled: boolean }> =>
  postJson(ROUTES.conversationCancel, { conversation });

export const archiveConversation = (
  conversation: number,
  archived: boolean,
): Promise<{ archived: boolean }> => postJson(ROUTES.conversationArchive, { conversation, archived });

export const pinConversation = (
  conversation: number,
  pinned: boolean,
): Promise<{ pinned: boolean }> => postJson(ROUTES.conversationPin, { conversation, pinned });

export const renameConversation = (
  conversation: number,
  title: string,
  autoApproveTools?: boolean,
): Promise<{ title: string }> =>
  postJson(ROUTES.conversationRename, {
    conversation,
    title,
    ...(autoApproveTools === undefined ? {} : { autoApproveTools }),
  });

export const deleteConversation = (conversation: number): Promise<{ deleted: boolean }> =>
  postJson(ROUTES.conversationDelete, { conversation });

export async function uploadFile(conversation: number, file: File): Promise<AttachmentInfo> {
  const form = new FormData();
  form.append('conversation', String(conversation));
  form.append('file', file);

  const response = await fetch(routeUrl(ROUTES.fileUpload), {
    method: 'POST',
    credentials: 'same-origin',
    headers: { Accept: 'application/json' },
    body: form,
  });
  if (!response.ok) {
    throw await failure(response);
  }

  return (await response.json()) as AttachmentInfo;
}

// -------------------------------------------------------------------- turns

export interface TurnRequest {
  conversation: number;
  content: string;
  attachments: { fileUid: number }[];
  context?: Record<string, unknown>;
}

export interface ApprovalRequest {
  conversation: number;
  approved: boolean;
  /** Echoed back exactly as it arrived. See `PendingApproval.turnDigest`. */
  turnDigest: string;
}

/**
 * Run one turn and report it as it happens.
 *
 * Two transports, one route, negotiated by `Accept` — and the fallback is not a
 * different feature, it is the same event list arriving all at once. So the
 * caller is handed events either way and never has to know which path ran.
 *
 * Streaming is attempted first and abandoned on the first sign it will not
 * work: a body the browser will not stream, or a `Content-Type` that came back
 * as JSON because something between here and PHP decided to buffer the
 * response. Both are real (a proxy, a security module), and both look exactly
 * like a turn that produces nothing — which is the failure this fallback
 * exists to prevent.
 */
export async function runTurn(
  route: typeof ROUTES.conversationTurn | typeof ROUTES.conversationApproval,
  body: TurnRequest | ApprovalRequest,
  onEvent: (event: SequencedEvent) => void,
  signal?: AbortSignal,
): Promise<void> {
  const response = await fetch(routeUrl(route), {
    method: 'POST',
    credentials: 'same-origin',
    headers: {
      'Content-Type': 'application/json',
      // The whole negotiation, in one header.
      Accept: 'text/event-stream',
    },
    body: JSON.stringify(body),
    ...(signal ? { signal } : {}),
  });

  if (!response.ok) {
    throw await failure(response);
  }

  const contentType = response.headers.get('Content-Type') ?? '';
  if (response.body !== null && contentType.includes('text/event-stream')) {
    await readEventStream(response.body, onEvent, signal);

    return;
  }

  // Same events, one document. `events[]` is what the JSON transport returns
  // in place of the stream, and the ids the stream would have carried do not
  // exist here — which is correct: a single response cannot deliver a
  // duplicate.
  const document = (await response.json()) as TurnJsonResponse;
  for (const event of document.events ?? []) {
    onEvent({ event: event.event, data: event.data });
  }
}

/**
 * Open the full module on a conversation.
 *
 * The identifier is the one `Configuration/Backend/Modules.php` registers —
 * `webconsulting_ai_chat`, the array key, with no route suffix. A module
 * identifier that does not exist fails silently in `showModule()`, so it is
 * spelled here once and read from the PHP rather than guessed.
 *
 * `TYPO3.ModuleMenu` only exists in the top document — the panel is appended
 * there, so it is usually reachable directly, but the module variant has to ask
 * its parent. If neither answers (the backend framed by something else), the
 * caller gets `false` and can hide the action rather than offering a button
 * that does nothing.
 */
export const MODULE_IDENTIFIER = 'webconsulting_ai_chat';

export function openModule(conversationUid: number): boolean {
  const params = conversationUid > 0 ? `conversation=${conversationUid}` : '';
  const candidates: (Typo3Global | undefined)[] = [window.TYPO3];
  try {
    candidates.push(window.top?.TYPO3);
  } catch {
    // Cross-origin top frame.
  }

  for (const candidate of candidates) {
    const show = candidate?.ModuleMenu?.App?.showModule;
    if (typeof show === 'function') {
      show(MODULE_IDENTIFIER, params);

      return true;
    }
  }

  return false;
}
