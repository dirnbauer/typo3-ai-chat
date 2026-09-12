import { useCallback, useEffect, useRef, useState } from 'react';
import { MaximizeIcon, PlusIcon, XIcon } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Composer } from '@/components/chat/composer';
import { ErrorBanner } from '@/components/chat/error-banner';
import { Thread } from '@/components/chat/thread';
import { openModule } from '@/lib/api';
import { readNumber, writeSetting } from '@/lib/storage';
import { cn } from '@/lib/utils';
import { useChat } from '@/state/use-chat';

/**
 * The toolbar surface: a drawer on the trailing edge of the backend.
 *
 * It lives in the TOP document, which is what lets it survive module
 * navigation, and that is also what makes its width worth remembering — a
 * drawer the user resized once should still be that wide tomorrow.
 *
 * `Escape` closes it and the element announces that closing, because the
 * toolbar button's `aria-expanded` is only true if something keeps it true.
 */

const DEFAULT_WIDTH = 440;
const MIN_WIDTH = 360;
const WIDTH_KEY = 'panel.width';

function maxWidth(): number {
  return Math.max(MIN_WIDTH, Math.round(window.innerWidth * 0.6));
}

function clampWidth(value: number): number {
  return Math.min(maxWidth(), Math.max(MIN_WIDTH, Math.round(value)));
}

export type PanelSurfaceProps = {
  open: boolean;
  onRequestClose: () => void;
};

export function PanelSurface({ open, onRequestClose }: PanelSurfaceProps) {
  const chat = useChat(0);
  const [width, setWidth] = useState(() => clampWidth(readNumber(WIDTH_KEY, DEFAULT_WIDTH)));
  const [dragging, setDragging] = useState(false);
  const panelRef = useRef<HTMLDivElement | null>(null);

  // The drag is tracked on the window rather than the handle: a pointer moving
  // faster than the browser repaints will leave the 6px handle behind, and a
  // resize that stops when the cursor slips off it feels broken.
  useEffect(() => {
    if (!dragging) {
      return;
    }

    const move = (event: PointerEvent) => {
      event.preventDefault();
      setWidth(clampWidth(window.innerWidth - event.clientX));
    };
    const stop = () => setDragging(false);

    window.addEventListener('pointermove', move);
    window.addEventListener('pointerup', stop);
    window.addEventListener('pointercancel', stop);

    return () => {
      window.removeEventListener('pointermove', move);
      window.removeEventListener('pointerup', stop);
      window.removeEventListener('pointercancel', stop);
    };
  }, [dragging]);

  useEffect(() => {
    if (!dragging) {
      writeSetting(WIDTH_KEY, String(width));
    }
  }, [dragging, width]);

  useEffect(() => {
    const onResize = () => setWidth((current) => clampWidth(current));
    window.addEventListener('resize', onResize);

    return () => window.removeEventListener('resize', onResize);
  }, []);

  useEffect(() => {
    if (!open) {
      return;
    }
    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        // A dialog inside the panel owns Escape first; Radix stops propagation
        // while one is open, so reaching here means nothing else wanted it.
        onRequestClose();
      }
    };
    const root = panelRef.current?.getRootNode();
    const target: EventTarget = root instanceof ShadowRoot ? root : document;
    target.addEventListener('keydown', onKeyDown as EventListener);

    return () => target.removeEventListener('keydown', onKeyDown as EventListener);
  }, [onRequestClose, open]);

  const resizeByKeyboard = useCallback((event: React.KeyboardEvent<HTMLDivElement>) => {
    const step = event.shiftKey ? 48 : 16;
    if (event.key === 'ArrowLeft') {
      event.preventDefault();
      setWidth((current) => clampWidth(current + step));
    } else if (event.key === 'ArrowRight') {
      event.preventDefault();
      setWidth((current) => clampWidth(current - step));
    } else if (event.key === 'Home') {
      event.preventDefault();
      setWidth(clampWidth(DEFAULT_WIDTH));
    }
  }, []);

  if (!open) {
    return null;
  }

  return (
    <aside
      aria-label="TYPO3 AI Chat"
      className={cn(
        'wc-root fixed inset-y-0 end-0 z-(--z-panel) flex flex-col border-s shadow-2xl',
        dragging ? 'select-none' : 'transition-[width] duration-150 ease-out',
      )}
      ref={panelRef}
      style={{ width: `${width}px` }}
    >
      <div
        aria-label="Resize the chat panel"
        aria-orientation="vertical"
        aria-valuemax={maxWidth()}
        aria-valuemin={MIN_WIDTH}
        aria-valuenow={width}
        className="group absolute inset-y-0 start-0 z-10 w-1.5 -translate-x-1/2 cursor-ew-resize"
        onKeyDown={resizeByKeyboard}
        onPointerDown={(event) => {
          event.preventDefault();
          setDragging(true);
        }}
        role="separator"
        tabIndex={0}
      >
        <span
          className={cn(
            'absolute inset-y-0 start-1/2 w-px -translate-x-1/2 bg-border transition-colors',
            'group-hover:bg-primary group-focus-visible:bg-primary',
            dragging && 'bg-primary',
          )}
        />
      </div>

      <header className="flex items-center gap-1 border-b bg-background px-2.5 py-2">
        <h1 className="min-w-0 flex-1 truncate font-semibold">
          {chat.thread.conversation?.title || 'TYPO3 AI Chat'}
        </h1>
        <Button aria-label="New conversation" onClick={() => void chat.startNew()} size="icon-sm" variant="ghost">
          <PlusIcon aria-hidden="true" />
        </Button>
        <Button
          aria-label="Open in the full module"
          onClick={() => {
            if (openModule(chat.conversationUid)) {
              onRequestClose();
            }
          }}
          size="icon-sm"
          variant="ghost"
        >
          <MaximizeIcon aria-hidden="true" />
        </Button>
        <Button aria-label="Close the chat panel" onClick={onRequestClose} size="icon-sm" variant="ghost">
          <XIcon aria-hidden="true" />
        </Button>
      </header>

      {chat.statusError === null ? null : <ErrorBanner message={chat.statusError} />}
      {chat.thread.error === null ? null : (
        <ErrorBanner message={chat.thread.error} onDismiss={chat.dismissError} />
      )}

      <Thread
        busy={chat.busy}
        emptyDescription="This chat can read and change this TYPO3 installation. Writes always ask first."
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
    </aside>
  );
}
