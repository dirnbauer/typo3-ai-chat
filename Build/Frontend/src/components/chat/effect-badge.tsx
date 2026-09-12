import { EyeIcon, PencilIcon, TriangleAlertIcon } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';
import type { ToolEffect } from '@/state/types';

/**
 * What a tool call will do to this installation.
 *
 * The wording is the runtime's own, not a paraphrase: nr-llm decides whether a
 * call may be retried after a lost lease from exactly this classification, and
 * a badge that reads more comfortably than the gate behaves is worse than no
 * badge at all.
 */
const EFFECTS: Record<
  ToolEffect,
  { label: string; explanation: string; icon: typeof EyeIcon; className: string }
> = {
  read_only: {
    label: 'Reads',
    explanation: 'Looks at this installation without changing anything.',
    icon: EyeIcon,
    className: 'border-border text-muted-foreground',
  },
  idempotent_write: {
    label: 'Writes',
    explanation: 'Changes this installation. Running it again lands on the same result.',
    icon: PencilIcon,
    className: 'border-warning/50 bg-warning/10 text-warning-foreground',
  },
  non_idempotent_write: {
    label: 'Writes once',
    explanation:
      'Changes this installation, and running it again would change it again — a record created twice is two records.',
    icon: TriangleAlertIcon,
    className: 'border-destructive/50 bg-destructive/10 text-destructive',
  },
};

export function EffectBadge({ effect, className }: { effect: ToolEffect; className?: string }) {
  const { label, explanation, icon: Icon, className: tone } = EFFECTS[effect];

  return (
    <Tooltip>
      <TooltipTrigger asChild>
        <Badge
          variant="outline"
          className={cn('gap-1 rounded-full px-1.5 font-normal', tone, className)}
        >
          <Icon className="size-3" aria-hidden="true" />
          {label}
        </Badge>
      </TooltipTrigger>
      <TooltipContent className="max-w-64">{explanation}</TooltipContent>
    </Tooltip>
  );
}

export function effectExplanation(effect: ToolEffect): string {
  return EFFECTS[effect].explanation;
}
