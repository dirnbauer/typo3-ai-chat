import type {
  AttachmentInfo,
  ConversationSummary,
  MessageRow,
  PendingApproval,
  PendingCall,
  RunOutcome,
  SequencedEvent,
  ToolEffect,
  TokenUsage,
} from '@/state/types';

/**
 * What the thread is doing, and nothing else.
 *
 * The machine is pure on purpose: every transition is a function of the frames
 * that arrived, so a run can be replayed in a test exactly as it happened in a
 * browser. Nothing in here fetches, writes, measures time or touches the DOM.
 *
 * Four states, because four is what a user can act on:
 *
 *   idle ──send──▶ streaming ──run.finished(completed)──▶ idle
 *                     │  │
 *                     │  └─approval.required──▶ awaiting_approval ──decide──▶ streaming
 *                     └─run.error / transport failure──▶ error ──▶ idle (on next send)
 *
 * `awaiting_approval` is not a kind of `streaming`. The difference is who the
 * turn is waiting for, and that is the whole reason the state exists.
 */

export type TurnPhase = 'idle' | 'streaming' | 'awaiting_approval' | 'error';

export interface ToolCallEntry {
  callId: string;
  name: string;
  effect: ToolEffect;
  round: number;
  arguments: Record<string, unknown>;
  /** Undefined until `step.tool.result` arrives for this call. */
  result?: { isError: boolean; preview: string; durationMs: number };
}

export type ThreadItem =
  | { kind: 'message'; key: string; message: MessageRow }
  | { kind: 'tool'; key: string; call: ToolCallEntry }
  | { kind: 'thinking'; key: string; round: number; text: string }
  | { kind: 'notice'; key: string; tone: 'info' | 'error'; text: string };

export interface ThreadState {
  phase: TurnPhase;
  conversation: ConversationSummary | null;
  items: ThreadItem[];
  /** The assistant text accumulating in THIS turn, before `message.final`. */
  draft: string;
  runUuid: string;
  pendingApproval: PendingApproval | null;
  usage: TokenUsage;
  outcome: RunOutcome | null;
  error: string | null;
  /**
   * The highest stream id applied. Frames at or below it have already been
   * folded in — a retried read, a resumed stream or a double dispatch must not
   * append the same tool card twice.
   */
  lastEventId: number;
  /** True from the first frame of a run until it settles, for the shimmer. */
  running: boolean;
}

export type ThreadAction =
  | { type: 'reset'; conversation: ConversationSummary | null; messages: MessageRow[] }
  | { type: 'conversation'; conversation: ConversationSummary }
  | { type: 'send'; content: string; attachments: AttachmentInfo[] }
  | { type: 'event'; event: SequencedEvent }
  | { type: 'transport-error'; message: string }
  | { type: 'cancelled' }
  | { type: 'dismiss-error' };

export const emptyUsage: TokenUsage = { promptTokens: 0, completionTokens: 0, totalTokens: 0 };

export const initialThreadState: ThreadState = {
  phase: 'idle',
  conversation: null,
  items: [],
  draft: '',
  runUuid: '',
  pendingApproval: null,
  usage: emptyUsage,
  outcome: null,
  error: null,
  lastEventId: 0,
  running: false,
};

/**
 * An outcome that leaves the conversation usable again.
 *
 * `awaiting_approval` and `awaiting_input` are the two that do not: the run has
 * stopped, but the turn has not ended and the composer must stay shut.
 */
const SETTLED_OUTCOMES = new Set<RunOutcome>([
  'completed',
  'guardrail_blocked',
  'suspend_failed',
  'cancelled',
  'lease_lost',
  'requeued',
  'failed',
]);

const FAILED_OUTCOMES = new Set<RunOutcome>([
  'guardrail_blocked',
  'suspend_failed',
  'lease_lost',
  'failed',
]);

