import { ActivityRail } from '@/components/chat/activity-rail';
import { Composer } from '@/components/chat/composer';
import { ConversationSidebar } from '@/components/chat/conversation-sidebar';
import { ErrorBanner } from '@/components/chat/error-banner';
import { Thread } from '@/components/chat/thread';
import { useChat } from '@/state/use-chat';

/**
 * The full module: conversations, the thread, and what the run is doing.
 *
 * Three columns because there are three questions — which conversation, what
 * was said, what it touched — and the module is the surface with room to answer
 * all three at once. Below a laptop width the rails fold away and what is left
 * is the panel's layout, which is the same component tree with less around it.
 */
export function ModuleSurface({ conversationUid }: { conversationUid: number }) {
  const chat = useChat(conversationUid);

  return (
    <div className="wc-root grid h-dvh min-h-0 grid-cols-1 lg:grid-cols-[minmax(14rem,18rem)_minmax(0,1fr)] xl:grid-cols-[minmax(14rem,18rem)_minmax(0,1fr)_minmax(16rem,22rem)]">
      <div className="hidden min-h-0 lg:block">
        <ConversationSidebar
          activeUid={chat.conversationUid}
          conversations={chat.conversations}
          includeArchived={chat.includeArchived}
          onArchive={(uid, archived) => void chat.setArchived(uid, archived)}
          onCreate={() => void chat.startNew()}
          onDelete={(uid) => void chat.remove(uid)}
          onIncludeArchived={chat.setIncludeArchived}
          onPin={(uid, pinned) => void chat.setPinned(uid, pinned)}
          onRename={(uid, title) => void chat.rename(uid, title)}
          onSelect={chat.select}
        />
      </div>

      <main aria-label="Chat" className="flex min-h-0 flex-col bg-background">
        {chat.statusError === null ? null : <ErrorBanner message={chat.statusError} />}
        {chat.thread.error === null ? null : (
          <ErrorBanner message={chat.thread.error} onDismiss={chat.dismissError} />
        )}
        {(chat.status?.issues ?? []).map((issue) => (
          <ErrorBanner key={issue} message={issue} />
        ))}

        <Thread
          busy={chat.busy}
          emptyDescription="Ask a question about this installation, or describe a change. Every write asks for your approval first."
          onDecide={chat.decide}
          thread={chat.thread}
          tools={chat.status?.tools ?? []}
        />

        <Composer
          attachments={chat.attachments}
          busy={chat.busy}
          onAddFiles={chat.addFiles}
          onRemoveFile={chat.removeFile}
          onSend={chat.send}
          onStop={chat.stop}
          phase={chat.thread.phase}
          showSuggestions={chat.thread.items.length === 0}
          status={chat.status}
        />
      </main>

      <div className="hidden min-h-0 xl:block">
        <ActivityRail status={chat.status} thread={chat.thread} />
      </div>
    </div>
  );
}
