import { AlertTriangleIcon, MessageSquareIcon, SparklesIcon } from 'lucide-react';
import {
  Conversation,
  ConversationContent,
  ConversationEmptyState,
  ConversationItem,
} from '@/components/ai/conversation';
import { Reasoning, ReasoningContent, ReasoningTrigger } from '@/components/ai/reasoning';
import { Bubble, BubbleContent } from '@/components/ui/bubble';
import { Marker, MarkerContent, MarkerIcon } from '@/components/ui/marker';
import { Message, MessageContent, MessageHeader } from '@/components/ui/message';
import { Skeleton } from '@/components/ui/skeleton';
import { ApprovalCard } from '@/components/chat/approval-card';
import { AttachmentList } from '@/components/chat/attachment-list';
import { Markdown } from '@/components/chat/markdown';
import { ToolCallCard } from '@/components/chat/tool-call-card';
import { cn, formatTimestamp } from '@/lib/utils';
import type { ThreadState } from '@/state/reducer';
import type { ToolDescription } from '@/state/types';

/**
 * The transcript, shared by both surfaces.
 *
 * It renders exactly what the reducer holds and decides nothing: the panel and
 * the module differ in how much room this gets, never in what it says.
 */
export type ThreadProps = {
  thread: ThreadState;
  tools: ToolDescription[];
  busy: boolean;
  onDecide: (approved: boolean, remember: boolean) => void;
  emptyTitle?: string;
  emptyDescription?: string;
};

export function Thread({
  thread,
  tools,
  busy,
  onDecide,
  emptyTitle = 'Ask about this installation',
  emptyDescription,
}: ThreadProps) {
  const empty = thread.items.length === 0 && thread.draft === '' && thread.pendingApproval === null;

  return (
    <Conversation>
      <ConversationContent>
        {empty ? (
          <ConversationEmptyState
            description={emptyDescription}
            icon={<SparklesIcon className="size-5" />}
            title={emptyTitle}
          />
        ) : null}

        {thread.items.map((item) => {
          switch (item.kind) {
            case 'message': {
              const mine = item.message.role === 'user';

              return (
                <ConversationItem key={item.key} messageId={item.key}>
                  <Message align={mine ? 'end' : 'start'}>
                    <MessageContent>
                      {mine ? null : (
                        <MessageHeader className="px-0">
                          <span>Assistant</span>
                          {item.message.createdAt > 0 ? (
                            <span className="ms-2">{formatTimestamp(item.message.createdAt)}</span>
                          ) : null}
                        </MessageHeader>
                      )}
                      <Bubble align={mine ? 'end' : 'start'} variant={mine ? 'default' : 'ghost'}>
                        <BubbleContent>
                          {mine ? (
                            <p className="whitespace-pre-wrap">{item.message.content}</p>
                          ) : (
                            <Markdown>{item.message.content}</Markdown>
                          )}
                        </BubbleContent>
                      </Bubble>
                      {item.message.attachments === undefined ? null : (
                        <AttachmentList attachments={item.message.attachments} />
                      )}
                    </MessageContent>
                  </Message>
                </ConversationItem>
              );
            }

            case 'tool':
              return (
                <ConversationItem key={item.key} messageId={item.key}>
                  <ToolCallCard call={item.call} />
                </ConversationItem>
              );

            case 'thinking':
              return (
                <ConversationItem key={item.key} messageId={item.key}>
                  <Reasoning>
                    <ReasoningTrigger label={<span>Reasoning · round {item.round}</span>} />
                    <ReasoningContent>{item.text}</ReasoningContent>
                  </Reasoning>
                </ConversationItem>
              );

            case 'notice':
              return (
                <ConversationItem key={item.key} messageId={item.key}>
                  <Marker variant="separator">
                    {item.tone === 'error' ? (
                      <MarkerIcon>
                        <AlertTriangleIcon className="text-destructive" />
                      </MarkerIcon>
                    ) : null}
                    <MarkerContent className={cn(item.tone === 'error' && 'text-destructive')}>
                      {item.text}
                    </MarkerContent>
                  </Marker>
                </ConversationItem>
              );

            default:
              return null;
          }
        })}

        {thread.draft === '' ? null : (
          <ConversationItem key="draft" messageId="draft">
            <Message align="start">
              <MessageContent>
                <Bubble align="start" variant="ghost">
                  <BubbleContent>
                    <Markdown>{thread.draft}</Markdown>
                  </BubbleContent>
                </Bubble>
              </MessageContent>
            </Message>
          </ConversationItem>
        )}

        {/*
          The one row that has to keep moving.
          While a run is in flight there may be nothing else on screen, and a
          static status line is indistinguishable from a wedged request. The
          shimmer is proof of life; `prefers-reduced-motion` turns it into plain
          muted text, which still says the same thing.
        */}
        {thread.running ? (
          <ConversationItem key="running" messageId="running">
            <Marker aria-live="polite" role="status">
              <MarkerIcon>
                <MessageSquareIcon />
              </MarkerIcon>
              <MarkerContent className="shimmer">{runningLabel(thread)}</MarkerContent>
            </Marker>
            {thread.items.length === 0 ? (
              <div className="mt-2 space-y-1.5">
                <Skeleton className="h-3 w-4/5" />
                <Skeleton className="h-3 w-3/5" />
              </div>
            ) : null}
          </ConversationItem>
        ) : null}

        {thread.pendingApproval === null ? null : (
          <ConversationItem key="approval" messageId="approval" scrollAnchor>
            <ApprovalCard
              approval={thread.pendingApproval}
              busy={busy}
              onDecide={onDecide}
              tools={tools}
            />
          </ConversationItem>
        )}
      </ConversationContent>
    </Conversation>
  );
}

function runningLabel(thread: ThreadState): string {
  const lastTool = [...thread.items].reverse().find((item) => item.kind === 'tool');
  if (lastTool !== undefined && lastTool.kind === 'tool' && lastTool.call.result === undefined) {
    return `Running ${lastTool.call.name.replace(/^typo3_/, '')}…`;
  }

  return thread.draft === '' ? 'Thinking…' : 'Writing…';
}
