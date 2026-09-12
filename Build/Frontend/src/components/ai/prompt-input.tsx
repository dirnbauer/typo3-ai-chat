import {
  Children,
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useRef,
  useState,
} from 'react';
import type {
  ChangeEventHandler,
  ClipboardEventHandler,
  ComponentProps,
  FormEvent,
  FormEventHandler,
  HTMLAttributes,
  KeyboardEventHandler,
  ReactNode,
  RefObject,
} from 'react';
import { CornerDownLeftIcon, PaperclipIcon, SquareIcon, XIcon } from 'lucide-react';
import {
  InputGroup,
  InputGroupAddon,
  InputGroupButton,
  InputGroupTextarea,
} from '@/components/ui/input-group';
import { Spinner } from '@/components/ui/spinner';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';

/**
 * Vendored from AI Elements, trimmed to what this chat has a use for.
 *
 * Three things were removed and each removal is the point of vendoring:
 *
 * 1.  **The `ai` SDK types.** `PromptInputSubmit` typed its status as the SDK's
 *     `ChatStatus`; this client's states come from the turn machine in
 *     `state/reducer.ts`, which is the thing that actually knows.
 * 2.  **`nanoid` and blob-URL attachments.** The original holds files in the
 *     browser as object URLs and hands them to a model as data URLs. This
 *     backend takes an upload first and gives back a FAL uid, so an attachment
 *     here is a `fileUid` — there is nothing to encode and nothing to revoke.
 * 3.  **The model picker, command palette, hover cards and screenshot capture.**
 *     They brought `cmdk`, `select` and `hover-card` for surfaces this chat
 *     does not have: one installation has one configured model, and the user
 *     does not choose it.
 *
 * What is kept is the behaviour the composer is judged on: Enter sends,
 * Shift+Enter is a newline, composition (IME) input is never interrupted,
 * Backspace on an empty field removes the last attachment, files can be pasted
 * or dropped, and the submit button turns into a stop button while a run is in
 * flight.
 */

export interface PromptAttachment {
  /** Stable within this composer session; not the FAL uid. */
  id: string;
  file: File;
  fileUid?: number;
  status: 'uploading' | 'ready' | 'error';
  error?: string;
}

interface AttachmentsContextValue {
  files: PromptAttachment[];
  add: (files: File[] | FileList) => void;
  remove: (id: string) => void;
  clear: () => void;
  openFileDialog: () => void;
  fileInputRef: RefObject<HTMLInputElement | null>;
}

const AttachmentsContext = createContext<AttachmentsContextValue | null>(null);

export function usePromptInputAttachments(): AttachmentsContextValue {
  const context = useContext(AttachmentsContext);
  if (context === null) {
    throw new Error('usePromptInputAttachments must be used inside <PromptInput>.');
  }

  return context;
}

export interface PromptInputMessage {
  text: string;
  attachments: PromptAttachment[];
}

export type PromptInputProps = Omit<HTMLAttributes<HTMLFormElement>, 'onSubmit' | 'onError'> & {
  accept?: string;
  multiple?: boolean;
  maxFiles?: number;
  maxFileSize?: number;
  attachments: PromptAttachment[];
  onAddFiles: (files: File[]) => void;
  onRemoveFile: (id: string) => void;
  onError?: (error: { code: 'max_files' | 'max_file_size' | 'accept'; message: string }) => void;
  onSubmit: (message: PromptInputMessage, event: FormEvent<HTMLFormElement>) => void;
};

