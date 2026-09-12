import type { ComponentProps, ReactNode } from 'react';
import {
  CheckCircleIcon,
  ChevronDownIcon,
  ClockIcon,
  ShieldQuestionMarkIcon,
  WrenchIcon,
  XCircleIcon,
} from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { cn } from '@/lib/utils';

/**
 * Vendored from AI Elements, with the `ai` SDK removed.
 *
 * The original types its state as `ToolUIPart["state"]` from the Vercel AI SDK.
 * This chat's transport is the SSE contract in `Documentation/Developer/Api.rst`
 * and its states come from there, so the union is declared here instead — which
 * also means the states are the ones this backend can actually produce rather
 * than the seven the SDK defines.
 *
 * `CodeBlock` is gone with it: it pulled Shiki and three Streamdown plugins to
 * pretty-print a JSON argument list, which is several hundred kilobytes for
 * something `JSON.stringify(value, null, 2)` in a `<pre>` already says.
 */

export type ToolState =
  | 'pending'
  | 'running'
  | 'awaiting-approval'
  | 'denied'
  | 'completed'
  | 'error';

const STATE_LABELS: Record<ToolState, string> = {
  pending: 'Queued',
  running: 'Running',
  'awaiting-approval': 'Needs approval',
  denied: 'Denied',
  completed: 'Done',
  error: 'Failed',
};

const STATE_ICONS: Record<ToolState, ReactNode> = {
  pending: <ClockIcon className="size-3" />,
  running: <ClockIcon className="size-3 animate-pulse" />,
  'awaiting-approval': <ShieldQuestionMarkIcon className="size-3" />,
  denied: <XCircleIcon className="size-3" />,
  completed: <CheckCircleIcon className="size-3" />,
  error: <XCircleIcon className="size-3" />,
};

const STATE_VARIANTS: Record<ToolState, 'secondary' | 'destructive' | 'outline'> = {
  pending: 'outline',
  running: 'secondary',
  'awaiting-approval': 'secondary',
  denied: 'outline',
  completed: 'outline',
  error: 'destructive',
};

export function ToolStateBadge({ state }: { state: ToolState }) {
  return (
    <Badge className="gap-1 rounded-full px-1.5 font-normal" variant={STATE_VARIANTS[state]}>
      {STATE_ICONS[state]}
      {STATE_LABELS[state]}
    </Badge>
  );
}

export type ToolProps = ComponentProps<typeof Collapsible>;

export function Tool({ className, ...props }: ToolProps) {
  return (
    <Collapsible
      className={cn('group w-full overflow-hidden rounded-md border bg-card', className)}
      {...props}
    />
  );
}

export type ToolHeaderProps = ComponentProps<typeof CollapsibleTrigger> & {
  title: string;
  state: ToolState;
  /** The effect badge and anything else the caller wants beside the name. */
  meta?: ReactNode;
};

export function ToolHeader({ className, title, state, meta, ...props }: ToolHeaderProps) {
  return (
    <CollapsibleTrigger
      className={cn(
        'flex w-full items-center justify-between gap-2 px-2.5 py-2 text-start transition-colors hover:bg-accent/60',
        className,
      )}
      {...props}
    >
      <span className="flex min-w-0 flex-1 items-center gap-2">
        <WrenchIcon className="size-3.5 shrink-0 text-muted-foreground" aria-hidden="true" />
        <span className="truncate font-medium">{title}</span>
        {meta}
      </span>
      <span className="flex shrink-0 items-center gap-1.5">
        <ToolStateBadge state={state} />
        <ChevronDownIcon
          className="size-3.5 text-muted-foreground transition-transform group-data-[state=open]:rotate-180"
          aria-hidden="true"
        />
      </span>
    </CollapsibleTrigger>
  );
}

export type ToolContentProps = ComponentProps<typeof CollapsibleContent>;

export function ToolContent({ className, ...props }: ToolContentProps) {
  return (
    <CollapsibleContent
      className={cn(
        'space-y-2 border-t px-2.5 py-2 data-[state=closed]:animate-out data-[state=open]:animate-in data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0',
        className,
      )}
      {...props}
    />
  );
}

function Section({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div className="space-y-1">
      <h4 className="font-medium text-[0.6875rem] uppercase tracking-wide text-muted-foreground">
        {label}
      </h4>
      {children}
    </div>
  );
}

export type ToolInputProps = {
  input: unknown;
  label?: string;
};

export function ToolInput({ input, label = 'Arguments' }: ToolInputProps) {
  const text = stringify(input);

  return (
    <Section label={label}>
      {text === '' ? (
        <p className="text-muted-foreground">No arguments.</p>
      ) : (
        <pre className="max-h-56 overflow-auto rounded-sm bg-muted px-2 py-1.5 font-mono text-[0.6875rem] leading-relaxed">
          {text}
        </pre>
      )}
    </Section>
  );
}

export type ToolOutputProps = {
  output: string;
  isError: boolean;
  label?: string;
};

export function ToolOutput({ output, isError, label }: ToolOutputProps) {
  if (output === '') {
    return null;
  }

  return (
    <Section label={label ?? (isError ? 'Error' : 'Result preview')}>
      <pre
        className={cn(
          'max-h-56 overflow-auto whitespace-pre-wrap rounded-sm px-2 py-1.5 font-mono text-[0.6875rem] leading-relaxed',
          isError ? 'bg-destructive/10 text-destructive' : 'bg-muted text-foreground',
        )}
      >
        {output}
      </pre>
    </Section>
  );
}

function stringify(value: unknown): string {
  if (value === undefined || value === null) {
    return '';
  }
  if (typeof value === 'string') {
    return value;
  }
  try {
    const text = JSON.stringify(value, null, 2);

    return text === undefined || text === '{}' ? '' : text;
  } catch {
    return String(value);
  }
}
