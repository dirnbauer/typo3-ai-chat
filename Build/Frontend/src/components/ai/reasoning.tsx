import { createContext, useCallback, useContext, useMemo, useState } from 'react';
import type { ComponentProps, ReactNode } from 'react';
import { BrainIcon, ChevronDownIcon } from 'lucide-react';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { Shimmer } from '@/components/ai/shimmer';
import { Markdown } from '@/components/chat/markdown';
import { cn } from '@/lib/utils';

/**
 * Vendored from AI Elements, with Streamdown and its plugin set removed.
 *
 * The original renders reasoning through Streamdown with the CJK, code, math
 * and mermaid plugins — which means Shiki and a diagram renderer in the bundle,
 * to display a paragraph the model wrote about what it was about to do. The
 * chat has one markdown pipeline (`react-markdown` with `rehype-sanitize`) and
 * reasoning goes through it like everything else, because a second renderer is
 * a second sanitiser to keep honest.
 *
 * `useControllableState` came from a Radix internal package; the eight lines it
 * provided are below instead.
 */

interface ReasoningContextValue {
  isStreaming: boolean;
  isOpen: boolean;
}

const ReasoningContext = createContext<ReasoningContextValue | null>(null);

function useReasoning(): ReasoningContextValue {
  const context = useContext(ReasoningContext);
  if (context === null) {
    throw new Error('Reasoning components must be used inside <Reasoning>.');
  }

  return context;
}

export type ReasoningProps = Omit<ComponentProps<typeof Collapsible>, 'open' | 'onOpenChange'> & {
  isStreaming?: boolean;
  defaultOpen?: boolean;
};

/**
 * Reasoning opens itself while it streams and closes when the answer arrives —
 * it is context for what the model is doing, and once the answer is there the
 * answer is the thing to read.
 */
export function Reasoning({
  className,
  isStreaming = false,
  defaultOpen = false,
  children,
  ...props
}: ReasoningProps) {
  // Derived, not synchronised. `null` means "the user has not decided", in
  // which case the panel simply follows the run — which is why there is no
  // effect here: an effect that pushed `isStreaming` into state would render
  // twice for every frame of a stream.
  const [choice, setChoice] = useState<boolean | null>(defaultOpen ? true : null);
  const isOpen = choice ?? isStreaming;

  const handleOpenChange = useCallback((open: boolean) => setChoice(open), []);

  const value = useMemo(() => ({ isOpen, isStreaming }), [isOpen, isStreaming]);

  return (
    <ReasoningContext.Provider value={value}>
      <Collapsible
        className={cn('w-full', className)}
        onOpenChange={handleOpenChange}
        open={isOpen}
        {...props}
      >
        {children}
      </Collapsible>
    </ReasoningContext.Provider>
  );
}

export type ReasoningTriggerProps = ComponentProps<typeof CollapsibleTrigger> & {
  label?: ReactNode;
};

export function ReasoningTrigger({ className, label, children, ...props }: ReasoningTriggerProps) {
  const { isOpen, isStreaming } = useReasoning();

  return (
    <CollapsibleTrigger
      className={cn(
        'flex items-center gap-1.5 text-muted-foreground transition-colors hover:text-foreground',
        className,
      )}
      {...props}
    >
      {children ?? (
        <>
          <BrainIcon className="size-3.5" aria-hidden="true" />
          {isStreaming ? <Shimmer>Thinking</Shimmer> : (label ?? <span>Reasoning</span>)}
          <ChevronDownIcon
            className={cn('size-3.5 transition-transform', isOpen && 'rotate-180')}
            aria-hidden="true"
          />
        </>
      )}
    </CollapsibleTrigger>
  );
}

export type ReasoningContentProps = Omit<
  ComponentProps<typeof CollapsibleContent>,
  'children'
> & {
  children: string;
};

export function ReasoningContent({ className, children, ...props }: ReasoningContentProps) {
  return (
    <CollapsibleContent
      className={cn(
        'mt-1.5 border-s ps-2.5 text-muted-foreground data-[state=closed]:animate-out data-[state=open]:animate-in data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0',
        className,
      )}
      {...props}
    >
      <Markdown>{children}</Markdown>
    </CollapsibleContent>
  );
}