export function PromptInput({
  className,
  accept,
  multiple = true,
  maxFiles,
  maxFileSize,
  attachments,
  onAddFiles,
  onRemoveFile,
  onError,
  onSubmit,
  children,
  ...props
}: PromptInputProps) {
  const inputRef = useRef<HTMLInputElement | null>(null);
  const formRef = useRef<HTMLFormElement | null>(null);

  const matchesAccept = useCallback(
    (file: File): boolean => {
      if (accept === undefined || accept.trim() === '') {
        return true;
      }

      return accept
        .split(',')
        .map((pattern) => pattern.trim())
        .filter((pattern) => pattern !== '')
        .some((pattern) =>
          pattern.endsWith('/*') ? file.type.startsWith(pattern.slice(0, -1)) : file.type === pattern,
        );
    },
    [accept],
  );

  const add = useCallback(
    (incoming: File[] | FileList) => {
      const candidates = [...incoming];
      if (candidates.length === 0) {
        return;
      }

      const accepted = candidates.filter(matchesAccept);
      if (accepted.length === 0) {
        onError?.({ code: 'accept', message: 'That file type is not accepted.' });

        return;
      }

      const sized =
        maxFileSize === undefined ? accepted : accepted.filter((file) => file.size <= maxFileSize);
      if (sized.length === 0) {
        onError?.({ code: 'max_file_size', message: 'The file is too large.' });

        return;
      }

      const capacity = maxFiles === undefined ? sized.length : Math.max(0, maxFiles - attachments.length);
      const capped = sized.slice(0, capacity);
      if (capped.length < sized.length) {
        onError?.({ code: 'max_files', message: 'Too many files; some were not added.' });
      }
      if (capped.length > 0) {
        onAddFiles(capped);
      }
    },
    [attachments.length, matchesAccept, maxFileSize, maxFiles, onAddFiles, onError],
  );

  const openFileDialog = useCallback(() => inputRef.current?.click(), []);

  const clear = useCallback(() => {
    for (const attachment of attachments) {
      onRemoveFile(attachment.id);
    }
  }, [attachments, onRemoveFile]);

  // Drops land on the form, not on the document: a chat panel floating over the
  // backend must not swallow a file an editor meant for the file list behind it.
  useEffect(() => {
    const form = formRef.current;
    if (form === null) {
      return;
    }
    const allow = (event: DragEvent) => {
      if (event.dataTransfer?.types?.includes('Files')) {
        event.preventDefault();
      }
    };
    const drop = (event: DragEvent) => {
      if (event.dataTransfer?.types?.includes('Files')) {
        event.preventDefault();
      }
      if (event.dataTransfer?.files && event.dataTransfer.files.length > 0) {
        add(event.dataTransfer.files);
      }
    };
    form.addEventListener('dragover', allow);
    form.addEventListener('drop', drop);

    return () => {
      form.removeEventListener('dragover', allow);
      form.removeEventListener('drop', drop);
    };
  }, [add]);

  const handleChange: ChangeEventHandler<HTMLInputElement> = useCallback(
    (event) => {
      if (event.currentTarget.files !== null) {
        add(event.currentTarget.files);
      }
      // Reset so re-picking a file that was just removed still fires `change`.
      event.currentTarget.value = '';
    },
    [add],
  );

  const handleSubmit: FormEventHandler<HTMLFormElement> = useCallback(
    (event) => {
      event.preventDefault();
      const form = event.currentTarget;
      const text = String(new FormData(form).get('message') ?? '');
      if (text.trim() === '' && attachments.length === 0) {
        return;
      }
      form.reset();
      onSubmit({ text, attachments }, event);
    },
    [attachments, onSubmit],
  );

  const context = useMemo<AttachmentsContextValue>(
    () => ({ add, clear, fileInputRef: inputRef, files: attachments, openFileDialog, remove: onRemoveFile }),
    [add, attachments, clear, onRemoveFile, openFileDialog],
  );

  return (
    <AttachmentsContext.Provider value={context}>
      <input
        accept={accept}
        aria-hidden="true"
        className="hidden"
        multiple={multiple}
        onChange={handleChange}
        ref={inputRef}
        tabIndex={-1}
        type="file"
      />
      <form className={cn('w-full', className)} onSubmit={handleSubmit} ref={formRef} {...props}>
        <InputGroup className="overflow-hidden">{children}</InputGroup>
      </form>
    </AttachmentsContext.Provider>
  );
}

export type PromptInputBodyProps = HTMLAttributes<HTMLDivElement>;

export function PromptInputBody({ className, ...props }: PromptInputBodyProps) {
  return <div className={cn('contents', className)} {...props} />;
}

export type PromptInputTextareaProps = ComponentProps<typeof InputGroupTextarea>;

export function PromptInputTextarea({
  className,
  onKeyDown,
  placeholder = 'Ask about this installation…',
  ...props
}: PromptInputTextareaProps) {
  const attachments = usePromptInputAttachments();
  const [isComposing, setIsComposing] = useState(false);

  const handleKeyDown: KeyboardEventHandler<HTMLTextAreaElement> = useCallback(
    (event) => {
      onKeyDown?.(event);
      if (event.defaultPrevented) {
        return;
      }

      if (event.key === 'Enter') {
        // An IME candidate window uses Enter to commit a character. Sending on
        // that keystroke truncates the word the user was in the middle of.
        if (isComposing || event.nativeEvent.isComposing || event.shiftKey) {
          return;
        }
        event.preventDefault();
        const submit = event.currentTarget.form?.querySelector<HTMLButtonElement>(
          'button[type="submit"]',
        );
        if (submit?.disabled === true) {
          return;
        }
        event.currentTarget.form?.requestSubmit();

        return;
      }

      if (event.key === 'Backspace' && event.currentTarget.value === '' && attachments.files.length > 0) {
        event.preventDefault();
        const last = attachments.files.at(-1);
        if (last !== undefined) {
          attachments.remove(last.id);
        }
      }
    },
    [attachments, isComposing, onKeyDown],
  );

  const handlePaste: ClipboardEventHandler<HTMLTextAreaElement> = useCallback(
    (event) => {
      const items = event.clipboardData?.items;
      if (items === undefined) {
        return;
      }
      const files: File[] = [];
      for (const item of items) {
        if (item.kind === 'file') {
          const file = item.getAsFile();
          if (file !== null) {
            files.push(file);
          }
        }
      }
      if (files.length > 0) {
        event.preventDefault();
        attachments.add(files);
      }
    },
    [attachments],
  );

  return (
    <InputGroupTextarea
      className={cn('field-sizing-content max-h-56 min-h-14 text-[length:var(--text-read)]', className)}
      name="message"
      onCompositionEnd={() => setIsComposing(false)}
      onCompositionStart={() => setIsComposing(true)}
      onKeyDown={handleKeyDown}
      onPaste={handlePaste}
      placeholder={placeholder}
      {...props}
    />
  );
}

