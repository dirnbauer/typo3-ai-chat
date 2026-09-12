import { beforeEach, describe, expect, it } from 'vitest';
import {
  composerState,
  initialThreadState,
  resetKeyCounter,
  threadReducer,
  type ThreadState,
} from '@/state/reducer';
import type { ConversationSummary, SequencedEvent } from '@/state/types';

/**
 * The turn machine, one frame type at a time.
 *
 * The reducer is the only place where "what the server said" becomes "what the
 * user sees", and it is pure — so every case here is the real thing rather than
 * a mock of it.
 */

let sequence = 0;

function event(name: SequencedEvent['event'], data: unknown, id?: number): SequencedEvent {
  sequence = id ?? sequence + 1;

  return { id: sequence, event: name, data };
}

function apply(state: ThreadState, ...events: SequencedEvent[]): ThreadState {
  return events.reduce((current, next) => threadReducer(current, { type: 'event', event: next }), state);
}

function conversation(overrides: Partial<ConversationSummary> = {}): ConversationSummary {
  return {
    uid: 12,
    title: 'Test',
    status: 'idle',
    messageCount: 0,
    pinned: false,
    archived: false,
    autoApproveTools: false,
    runUuid: '',
    pendingApproval: {},
    errorMessage: '',
    lastMessageAt: 0,
    createdAt: 0,
    ...overrides,
  };
}

beforeEach(() => {
  sequence = 0;
  resetKeyCounter();
});

