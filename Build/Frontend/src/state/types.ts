/**
 * The API's vocabulary, in TypeScript.
 *
 * Every shape here is `Documentation/Developer/Api.rst` restated for the
 * compiler. Nothing is invented: where a field is optional in the PHP it is
 * optional here, because a client that assumes a field the server omits fails
 * at the one moment it must not — mid-run, with the user watching.
 */

export type ToolEffect = 'read_only' | 'idempotent_write' | 'non_idempotent_write';

export type RunOutcome =
  | 'completed'
  | 'awaiting_approval'
  | 'awaiting_input'
  | 'guardrail_blocked'
  | 'guardrail_approval_required'
  | 'suspend_failed'
  | 'cancelled'
  | 'lease_lost'
  | 'requeued'
  | 'failed';

export type ConversationStatus = 'idle' | 'processing' | 'awaiting_approval' | 'failed';

export type MessageRole = 'system' | 'user' | 'assistant' | 'tool';

export interface TokenUsage {
  promptTokens: number;
  completionTokens: number;
  totalTokens: number;
}

export interface AttachmentInfo {
  fileUid: number;
  fileName: string;
  fileMimeType: string;
  fileSize: number;
}

export interface PendingCall {
  index: number;
  callId: string;
  name: string;
  arguments: Record<string, unknown>;
}

export interface PendingApproval {
  runUuid: string;
  /**
   * nr-llm recomputes this from the run's live state and refuses a mismatch, so
   * it travels back to `conversations/approval` byte for byte. The client must
   * never derive, normalise or re-encode it.
   */
  turnDigest: string;
  calls: PendingCall[];
}

export interface MessageRow {
  uid: number;
  sequence: number;
  role: MessageRole;
  content: string;
  createdAt: number;
  toolCalls?: unknown[];
  toolCallId?: string;
  attachments?: AttachmentInfo[];
  runUuid?: string;
  tokens?: { prompt: number; completion: number };
}

export interface ConversationSummary {
  uid: number;
  title: string;
  status: ConversationStatus;
  messageCount: number;
  pinned: boolean;
  archived: boolean;
  autoApproveTools: boolean;
  runUuid: string;
  pendingApproval: PendingApproval | Record<string, never>;
  errorMessage: string;
  lastMessageAt: number;
  createdAt: number;
}

export interface ToolDescription {
  name: string;
  mcpName: string | null;
  effect: ToolEffect;
  requiresApproval: boolean;
}

export interface ChatStatus {
  available: boolean;
  issues: string[];
  configuration: {
    identifier: string;
    name: string;
    provider: string;
    model: string;
  } | null;
  tools: ToolDescription[];
  budget: { allowed: boolean; reason: string | null };
  limits: {
    maxMessageLength: number;
    maxIterations: number;
    turnsPerMinute: number;
    turnsRemaining: number;
    maxConversations: number;
    maxActiveConversations: number;
    activeConversations: number;
  };
  suggestions: string[];
  features: { sse: boolean; approvals: boolean; attachments: boolean };
}

// --------------------------------------------------------------- turn events

export interface RunStartedEvent {
  event: 'run.started';
  data: { runUuid: string; userMessageUid: number };
}

export interface LlmStepEvent {
  event: 'step.llm';
  data: {
    round: number;
    content?: string;
    thinking?: string;
    tokens: { prompt: number; completion: number; total: number };
  };
}

export interface ToolCallEvent {
  event: 'step.tool.call';
  data: {
    round: number;
    callId: string;
    name: string;
    arguments: Record<string, unknown>;
    effect: ToolEffect;
  };
}

export interface ToolResultEvent {
  event: 'step.tool.result';
  data: {
    callId: string;
    name: string;
    isError: boolean;
    preview: string;
    durationMs: number;
  };
}

export interface ApprovalRequiredEvent {
  event: 'approval.required';
  data: PendingApproval;
}

export interface MessageFinalEvent {
  event: 'message.final';
  data: { messageUid: number; content: string };
}

export interface RunFinishedEvent {
  event: 'run.finished';
  data: { outcome: RunOutcome; usage: TokenUsage };
}

export interface RunErrorEvent {
  event: 'run.error';
  data: { message: string };
}

export interface PingEvent {
  event: 'ping';
  data: { at: number };
}

export type TurnEvent =
  | RunStartedEvent
  | LlmStepEvent
  | ToolCallEvent
  | ToolResultEvent
  | ApprovalRequiredEvent
  | MessageFinalEvent
  | RunFinishedEvent
  | RunErrorEvent
  | PingEvent;

/**
 * A turn event as it arrives: the payload plus the stream's monotonic `id:`.
 *
 * The id is the stream's, not the event's, and the JSON transport has none —
 * so it is optional here and the reducer treats "no id" as "cannot be a
 * duplicate", which is exactly what a single JSON document guarantees.
 */
export interface SequencedEvent {
  id?: number;
  event: TurnEvent['event'];
  data: unknown;
}

/**
 * The non-streaming transport's body: the turn's result, with the same event
 * list the stream would have produced.
 */
export interface TurnJsonResponse {
  runUuid: string;
  outcome: RunOutcome;
  status: ConversationStatus;
  message: string;
  messages: MessageRow[];
  pendingApproval: PendingApproval | Record<string, never>;
  usage: TokenUsage;
  events: { event: TurnEvent['event']; data: unknown }[];
}