export type PromptInputHeaderProps = Omit<ComponentProps<typeof InputGroupAddon>, 'align'>;

export function PromptInputHeader({ className, ...props }: PromptInputHeaderProps) {
  return <InputGroupAddon align="block-start" className={cn('gap-1.5', className)} {...props} />;
}

export type PromptInputFooterProps = Omit<ComponentProps<typeof InputGroupAddon>, 'align'>;

export function PromptInputFooter({ className, ...props }: PromptInputFooterProps) {
  return (
    <InputGroupAddon align="block-end" className={cn('justify-between gap-1', className)} {...props} />
  );
}

export type PromptInputToolsProps = HTMLAttributes<HTMLDivElement>;

export function PromptInputTools({ className, ...props }: PromptInputToolsProps) {
  return <div className={cn('flex min-w-0 items-center gap-1', className)} {...props} />;
}

export type PromptInputButtonProps = ComponentProps<typeof InputGroupButton> & {
  tooltip?: ReactNode;
};

export function PromptInputButton({
  variant = 'ghost',
  className,
  size,
  tooltip,
  ...props
}: PromptInputButtonProps) {
  const resolvedSize = size ?? (Children.count(props.children) > 1 ? 'sm' : 'icon-sm');
  const button = (
    <InputGroupButton className={cn(className)} size={resolvedSize} type="button" variant={variant} {...props} />
  );

  if (tooltip === undefined) {
    return button;
  }

  return (
    <Tooltip>
      <TooltipTrigger asChild>{button}</TooltipTrigger>
      <TooltipContent side="top">{tooltip}</TooltipContent>
    </Tooltip>
  );
}

export type PromptInputAttachButtonProps = Omit<PromptInputButtonProps, 'onClick'>;

export function PromptInputAttachButton({ children, ...props }: PromptInputAttachButtonProps) {
  const attachments = usePromptInputAttachments();

  return (
    <PromptInputButton
      aria-label="Attach a file"
      onClick={attachments.openFileDialog}
      tooltip="Attach a file"
      {...props}
    >
      {children ?? <PaperclipIcon className="size-4" aria-hidden="true" />}
    </PromptInputButton>
  );
}

/** What the submit button is for, right now. */
export type PromptInputStatus = 'ready' | 'submitted' | 'streaming' | 'error';

export type PromptInputSubmitProps = ComponentProps<typeof InputGroupButton> & {
  status?: PromptInputStatus;
  onStop?: () => void;
};

export function PromptInputSubmit({
  className,
  variant = 'default',
  size = 'icon-sm',
  status = 'ready',
  onStop,
  onClick,
  children,
  ...props
}: PromptInputSubmitProps) {
  const running = status === 'submitted' || status === 'streaming';
  const stoppable = running && onStop !== undefined;

  let icon = <CornerDownLeftIcon className="size-4" aria-hidden="true" />;
  if (status === 'submitted') {
    icon = <Spinner />;
  } else if (status === 'streaming') {
    icon = <SquareIcon className="size-4" aria-hidden="true" />;
  } else if (status === 'error') {
    icon = <XIcon className="size-4" aria-hidden="true" />;
  }

  return (
    <InputGroupButton
      aria-label={stoppable ? 'Stop the running turn' : 'Send message'}
      className={cn('transition-transform active:scale-95', className)}
      onClick={(event) => {
        if (stoppable) {
          event.preventDefault();
          onStop();

          return;
        }
        onClick?.(event);
      }}
      size={size}
      type={stoppable ? 'button' : 'submit'}
      variant={variant}
      {...props}
    >
      {children ?? icon}
    </InputGroupButton>
  );
}
