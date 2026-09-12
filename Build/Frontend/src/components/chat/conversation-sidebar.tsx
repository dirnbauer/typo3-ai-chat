import { useEffect, useState } from 'react';
import {
  ArchiveIcon,
  ArchiveRestoreIcon,
  EllipsisVerticalIcon,
  PinIcon,
  PinOffIcon,
  PlusIcon,
  SquarePenIcon,
  Trash2Icon,
} from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { ScrollArea } from '@/components/ui/scroll-area';
import { Textarea } from '@/components/ui/textarea';
import { cn, formatTimestamp } from '@/lib/utils';
import type { ConversationSummary } from '@/state/types';

/**
 * Every conversation this user owns.
 *
 * Pinned first, then newest activity: the two orders an editor actually works
 * in. Archived rows are hidden until asked for, because "archive" is how a
 * conversation gets out of the way and a list that shows it anyway has no
 * archive.
 */
export type ConversationSidebarProps = {
  conversations: ConversationSummary[];
  activeUid: number;
  includeArchived: boolean;
  onSelect: (uid: number) => void;
  onCreate: () => void;
  onRename: (uid: number, title: string) => void;
  onPin: (uid: number, pinned: boolean) => void;
  onArchive: (uid: number, archived: boolean) => void;
  onDelete: (uid: number) => void;
  onIncludeArchived: (include: boolean) => void;
};