describe('threadReducer', () => {
  it('starts idle with nothing in it', () => {
    expect(initialThreadState.phase).toBe('idle');
    expect(initialThreadState.items).toEqual([]);
  });

  it('shows the user message before the server confirms it', () => {
    const state = threadReducer(initialThreadState, {
      type: 'send',
      content: 'Which pages mention the old name?',
      attachments: [],
    });

    expect(state.phase).toBe('streaming');
    expect(state.running).toBe(true);
    expect(state.items).toHaveLength(1);
    expect(state.items[0]).toMatchObject({ kind: 'message' });
  });

  it('ignores the heartbeat but keeps its place in the stream', () => {
    const state = apply(initialThreadState, event('ping', { at: 1 }, 7));

    expect(state.items).toEqual([]);
    expect(state.lastEventId).toBe(7);
    expect(state.phase).toBe('idle');
  });

  it('accumulates step.llm content into a draft and sums the tokens', () => {
    const state = apply(
      initialThreadState,
      event('run.started', { runUuid: 'r1', userMessageUid: 4 }),
      event('step.llm', { round: 1, content: 'Looking', tokens: { prompt: 10, completion: 2, total: 12 } }),
      event('step.llm', { round: 2, content: ' now.', tokens: { prompt: 5, completion: 3, total: 8 } }),
    );

    expect(state.draft).toBe('Looking now.');
    expect(state.usage).toEqual({ promptTokens: 15, completionTokens: 5, totalTokens: 20 });
    expect(state.runUuid).toBe('r1');
  });

  it('renders thinking as its own row rather than as answer text', () => {
    const state = apply(
      initialThreadState,
      event('step.llm', { round: 1, thinking: 'I should search first.', tokens: {} }),
    );

    expect(state.draft).toBe('');
    expect(state.items[0]).toMatchObject({ kind: 'thinking', round: 1, text: 'I should search first.' });
  });

  it('opens a tool card on the call and completes it on the result', () => {
    let state = apply(
      initialThreadState,
      event('step.tool.call', {
        round: 1,
        callId: 'c1',
        name: 'typo3_Search',
        arguments: { term: 'old name' },
        effect: 'read_only',
      }),
    );

    expect(state.items).toHaveLength(1);
    expect(state.items[0]).toMatchObject({ kind: 'tool' });

    state = apply(
      state,
      event('step.tool.result', {
        callId: 'c1',
        name: 'typo3_Search',
        isError: false,
        preview: '3 pages',
        durationMs: 41.5,
      }),
    );

    const item = state.items[0];
    expect(item?.kind).toBe('tool');
    if (item?.kind === 'tool') {
      expect(item.call.result).toEqual({ isError: false, preview: '3 pages', durationMs: 41.5 });
    }
  });

  it('treats an unknown effect as read-only rather than guessing worse', () => {
    const state = apply(
      initialThreadState,
      event('step.tool.call', { round: 1, callId: 'c1', name: 't', arguments: {}, effect: 'something-new' }),
    );

    const item = state.items[0];
    expect(item?.kind === 'tool' && item.call.effect).toBe('read_only');
  });

  it('attaches a result with no call id to the newest unanswered call of that name', () => {
    const state = apply(
      initialThreadState,
      event('step.tool.call', { round: 1, callId: 'a', name: 'typo3_ReadTable', arguments: {}, effect: 'read_only' }),
      event('step.tool.call', { round: 1, callId: 'b', name: 'typo3_ReadTable', arguments: {}, effect: 'read_only' }),
      event('step.tool.result', { callId: '', name: 'typo3_ReadTable', isError: false, preview: 'x', durationMs: 1 }),
    );

    const [first, second] = state.items;
    expect(first?.kind === 'tool' && first.call.result).toBeUndefined();
    expect(second?.kind === 'tool' && second.call.result).toMatchObject({ preview: 'x' });
  });

  it('drops a frame it has already applied', () => {
    const first = apply(
      initialThreadState,
      event('step.tool.call', { round: 1, callId: 'c1', name: 't', arguments: {}, effect: 'read_only' }, 4),
    );
    const again = threadReducer(first, {
      type: 'event',
      event: { id: 4, event: 'step.tool.call', data: { round: 1, callId: 'c1', name: 't', arguments: {}, effect: 'read_only' } },
    });

    expect(again).toBe(first);
    expect(again.items).toHaveLength(1);
  });

  it('drops a frame that arrives out of order behind the high-water mark', () => {
    const state = apply(
      initialThreadState,
      event('step.llm', { round: 2, content: 'second', tokens: {} }, 9),
      event('step.llm', { round: 1, content: 'first', tokens: {} }, 3),
    );

    expect(state.draft).toBe('second');
    expect(state.lastEventId).toBe(9);
  });

  it('does not open a second card for a call id the JSON transport replays', () => {
    const call = { round: 1, callId: 'c1', name: 't', arguments: {}, effect: 'read_only' };
    const state = threadReducer(
      threadReducer(initialThreadState, { type: 'event', event: { event: 'step.tool.call', data: call } }),
      { type: 'event', event: { event: 'step.tool.call', data: call } },
    );

    expect(state.items).toHaveLength(1);
  });

  it('replaces the draft with the persisted final message', () => {
    const state = apply(
      initialThreadState,
      event('step.llm', { round: 1, content: 'Three pages', tokens: {} }),
      event('message.final', { messageUid: 88, content: 'Three pages mention it.' }),
    );

    expect(state.draft).toBe('');
    expect(state.items).toHaveLength(1);
    const item = state.items[0];
    expect(item?.kind === 'message' && item.message.content).toBe('Three pages mention it.');
  });

  it('returns to idle when the run completes, with the server total winning', () => {
    const state = apply(
      initialThreadState,
      event('step.llm', { round: 1, content: 'x', tokens: { prompt: 1, completion: 1, total: 2 } }),
      event('message.final', { messageUid: 1, content: 'x' }),
      event('run.finished', {
        outcome: 'completed',
        usage: { promptTokens: 120, completionTokens: 30, totalTokens: 150 },
      }),
    );

    expect(state.phase).toBe('idle');
    expect(state.running).toBe(false);
    expect(state.usage.totalTokens).toBe(150);
    expect(state.outcome).toBe('completed');
  });

  it('flushes a draft that run.finished ended without a message.final', () => {
    const state = apply(
      initialThreadState,
      event('step.llm', { round: 1, content: 'half an answ', tokens: {} }),
      event('run.finished', { outcome: 'cancelled', usage: {} }),
    );

    const message = state.items.find((item) => item.kind === 'message');
    expect(message?.kind === 'message' && message.message.content).toBe('half an answ');
    expect(state.draft).toBe('');
  });

  it('moves to awaiting_approval and holds the digest untouched', () => {
    const digest = 'A1b2C3==/+trailing';
    const state = apply(
      initialThreadState,
      event('approval.required', {
        runUuid: 'r9',
        turnDigest: digest,
        calls: [{ index: 0, callId: 'c1', name: 'typo3_WriteTable', arguments: { table: 'pages' } }],
      }),
    );

    expect(state.phase).toBe('awaiting_approval');
    expect(state.running).toBe(false);
    expect(state.pendingApproval?.turnDigest).toBe(digest);
    expect(state.pendingApproval?.calls).toHaveLength(1);
  });

  it('stays awaiting_approval when run.finished reports that outcome', () => {
    const state = apply(
      initialThreadState,
      event('approval.required', { runUuid: 'r9', turnDigest: 'd', calls: [] }),
      event('run.finished', { outcome: 'awaiting_approval', usage: {} }),
    );

    expect(state.phase).toBe('awaiting_approval');
  });

  it('refuses an approval frame with no digest instead of drawing a dead button', () => {
    const state = apply(
      initialThreadState,
      event('approval.required', { runUuid: 'r9', calls: [{ index: 0, callId: 'c', name: 'x', arguments: {} }] }),
    );

    expect(state.phase).toBe('error');
    expect(state.pendingApproval).toBeNull();
    expect(state.error).toMatch(/digest/i);
  });

  it('reports run.error in the thread and in the banner', () => {
    const state = apply(initialThreadState, event('run.error', { message: 'The provider refused.' }));

    expect(state.phase).toBe('error');
    expect(state.error).toBe('The provider refused.');
    expect(state.items.at(-1)).toMatchObject({ kind: 'notice', tone: 'error' });
  });

  it('treats a failing outcome as an error even without run.error', () => {
    const state = apply(initialThreadState, event('run.finished', { outcome: 'lease_lost', usage: {} }));

    expect(state.phase).toBe('error');
    expect(state.error).toMatch(/lease/i);
  });

  it('recovers: a new send clears the previous error', () => {
    const failed = apply(initialThreadState, event('run.error', { message: 'boom' }));
    const next = threadReducer(failed, { type: 'send', content: 'try again', attachments: [] });

    expect(next.phase).toBe('streaming');
    expect(next.error).toBeNull();
    expect(next.lastEventId).toBe(0);
  });

  it('dismissing an error leaves the transcript alone', () => {
    const failed = apply(initialThreadState, event('run.error', { message: 'boom' }));
    const next = threadReducer(failed, { type: 'dismiss-error' });

    expect(next.error).toBeNull();
    expect(next.phase).toBe('idle');
    expect(next.items).toHaveLength(failed.items.length);
  });

  it('keeps its place in the stream for a frame it does not understand', () => {
    const state = threadReducer(initialThreadState, {
      type: 'event',
      event: { id: 5, event: 'step.something.new' as never, data: {} },
    });

    expect(state.lastEventId).toBe(5);
    expect(state.items).toEqual([]);
  });

  it('reset adopts a suspended conversation as awaiting_approval', () => {
    const state = threadReducer(initialThreadState, {
      type: 'reset',
      conversation: conversation({
        status: 'awaiting_approval',
        pendingApproval: { runUuid: 'r', turnDigest: 'd', calls: [] },
      }),
      messages: [],
    });

    expect(state.phase).toBe('awaiting_approval');
    expect(state.pendingApproval?.turnDigest).toBe('d');
  });

  it('reset keeps only the messages a reader can see', () => {
    const state = threadReducer(initialThreadState, {
      type: 'reset',
      conversation: conversation(),
      messages: [
        { uid: 1, sequence: 1, role: 'system', content: 'context', createdAt: 1 },
        { uid: 2, sequence: 2, role: 'user', content: 'hello', createdAt: 2 },
        { uid: 3, sequence: 3, role: 'tool', content: '{}', createdAt: 3 },
        { uid: 4, sequence: 4, role: 'assistant', content: '', createdAt: 4 },
        { uid: 5, sequence: 5, role: 'assistant', content: 'hi', createdAt: 5 },
      ],
    });

    expect(state.items).toHaveLength(2);
  });

  it('cancelling ends the run and says so in the thread', () => {
    const running = threadReducer(initialThreadState, { type: 'send', content: 'x', attachments: [] });
    const state = threadReducer(running, { type: 'cancelled' });

    expect(state.phase).toBe('idle');
    expect(state.running).toBe(false);
    expect(state.items.at(-1)).toMatchObject({ kind: 'notice', text: 'Cancelled.' });
  });
});

describe('composerState', () => {
  it('is open when idle, in budget and configured', () => {
    expect(composerState('idle', true, null, true)).toEqual({ disabled: false, reason: '' });
  });

  it('closes with the budget reason when the budget is exhausted', () => {
    const state = composerState('idle', false, 'Daily cap of 5 EUR reached.', true);

    expect(state.disabled).toBe(true);
    expect(state.reason).toBe('Daily cap of 5 EUR reached.');
  });

  it('closes while a run is in flight', () => {
    expect(composerState('streaming', true, null, true).disabled).toBe(true);
  });

  it('closes while a decision is outstanding, and says which', () => {
    expect(composerState('awaiting_approval', true, null, true).reason).toMatch(/approval|decide/i);
  });

  it('closes first of all when the chat is not configured', () => {
    expect(composerState('idle', false, 'budget', false).reason).toMatch(/not configured/i);
  });
});