export function outcomeNotice(outcome: RunOutcome): { tone: 'info' | 'error'; text: string } | null {
  switch (outcome) {
    case 'completed':
    case 'awaiting_approval':
      return null;
    case 'awaiting_input':
      return { tone: 'info', text: 'The run is waiting for more input.' };
    case 'cancelled':
      return { tone: 'info', text: 'Cancelled.' };
    case 'requeued':
      return { tone: 'info', text: 'The run was requeued and will be picked up again.' };
    case 'guardrail_blocked':
      return { tone: 'error', text: 'A guardrail stopped this run.' };
    case 'guardrail_approval_required':
      return { tone: 'error', text: 'A guardrail asked for an approval this client cannot give.' };
    case 'suspend_failed':
      return { tone: 'error', text: 'The run could not be suspended for approval.' };
    case 'lease_lost':
      return { tone: 'error', text: 'The run lost its lease before it finished.' };
    case 'failed':
      return { tone: 'error', text: 'The run failed.' };
    default:
      return null;
  }
}

let syntheticKey = 0;

function nextKey(prefix: string): string {
  syntheticKey += 1;

  return `${prefix}-${syntheticKey}`;
}

/**
 * Reset the key counter. Only tests need this, and only so that a key asserted
 * in one test is not affected by how many ran before it.
 */
export function resetKeyCounter(): void {
  syntheticKey = 0;
}

function messageItem(message: MessageRow): ThreadItem {
  return { kind: 'message', key: `m${message.uid || nextKey('local')}`, message };
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null;
}

function str(value: unknown, fallback = ''): string {
  return typeof value === 'string' ? value : fallback;
}

function num(value: unknown, fallback = 0): number {
  return typeof value === 'number' && Number.isFinite(value) ? value : fallback;
}

function effectOf(value: unknown): ToolEffect {
  return value === 'idempotent_write' || value === 'non_idempotent_write' ? value : 'read_only';
}

function usageOf(value: unknown): TokenUsage {
  if (!isRecord(value)) {
    return emptyUsage;
  }

  return {
    promptTokens: num(value.promptTokens),
    completionTokens: num(value.completionTokens),
    totalTokens: num(value.totalTokens),
  };
}

function pendingApprovalOf(value: unknown): PendingApproval | null {
  if (!isRecord(value) || typeof value.turnDigest !== 'string' || value.turnDigest === '') {
    return null;
  }
  const calls: PendingCall[] = Array.isArray(value.calls)
    ? value.calls.filter(isRecord).map((call, index) => ({
        index: num(call.index, index),
        callId: str(call.callId),
        name: str(call.name),
        arguments: isRecord(call.arguments) ? call.arguments : {},
      }))
    : [];

  return { runUuid: str(value.runUuid), turnDigest: value.turnDigest, calls };
}

/**
 * A conversation row carries its pending approval too, so a tab opened while a
 * run is suspended shows the same card as the tab that triggered it.
 */
export function approvalOfConversation(conversation: ConversationSummary | null): PendingApproval | null {
  return conversation === null ? null : pendingApprovalOf(conversation.pendingApproval);
}

function appendNotice(
  items: ThreadItem[],
  tone: 'info' | 'error',
  text: string,
): ThreadItem[] {
  return [...items, { kind: 'notice', key: nextKey('notice'), tone, text }];
}

/**
 * Fold the accumulated draft into a real assistant message.
 *
 * `message.final` carries the persisted uid and the authoritative text, so it
 * replaces the draft rather than being appended after it — otherwise a stream
 * that also delivered `step.llm` content would show the answer twice.
 */
function commitFinal(state: ThreadState, uid: number, content: string): ThreadState {
  const message: MessageRow = {
    uid,
    sequence: 0,
    role: 'assistant',
    content,
    createdAt: Math.floor(Date.now() / 1000),
  };

  return { ...state, draft: '', items: [...state.items, messageItem(message)] };
}