export function ConversationSidebar({
  conversations,
  activeUid,
  includeArchived,
  onSelect,
  onCreate,
  onRename,
  onPin,
  onArchive,
  onDelete,
  onIncludeArchived,
}: ConversationSidebarProps) {
  const [renaming, setRenaming] = useState<ConversationSummary | null>(null);
  const [deleting, setDeleting] = useState<ConversationSummary | null>(null);
  const [draftTitle, setDraftTitle] = useState('');

  useEffect(() => {
    setDraftTitle(renaming?.title ?? '');
  }, [renaming]);

  const ordered = [...conversations].sort((a, b) => {
    if (a.pinned !== b.pinned) {
      return a.pinned ? -1 : 1;
    }

    return (b.lastMessageAt || b.createdAt) - (a.lastMessageAt || a.createdAt);
  });

  return (
    <div className="flex h-full min-h-0 flex-col border-e bg-background">
      <div className="flex items-center justify-between gap-2 border-b px-2.5 py-2">
        <h2 className="font-semibold">Conversations</h2>
        <Button aria-label="New conversation" onClick={onCreate} size="icon-sm" variant="ghost">
          <PlusIcon aria-hidden="true" />
        </Button>
      </div>

      <ScrollArea className="min-h-0 flex-1">
        <ul className="p-1.5">
          {ordered.length === 0 ? (
            <li className="px-2 py-6 text-center text-muted-foreground">
              No conversations yet.
            </li>
          ) : null}
          {ordered.map((conversation) => (
            <li key={conversation.uid}>
              <div
                className={cn(
                  'group flex items-start gap-1 rounded-md px-2 py-1.5 transition-colors',
                  conversation.uid === activeUid ? 'bg-accent' : 'hover:bg-accent/60',
                )}
              >
                <button
                  aria-current={conversation.uid === activeUid ? 'true' : undefined}
                  className="min-w-0 flex-1 text-start"
                  onClick={() => onSelect(conversation.uid)}
                  type="button"
                >
                  <span className="flex items-center gap-1.5">
                    {conversation.pinned ? (
                      <PinIcon className="size-3 shrink-0 text-muted-foreground" aria-label="Pinned" />
                    ) : null}
                    <span className="truncate font-medium">
                      {conversation.title || 'Untitled conversation'}
                    </span>
                  </span>
                  <span className="mt-0.5 flex items-center gap-1.5 text-muted-foreground">
                    <span>{formatTimestamp(conversation.lastMessageAt || conversation.createdAt)}</span>
                    <span aria-hidden="true">·</span>
                    <span>
                      {conversation.messageCount} {conversation.messageCount === 1 ? 'message' : 'messages'}
                    </span>
                    <StatusBadge status={conversation.status} />
                    {conversation.archived ? (
                      <Badge className="rounded-full px-1.5 font-normal" variant="outline">
                        Archived
                      </Badge>
                    ) : null}
                  </span>
                </button>

                <DropdownMenu>
                  <DropdownMenuTrigger asChild>
                    <Button
                      aria-label={`Actions for ${conversation.title || 'this conversation'}`}
                      className="opacity-0 transition-opacity focus-visible:opacity-100 group-hover:opacity-100"
                      size="icon-sm"
                      variant="ghost"
                    >
                      <EllipsisVerticalIcon aria-hidden="true" />
                    </Button>
                  </DropdownMenuTrigger>
                  <DropdownMenuContent align="end">
                    <DropdownMenuItem onSelect={() => setRenaming(conversation)}>
                      <SquarePenIcon aria-hidden="true" />
                      Rename
                    </DropdownMenuItem>
                    <DropdownMenuItem onSelect={() => onPin(conversation.uid, !conversation.pinned)}>
                      {conversation.pinned ? (
                        <PinOffIcon aria-hidden="true" />
                      ) : (
                        <PinIcon aria-hidden="true" />
                      )}
                      {conversation.pinned ? 'Unpin' : 'Pin'}
                    </DropdownMenuItem>
                    <DropdownMenuItem
                      onSelect={() => onArchive(conversation.uid, !conversation.archived)}
                    >
                      {conversation.archived ? (
                        <ArchiveRestoreIcon aria-hidden="true" />
                      ) : (
                        <ArchiveIcon aria-hidden="true" />
                      )}
                      {conversation.archived ? 'Restore' : 'Archive'}
                    </DropdownMenuItem>
                    <DropdownMenuSeparator />
                    <DropdownMenuItem variant="destructive" onSelect={() => setDeleting(conversation)}>
                      <Trash2Icon aria-hidden="true" />
                      Delete
                    </DropdownMenuItem>
                  </DropdownMenuContent>
                </DropdownMenu>
              </div>
            </li>
          ))}
        </ul>
      </ScrollArea>

      <div className="border-t px-2.5 py-2">
        <label className="flex items-center gap-2 text-muted-foreground">
          <input
            checked={includeArchived}
            className="size-3.5 accent-[var(--primary)]"
            onChange={(event) => onIncludeArchived(event.currentTarget.checked)}
            type="checkbox"
          />
          Show archived
        </label>
      </div>

      <Dialog onOpenChange={(open) => !open && setRenaming(null)} open={renaming !== null}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Rename conversation</DialogTitle>
            <DialogDescription>
              The title is how you will find this conversation again.
            </DialogDescription>
          </DialogHeader>
          <Textarea
            aria-label="Conversation title"
            className="min-h-9 resize-none"
            maxLength={255}
            onChange={(event) => setDraftTitle(event.currentTarget.value)}
            onKeyDown={(event) => {
              if (event.key === 'Enter' && !event.shiftKey) {
                event.preventDefault();
                commitRename();
              }
            }}
            value={draftTitle}
          />
          <DialogFooter>
            <Button onClick={() => setRenaming(null)} variant="outline">
              Cancel
            </Button>
            <Button disabled={draftTitle.trim() === ''} onClick={commitRename}>
              Rename
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <Dialog onOpenChange={(open) => !open && setDeleting(null)} open={deleting !== null}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Delete this conversation?</DialogTitle>
            <DialogDescription>
              “{deleting?.title || 'Untitled conversation'}” and its messages are removed from your
              list now, and purged for good when retention passes. This cannot be undone here.
            </DialogDescription>
          </DialogHeader>
          <DialogFooter>
            <Button onClick={() => setDeleting(null)} variant="outline">
              Cancel
            </Button>
            <Button
              onClick={() => {
                if (deleting !== null) {
                  onDelete(deleting.uid);
                }
                setDeleting(null);
              }}
              variant="destructive"
            >
              Delete
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );

  function commitRename(): void {
    const title = draftTitle.trim();
    if (renaming === null || title === '') {
      return;
    }
    onRename(renaming.uid, title);
    setRenaming(null);
  }
}

function StatusBadge({ status }: { status: ConversationSummary['status'] }) {
  if (status === 'idle') {
    return null;
  }

  const label =
    status === 'processing' ? 'Running' : status === 'awaiting_approval' ? 'Needs approval' : 'Failed';

  return (
    <Badge
      className="rounded-full px-1.5 font-normal"
      variant={status === 'failed' ? 'destructive' : 'secondary'}
    >
      {label}
    </Badge>
  );
}
