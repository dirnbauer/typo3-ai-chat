import { Tool, ToolContent, ToolHeader, ToolInput, ToolOutput, type ToolState } from '@/components/ai/tool';
import { EffectBadge } from '@/components/chat/effect-badge';
import { cn, formatDuration, toolDisplayName } from '@/lib/utils';
import type { ToolCallEntry } from '@/state/reducer';

/**
 * One tool call, as it happens and after it happened.
 *
 * Collapsed by default: the interesting fact is the name and what the call will
 * do, and an expanded argument list for every read would bury the answer the
 * user asked for. The result is a PREVIEW — the server sends at most 280
 * characters on purpose, because the full payload is already in the model's
 * context and in nr-llm's persisted run.
 */
export function ToolCallCard({ call }: { call: ToolCallEntry }) {
  const state: ToolState = call.result === undefined ? 'running' : call.result.isError ? 'error' : 'completed';
  const duration = call.result === undefined ? '' : formatDuration(call.result.durationMs);

  return (
    <Tool className={cn('effect-spine', `effect-${call.effect}`)}>
      <ToolHeader
        state={state}
        title={toolDisplayName(call.name)}
        meta={<EffectBadge effect={call.effect} />}
      />
      <ToolContent>
        <ToolInput input={call.arguments} />
        {call.result === undefined ? null : (
          <>
            <ToolOutput isError={call.result.isError} output={call.result.preview} />
            {duration === '' ? null : (
              <p className="text-muted-foreground">
                Round {call.round} · {duration}
              </p>
            )}
          </>
        )}
      </ToolContent>
    </Tool>
  );
}