export function threadReducer(state: ThreadState, action: ThreadAction): ThreadState {
  switch (action.type) {
    case 'reset': {
      return {
        ...initialThreadState,
        conversation: action.conversation,
        items: action.messages
          .filter((message) => message.role === 'user' || message.role === 'assistant')
          .filter((message) => message.content !== '')
          .map(messageItem),
        pendingApproval: approvalOfConversation(action.conversation),
        phase:
          action.conversation?.status === 'awaiting_approval'
            ? 'awaiting_approval'
            : action.conversation?.status === 'failed'
              ? 'error'
              : 'idle',
        error: action.conversation?.errorMessage || null,
        runUuid: action.conversation?.runUuid ?? '',
      };
    }

    case 'conversation':
      return { ...state, conversation: action.conversation };

    case 'send': {
      // The user's own message is shown before the server confirms it. The
      // alternative is a composer that empties into nothing while the first
      // round runs, which reads as a lost message.
      const optimistic: MessageRow = {
        uid: 0,
        sequence: 0,
        role: 'user',
        content: action.content,
        createdAt: Math.floor(Date.now() / 1000),
        ...(action.attachments.length > 0 ? { attachments: action.attachments } : {}),
      };

      return {
        ...state,
        phase: 'streaming',
        running: true,
        draft: '',
        error: null,
        outcome: null,
        pendingApproval: null,
        usage: emptyUsage,
        lastEventId: 0,
        items: [...state.items, messageItem(optimistic)],
      };
    }

    case 'event':
      return applyEvent(state, action.event);

    case 'transport-error':
      return {
        ...state,
        phase: 'error',
        running: false,
        error: action.message,
        draft: '',
      };

    case 'cancelled':
      return {
        ...state,
        phase: 'idle',
        running: false,
        draft: '',
        pendingApproval: null,
        items: appendNotice(state.items, 'info', 'Cancelled.'),
      };

    case 'dismiss-error':
      return { ...state, error: null, phase: state.phase === 'error' ? 'idle' : state.phase };

    default:
      return state;
  }
}

function applyEvent(state: ThreadState, incoming: SequencedEvent): ThreadState {
  // Ids are monotonic within a stream. A frame at or below the high-water mark
  // has been seen — a resumed stream replays, and a React StrictMode double
  // dispatch repeats — and applying it again would duplicate a tool card or
  // double-count tokens.
  if (incoming.id !== undefined) {
    if (incoming.id <= state.lastEventId) {
      return state;
    }
  }

  const advanced =
    incoming.id === undefined ? state : { ...state, lastEventId: incoming.id };
  const data = isRecord(incoming.data) ? incoming.data : {};

  switch (incoming.event) {
    case 'ping':
      // The heartbeat proves the socket is alive and says nothing about the
      // run. It is consumed for its id and otherwise ignored.
      return advanced;

    case 'run.started':
      return {
        ...advanced,
        phase: 'streaming',
        running: true,
        error: null,
        outcome: null,
        runUuid: str(data.runUuid, advanced.runUuid),
      };

    case 'step.llm': {
      const round = num(data.round);
      const content = str(data.content);
      const thinking = str(data.thinking);

      let items = advanced.items;
      if (thinking !== '') {
        items = [...items, { kind: 'thinking', key: nextKey('think'), round, text: thinking }];
      }

      const tokens = isRecord(data.tokens) ? data.tokens : {};

      return {
        ...advanced,
        items,
        draft: content === '' ? advanced.draft : advanced.draft + content,
        usage: {
          promptTokens: advanced.usage.promptTokens + num(tokens.prompt),
          completionTokens: advanced.usage.completionTokens + num(tokens.completion),
          totalTokens: advanced.usage.totalTokens + num(tokens.total),
        },
      };
    }

    case 'step.tool.call': {
      const call: ToolCallEntry = {
        callId: str(data.callId),
        name: str(data.name),
        effect: effectOf(data.effect),
        round: num(data.round),
        arguments: isRecord(data.arguments) ? data.arguments : {},
      };

      // A call id repeated inside one run is the same call, not a second one:
      // the JSON transport replays the whole list after a stream that already
      // delivered part of it.
      if (call.callId !== '' && advanced.items.some(isSameCall(call.callId))) {
        return advanced;
      }

      return {
        ...advanced,
        running: true,
        items: [...advanced.items, { kind: 'tool', key: `call-${call.callId || nextKey('c')}`, call }],
      };
    }

    case 'step.tool.result': {
      const callId = str(data.callId);
      const result = {
        isError: data.isError === true,
        preview: str(data.preview),
        durationMs: num(data.durationMs),
      };

      // Correlation is positional on the server and by id here, with one
      // fallback: a result whose call id never arrived attaches to the newest
      // unanswered call of the same name, which is the same rule the server's
      // recorder uses.
      const index = findCallIndex(advanced.items, callId, str(data.name));
      if (index === -1) {
        return advanced;
      }
      const items = advanced.items.slice();
      const item = items[index];
      if (item === undefined || item.kind !== 'tool') {
        return advanced;
      }
      items[index] = { ...item, call: { ...item.call, result } };

      return { ...advanced, items };
    }

    case 'approval.required': {
      const approval = pendingApprovalOf(data);
      if (approval === null) {
        // A digest-less approval frame cannot be answered — the server refuses
        // a missing digest exactly as it refuses a stale one — so it is
        // reported rather than rendered as a card with a dead button.
        return {
          ...advanced,
          phase: 'error',
          running: false,
          error: 'The run asked for approval without a digest, so it cannot be decided here.',
        };
      }

      return {
        ...advanced,
        phase: 'awaiting_approval',
        running: false,
        pendingApproval: approval,
        runUuid: approval.runUuid !== '' ? approval.runUuid : advanced.runUuid,
        draft: '',
      };
    }

    case 'message.final': {
      const content = str(data.content);
      if (content === '') {
        return { ...advanced, draft: '' };
      }

      return commitFinal(advanced, num(data.messageUid), content);
    }

    case 'run.finished': {
      const outcome = str(data.outcome, 'completed') as RunOutcome;
      const usage = usageOf(data.usage);
      // The server's total is authoritative; the per-step sum was only ever a
      // running estimate for the meter.
      const withUsage = usage.totalTokens > 0 ? usage : advanced.usage;

      // A run that produced prose but no `message.final` (the JSON transport
      // for a suspended run, an outcome that ends mid-sentence) would otherwise
      // lose the draft.
      const flushed =
        advanced.draft !== '' && outcome !== 'awaiting_approval'
          ? commitFinal(advanced, 0, advanced.draft)
          : advanced;

      const settled = SETTLED_OUTCOMES.has(outcome);
      const notice = outcomeNotice(outcome);

      return {
        ...flushed,
        outcome,
        usage: withUsage,
        running: false,
        draft: outcome === 'awaiting_approval' ? flushed.draft : '',
        phase: outcome === 'awaiting_approval' ? 'awaiting_approval' : settled ? (FAILED_OUTCOMES.has(outcome) ? 'error' : 'idle') : flushed.phase,
        error: FAILED_OUTCOMES.has(outcome) ? (notice?.text ?? 'The run failed.') : flushed.error,
        items: notice === null ? flushed.items : appendNotice(flushed.items, notice.tone, notice.text),
      };
    }

    case 'run.error': {
      const message = str(data.message, 'The run failed.');

      return {
        ...advanced,
        phase: 'error',
        running: false,
        draft: '',
        error: message,
        items: appendNotice(advanced.items, 'error', message),
      };
    }

    default:
      // A frame this bundle predates. Its id is consumed so the stream stays in
      // step; nothing else changes.
      return advanced;
  }
}

