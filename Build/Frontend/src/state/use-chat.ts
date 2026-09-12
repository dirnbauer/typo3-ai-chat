import { useCallback, useEffect, useMemo, useReducer, useRef, useState } from 'react';
import {
  ApiError,
  ROUTES,
  archiveConversation,
  cancelTurn,
  createConversation,
  deleteConversation,
  fetchConversation,
  fetchConversations,
  fetchStatus,
  pinConversation,
  renameConversation,
  runTurn,
  uploadFile,
} from '@/lib/api';
import { readClientContext } from '@/lib/context';
import { initialThreadState, threadReducer } from '@/state/reducer';
import type { PromptAttachment } from '@/components/ai/prompt-input';
import type { ChatStatus, ConversationSummary } from '@/state/types';

/**
 * Everything a surface needs, and the same thing for both of them.
 *
 * The panel and the module are two layouts over one controller. That is the
 * decision ADR-016 made — "same implementation, same state, same API; the
 * variant changes layout, not behaviour" — and it only holds if the behaviour
 * lives in one place, which is here.
 */

let attachmentSequence = 0;

export interface ChatController {
  status: ChatStatus | null;
  statusError: string | null;
  conversations: ConversationSummary[];
  conversationUid: number;
  thread: ReturnType<typeof threadReducer>;
  attachments: PromptAttachment[];
  busy: boolean;
  includeArchived: boolean;

  select: (uid: number) => void;
  startNew: () => Promise<number | null>;
  send: (text: string) => void;
  decide: (approved: boolean, remember: boolean) => void;
  stop: () => void;
  dismissError: () => void;

  addFiles: (files: File[]) => void;
  removeFile: (id: string) => void;

  rename: (uid: number, title: string) => Promise<void>;
  setPinned: (uid: number, pinned: boolean) => Promise<void>;
  setArchived: (uid: number, archived: boolean) => Promise<void>;
  remove: (uid: number) => Promise<void>;
  setIncludeArchived: (include: boolean) => void;
}

