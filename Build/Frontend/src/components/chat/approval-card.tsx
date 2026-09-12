import { useId, useState } from 'react';
import { ShieldQuestionMarkIcon } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { Separator } from '@/components/ui/separator';
import { EffectBadge } from '@/components/chat/effect-badge';
import { cn, toolDisplayName } from '@/lib/utils';
import type { PendingApproval, ToolEffect, ToolDescription } from '@/state/types';

/**
 * The decision.
 *
 * Everything about this card is shaped by one property of the protocol: the
 * `turnDigest` travels back UNCHANGED. nr-llm recomputes it from the run's live
 * state and refuses a mismatch, which is what stops a tab left open since
 * yesterday from authorising calls it is no longer showing. So the digest is
 * carried through this component as an opaque string — never trimmed, never
 * re-encoded, never rebuilt from the calls — and a missing one is caught
 * upstream in the reducer rather than sent and rejected with a 409.
 *
 * The calls are listed in full and expanded arguments are one click away,
 * because "approve" here means "write to this installation" and a summary the
 * user cannot check is a summary they will learn to click past.
 */
export type ApprovalCardProps = {
  approval: PendingApproval;
  tools: ToolDescription[];
  busy: boolean;
  /** `remember` asks for this conversation's auto-approve flag to be set. */
  onDecide: (approved: boolean, remember: boolean) => void;
};

export function ApprovalCard({ approval, tools, busy, onDecide }: ApprovalCardProps) {
  const [remember, setRemember] = useState(false);
  const rememberId = useId();
  const headingId = useId();

  const effectOf = (name: string): ToolEffect =>
    tools.find((tool) => tool.name === name)?.effect ?? 'non_idempotent_write';

  const plural = approval.calls.length === 1 ? 'call' : 'calls';

  return (
    <section
      aria-labelledby={headingId}
      className="wc-enter rounded-md border border-warning/50 bg-warning/5"
    >
      <header className="flex items-start gap-2 px-3 py-2.5">
        <ShieldQuestionMarkIcon className="mt-0.5 size-4 shrink-0 text-warning" aria-hidden="true" />
        <div className="min-w-0">
          <h3 className="font-semibold" id={headingId}>
            {approval.calls.length} tool {plural} need your approval
          </h3>
          <p className="mt-0.5 text-muted-foreground">
            The run is paused until you decide. Nothing has been written yet.
          </p>
        </div>
      </header>

      <Separator />

      <ul className="divide-y">
        {approval.calls.map((call) => {
          const effect = effectOf(call.name);

          return (
            <li className={cn('effect-spine px-3 py-2', `effect-${effect}`)} key={`${call.index}-${call.callId}`}>
              <Collapsible>
                <div className="flex items-center justify-between gap-2">
                  <span className="flex min-w-0 items-center gap-2">
                    <span className="truncate font-mono font-medium">{toolDisplayName(call.name)}</span>
                    <EffectBadge effect={effect} />
                  </span>
                  <CollapsibleTrigger asChild>
                    <Button size="sm" variant="ghost">
                      Arguments
                    </Button>
                  </CollapsibleTrigger>
                </div>
                <CollapsibleContent>
                  <pre className="mt-1.5 max-h-48 overflow-auto rounded-sm bg-muted px-2 py-1.5 font-mono text-[0.6875rem] leading-relaxed">
                    {JSON.stringify(call.arguments, null, 2)}
                  </pre>
                </CollapsibleContent>
              </Collapsible>
            </li>
          );
        })}
      </ul>

      <Separator />

      <div className="flex flex-wrap items-center justify-between gap-2 px-3 py-2.5">
        <label className="flex items-center gap-2 text-muted-foreground" htmlFor={rememberId}>
          <input
            checked={remember}
            className="size-3.5 accent-[var(--primary)]"
            disabled={busy}
            id={rememberId}
            onChange={(event) => setRemember(event.currentTarget.checked)}
            type="checkbox"
          />
          Remember these tools for this conversation
        </label>
        <div className="flex items-center gap-2">
          <Button disabled={busy} onClick={() => onDecide(false, false)} size="sm" variant="outline">
            Deny
          </Button>
          <Button disabled={busy} onClick={() => onDecide(true, remember)} size="sm">
            Approve {approval.calls.length === 1 ? '' : `all ${approval.calls.length}`}
          </Button>
        </div>
      </div>
    </section>
  );
}