function isSameCall(callId: string) {
  return (item: ThreadItem): boolean => item.kind === 'tool' && item.call.callId === callId;
}

function findCallIndex(items: ThreadItem[], callId: string, name: string): number {
  if (callId !== '') {
    const byId = items.findIndex(isSameCall(callId));
    if (byId !== -1) {
      return byId;
    }
  }

  for (let index = items.length - 1; index >= 0; index -= 1) {
    const item = items[index];
    if (item !== undefined && item.kind === 'tool' && item.call.name === name && item.call.result === undefined) {
      return index;
    }
  }

  return -1;
}

/**
 * Whether the composer may accept a message right now, and why not if it may
 * not. One function so the button, the textarea and the hint cannot disagree.
 */
export function composerState(
  phase: TurnPhase,
  budgetAllowed: boolean,
  budgetReason: string | null,
  available: boolean,
): { disabled: boolean; reason: string } {
  if (!available) {
    return { disabled: true, reason: 'TYPO3 AI Chat is not configured on this installation.' };
  }
  if (!budgetAllowed) {
    return {
      disabled: true,
      reason: budgetReason ?? 'Your spend budget for this period is used up.',
    };
  }
  if (phase === 'streaming') {
    return { disabled: true, reason: 'A turn is running. Wait for it to finish, or cancel it.' };
  }
  if (phase === 'awaiting_approval') {
    return { disabled: true, reason: 'Decide the pending tool calls before sending another message.' };
  }

  return { disabled: false, reason: '' };
}