export function useChat(initialConversation: number): ChatController {
  const [status, setStatus] = useState<ChatStatus | null>(null);
  const [statusError, setStatusError] = useState<string | null>(null);
  const [conversations, setConversations] = useState<ConversationSummary[]>([]);
  const [conversationUid, setConversationUid] = useState(initialConversation);
  const [includeArchived, setIncludeArchived] = useState(false);
  const [attachments, setAttachments] = useState<PromptAttachment[]>([]);
  const [busy, setBusy] = useState(false);
  const [thread, dispatch] = useReducer(threadReducer, initialThreadState);

  // One run at a time, and the abort handle for it. A ref rather than state:
  // it is read inside callbacks that must not re-create themselves when a turn
  // starts, and nothing renders differently because of it.
  const inFlight = useRef<AbortController | null>(null);
  const conversationRef = useRef(conversationUid);
  conversationRef.current = conversationUid;

  const reloadConversations = useCallback(
    async (signal?: AbortSignal) => {
      try {
        const result = await fetchConversations(includeArchived, signal);
        setConversations(result.conversations);

        return result.conversations;
      } catch (error) {
        if (!(error instanceof DOMException && error.name === 'AbortError')) {
          setStatusError(describe(error));
        }

        return [];
      }
    },
    [includeArchived],
  );

  useEffect(() => {
    const controller = new AbortController();
    void (async () => {
      try {
        setStatus(await fetchStatus(controller.signal));
        setStatusError(null);
      } catch (error) {
        if (!(error instanceof DOMException && error.name === 'AbortError')) {
          setStatusError(describe(error));
        }
      }
    })();

    return () => controller.abort();
  }, []);

  useEffect(() => {
    const controller = new AbortController();
    void reloadConversations(controller.signal);

    return () => controller.abort();
  }, [reloadConversations]);

  // Load whichever conversation is selected. A uid of 0 means "none yet", which
  // is a real state — the panel's first open, before anything has been created.
  useEffect(() => {
    if (conversationUid <= 0) {
      dispatch({ type: 'reset', conversation: null, messages: [] });

      return;
    }

    const controller = new AbortController();
    void (async () => {
      try {
        const result = await fetchConversation(conversationUid, 0, controller.signal);
        dispatch({ type: 'reset', conversation: result.conversation, messages: result.messages });
      } catch (error) {
        if (!(error instanceof DOMException && error.name === 'AbortError')) {
          dispatch({ type: 'transport-error', message: describe(error) });
        }
      }
    })();

    return () => controller.abort();
  }, [conversationUid]);

  useEffect(() => () => inFlight.current?.abort(), []);

  const ensureConversation = useCallback(async (): Promise<number> => {
    if (conversationRef.current > 0) {
      return conversationRef.current;
    }
    const created = await createConversation();
    setConversationUid(created.conversation.uid);
    conversationRef.current = created.conversation.uid;
    setConversations((current) => [created.conversation, ...current]);
    dispatch({ type: 'reset', conversation: created.conversation, messages: [] });

    return created.conversation.uid;
  }, []);

  /**
   * Run one turn and refresh what it changed.
   *
   * The refresh at the end is not cosmetic: the conversation row carries the
   * status, the pending approval and the message count, and a sidebar that
   * still says "idle" after a run suspended is a sidebar nobody trusts.
   */
  const drive = useCallback(
    async (route: typeof ROUTES.conversationTurn | typeof ROUTES.conversationApproval, body: object) => {
      const controller = new AbortController();
      inFlight.current = controller;
      setBusy(true);

      try {
        await runTurn(
          route,
          body as never,
          (event) => dispatch({ type: 'event', event }),
          controller.signal,
        );
      } catch (error) {
        if (error instanceof DOMException && error.name === 'AbortError') {
          dispatch({ type: 'cancelled' });
        } else {
          dispatch({ type: 'transport-error', message: describe(error) });
        }
      } finally {
        inFlight.current = null;
        setBusy(false);
        void reloadConversations();
        const uid = conversationRef.current;
        if (uid > 0) {
          try {
            const result = await fetchConversation(uid);
            dispatch({ type: 'conversation', conversation: result.conversation });
          } catch {
            // The turn is over either way; a failed refresh is not worth an
            // error banner on top of whatever the turn already reported.
          }
        }
      }
    },
    [reloadConversations],
  );

  const send = useCallback(
    (text: string) => {
      const content = text.trim();
      const ready = attachments.filter((attachment) => attachment.status === 'ready');
      if (content === '' || busy) {
        return;
      }

      void (async () => {
        let uid: number;
        try {
          uid = await ensureConversation();
        } catch (error) {
          dispatch({ type: 'transport-error', message: describe(error) });

          return;
        }

        dispatch({
          type: 'send',
          content,
          attachments: ready.map((attachment) => ({
            fileUid: attachment.fileUid ?? 0,
            fileName: attachment.file.name,
            fileMimeType: attachment.file.type,
            fileSize: attachment.file.size,
          })),
        });
        setAttachments([]);

        await drive(ROUTES.conversationTurn, {
          conversation: uid,
          content,
          attachments: ready
            .filter((attachment) => attachment.fileUid !== undefined)
            .map((attachment) => ({ fileUid: attachment.fileUid })),
          context: readClientContext(),
        });
      })();
    },
    [attachments, busy, drive, ensureConversation],
  );

  const decide = useCallback(
    (approved: boolean, remember: boolean) => {
      const approval = thread.pendingApproval;
      const uid = conversationRef.current;
      if (approval === null || uid <= 0 || busy) {
        return;
      }

      void (async () => {
        if (remember && approved) {
          try {
            // `rename` is where the conversation's flags live; the title is
            // sent unchanged because the route requires a non-empty one.
            await renameConversation(uid, thread.conversation?.title || 'Conversation', true);
          } catch (error) {
            dispatch({ type: 'transport-error', message: describe(error) });

            return;
          }
        }

        await drive(ROUTES.conversationApproval, {
          conversation: uid,
          approved,
          // Unchanged. See `ApprovalCard`.
          turnDigest: approval.turnDigest,
        });
      })();
    },
    [busy, drive, thread.conversation?.title, thread.pendingApproval],
  );

  const stop = useCallback(() => {
    const uid = conversationRef.current;
    inFlight.current?.abort();
    if (uid > 0) {
      // Aborting the fetch closes the socket; the server notices through
      // `connection_aborted()` only after its next write, so the cancel route
      // is what actually ends the run.
      void cancelTurn(uid).catch(() => undefined);
    }
  }, []);

  const addFiles = useCallback(
    (files: File[]) => {
      void (async () => {
        let uid: number;
        try {
          uid = await ensureConversation();
        } catch (error) {
          dispatch({ type: 'transport-error', message: describe(error) });

          return;
        }

        for (const file of files) {
          attachmentSequence += 1;
          const id = `a${attachmentSequence}`;
          setAttachments((current) => [...current, { id, file, status: 'uploading' }]);

          try {
            const info = await uploadFile(uid, file);
            setAttachments((current) =>
              current.map((attachment) =>
                attachment.id === id
                  ? { ...attachment, status: 'ready', fileUid: info.fileUid }
                  : attachment,
              ),
            );
          } catch (error) {
            const message = describe(error);
            setAttachments((current) =>
              current.map((attachment) =>
                attachment.id === id ? { ...attachment, status: 'error', error: message } : attachment,
              ),
            );
          }
        }
      })();
    },
    [ensureConversation],
  );

  const removeFile = useCallback((id: string) => {
    setAttachments((current) => current.filter((attachment) => attachment.id !== id));
  }, []);

  const mutate = useCallback(
    async (action: () => Promise<unknown>) => {
      try {
        await action();
      } catch (error) {
        setStatusError(describe(error));

        return;
      }
      await reloadConversations();
    },
    [reloadConversations],
  );

  const rename = useCallback(
    async (uid: number, title: string) => {
      await mutate(() => renameConversation(uid, title));
      if (uid === conversationRef.current) {
        const result = await fetchConversation(uid).catch(() => null);
        if (result !== null) {
          dispatch({ type: 'conversation', conversation: result.conversation });
        }
      }
    },
    [mutate],
  );

  const setPinned = useCallback(
    (uid: number, pinned: boolean) => mutate(() => pinConversation(uid, pinned)),
    [mutate],
  );

  const setArchived = useCallback(
    (uid: number, archived: boolean) => mutate(() => archiveConversation(uid, archived)),
    [mutate],
  );

  const remove = useCallback(
    async (uid: number) => {
      await mutate(() => deleteConversation(uid));
      if (uid === conversationRef.current) {
        setConversationUid(0);
      }
    },
    [mutate],
  );

  const select = useCallback((uid: number) => {
    inFlight.current?.abort();
    setConversationUid(uid);
    setAttachments([]);
  }, []);

  const startNew = useCallback(async (): Promise<number | null> => {
    try {
      const created = await createConversation();
      setConversations((current) => [created.conversation, ...current]);
      setConversationUid(created.conversation.uid);
      conversationRef.current = created.conversation.uid;
      dispatch({ type: 'reset', conversation: created.conversation, messages: [] });
      setAttachments([]);

      return created.conversation.uid;
    } catch (error) {
      setStatusError(describe(error));

      return null;
    }
  }, []);

  const dismissError = useCallback(() => dispatch({ type: 'dismiss-error' }), []);

  return useMemo(
    () => ({
      status,
      statusError,
      conversations,
      conversationUid,
      thread,
      attachments,
      busy,
      includeArchived,
      select,
      startNew,
      send,
      decide,
      stop,
      dismissError,
      addFiles,
      removeFile,
      rename,
      setPinned,
      setArchived,
      remove,
      setIncludeArchived,
    }),
    [
      addFiles,
      attachments,
      busy,
      conversationUid,
      conversations,
      decide,
      dismissError,
      includeArchived,
      remove,
      removeFile,
      rename,
      select,
      send,
      setArchived,
      setPinned,
      startNew,
      status,
      statusError,
      stop,
      thread,
    ],
  );
}

/**
 * One sentence a user can act on, from whatever went wrong.
 *
 * `ApiError.hint` is what the status code MEANS; the server's own message is
 * what happened. Both, when both exist, because "429" and "you have started too
 * many turns" answer different questions.
 */
function describe(error: unknown): string {
  if (error instanceof ApiError) {
    return error.hint === '' ? error.message : `${error.message} ${error.hint}`;
  }
  if (error instanceof Error) {
    return error.message;
  }

  return 'Something went wrong.';
}
