import { useId } from 'react';
import { InfoIcon } from 'lucide-react';
import {
  PromptInput,
  PromptInputAttachButton,
  PromptInputFooter,
  PromptInputHeader,
  PromptInputSubmit,
  PromptInputTextarea,
  PromptInputTools,
  type PromptAttachment,
  type PromptInputStatus,
} from '@/components/ai/prompt-input';
import { Suggestion, Suggestions } from '@/components/ai/suggestion';
import { ComposerAttachments } from '@/components/chat/attachment-list';
import { composerState, type TurnPhase } from '@/state/reducer';
import type { ChatStatus } from '@/state/types';

/**
 * Where a turn starts.
 *
 * Enter sends and Shift+Enter is a newline — the convention every chat shares,
 * and the one an editor will try first. When the composer cannot accept a
 * message it says why in the same place the message would have gone, because a
 * disabled button with no explanation is indistinguishable from a broken one.
 */
export type ComposerProps = {
  status: ChatStatus | null;
  phase: TurnPhase;
  busy: boolean;
  attachments: PromptAttachment[];
  onSend: (text: string) => void;
  onStop: () => void;
  onAddFiles: (files: File[]) => void;
  onRemoveFile: (id: string) => void;
  /** Shown only when the thread is empty; a wall of chips over a conversation is noise. */
  showSuggestions: boolean;
};

const MAX_UPLOAD_BYTES = 20 * 1024 * 1024;

export function Composer({
  status,
  phase,
  busy,
  attachments,
  onSend,
  onStop,
  onAddFiles,
  onRemoveFile,
  showSuggestions,
}: ComposerProps) {
  const hintId = useId();
  const { disabled, reason } = composerState(
    phase,
    status?.budget.allowed ?? true,
    status?.budget.reason ?? null,
    status?.available ?? true,
  );

  const submitStatus: PromptInputStatus =
    phase === 'streaming' ? 'streaming' : phase === 'error' ? 'error' : 'ready';
  const suggestions = showSuggestions ? (status?.suggestions ?? []) : [];
  const maxLength = status?.limits.maxMessageLength ?? 0;

  return (
    <div className="border-t bg-background px-3 py-2.5">
      {/*
        Suggestions wrap rather than scroll. Upstream lays them out as a
        horizontal scroller, which suits short chips; these are whole sentences,
        and in a 360px drawer the second one is cut off mid-word with nothing to
        say that it continues.
      */}
      {suggestions.length > 0 && !disabled ? (
        <Suggestions className="mb-2 w-full flex-wrap">
          {suggestions.map((suggestion) => (
            <Suggestion key={suggestion} onClick={onSend} suggestion={suggestion} />
          ))}
        </Suggestions>
      ) : null}

      <PromptInput
        accept=".txt,.md,.csv,.pdf,.docx,.xlsx,text/plain,text/markdown,text/csv,application/pdf,application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
        attachments={attachments}
        maxFileSize={MAX_UPLOAD_BYTES}
        onAddFiles={onAddFiles}
        onRemoveFile={onRemoveFile}
        onSubmit={(message) => onSend(message.text)}
      >
        {attachments.length > 0 ? (
          <PromptInputHeader>
            <ComposerAttachments attachments={attachments} onRemove={onRemoveFile} />
          </PromptInputHeader>
        ) : null}

        <PromptInputTextarea
          aria-describedby={reason === '' ? undefined : hintId}
          aria-label="Message"
          disabled={disabled}
          {...(maxLength > 0 ? { maxLength } : {})}
          // Said once. The reason lives in the hint below, where it has an
          // icon, can wrap, and is what `aria-describedby` points at; repeating
          // it in the placeholder of a box nobody can type in is just noise.
          placeholder={disabled ? '' : 'Ask about this installation, or tell it what to change…'}
        />

        <PromptInputFooter>
          <PromptInputTools>
            <PromptInputAttachButton disabled={disabled} />
          </PromptInputTools>
          <PromptInputSubmit
            disabled={disabled && phase !== 'streaming'}
            onStop={onStop}
            status={submitStatus}
          />
        </PromptInputFooter>
      </PromptInput>

      {reason === '' ? null : (
        <p className="mt-1.5 flex items-start gap-1.5 text-muted-foreground" id={hintId}>
          <InfoIcon className="mt-px size-3.5 shrink-0" aria-hidden="true" />
          {reason}
        </p>
      )}

      {busy && phase === 'streaming' ? (
        <p className="sr-only" role="status">
          A turn is running.
        </p>
      ) : null}
    </div>
  );
}
