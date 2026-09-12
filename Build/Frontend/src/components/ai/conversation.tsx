import type { ComponentProps, ReactNode } from 'react';
import {
  MessageScroller,
  MessageScrollerButton,
  MessageScrollerContent,
  MessageScrollerItem,
  MessageScrollerProvider,
  MessageScrollerViewport,
} from '@/components/ui/message-scroller';
import { cn } from '@/lib/utils';

/**
 * Vendored from AI Elements, rebuilt on the shadcn message scroller.
 *
 * The original sits on `use-stick-to-bottom`. This bundle already carries the
 * `message-scroller` primitive, which does the same job — keep the newest
 * message pinned while the user is at the bottom, and stop fighting them the
 * moment they scroll up — and shipping two scroll engines in one thread is how
 * they end up disagreeing about where the bottom is. The `ai`-SDK `UIMessage`
 * types went with the download button that used them: this client's transcript
 * is the API's, not the SDK's.
 */

export type ConversationProps = ComponentProps<typeof MessageScroller> & {
  /** Announced as a log region, because a transcript is one. */
  label?: string;
};

export function Conversation({
  className,
  label = 'Chat transcript',
  children,
  ...props
}: ConversationProps) {
  return (
    <MessageScrollerProvider autoScroll defaultScrollPosition="end">
      <MessageScroller className={cn('relative min-h-0 flex-1', className)} {...props}>
        <MessageScrollerViewport aria-label={label} role="log" className="px-3 py-3">
          {children}
        </MessageScrollerViewport>
        <MessageScrollerButton direction="end" />
      </MessageScroller>
    </MessageScrollerProvider>
  );
}

export type ConversationContentProps = ComponentProps<typeof MessageScrollerContent>;

export function ConversationContent({ className, ...props }: ConversationContentProps) {
  return <MessageScrollerContent className={cn('gap-4', className)} {...props} />;
}

export type ConversationItemProps = ComponentProps<typeof MessageScrollerItem>;

export function ConversationItem({ className, ...props }: ConversationItemProps) {
  return <MessageScrollerItem className={cn('wc-enter', className)} {...props} />;
}

export type ConversationEmptyStateProps = ComponentProps<'div'> & {
  title?: string;
  description?: string;
  icon?: ReactNode;
};

export function ConversationEmptyState({
  className,
  title = 'Nothing here yet',
  description,
  icon,
  children,
  ...props
}: ConversationEmptyStateProps) {
  return (
    <div
      className={cn(
        'flex flex-col items-center justify-center gap-2 px-6 py-10 text-center',
        className,
      )}
      {...props}
    >
      {children ?? (
        <>
          {icon ? <div className="text-muted-foreground">{icon}</div> : null}
          <p className="font-medium text-foreground">{title}</p>
          {description ? <p className="max-w-sm text-muted-foreground">{description}</p> : null}
        </>
      )}
    </div>
  );
}
