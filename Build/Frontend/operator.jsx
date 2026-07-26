import React, {useCallback, useEffect, useMemo, useRef, useState} from 'react';
import {createRoot} from 'react-dom/client';
import {
    AssistantRuntimeProvider,
    AttachmentPrimitive,
    ComposerPrimitive,
    MessagePrimitive,
    ThreadPrimitive,
    useLocalRuntime,
    useMessage,
    useMessagePart,
} from '@assistant-ui/react';
import ReactMarkdown from 'react-markdown';
import remarkGfm from 'remark-gfm';
import {
    Archive,
    Bot,
    Check,
    ChevronLeft,
    ChevronRight,
    CircleAlert,
    FileText,
    Image as ImageIcon,
    Menu,
    MessageSquarePlus,
    MoreHorizontal,
    Paperclip,
    Pin,
    Play,
    Search,
    Send,
    ShieldCheck,
    Sparkles,
    Square,
    Trash2,
    User,
    Wrench,
    X,
    Zap,
} from 'lucide-react';
import './operator.css';

const ACTIVE_STATUSES = new Set(['processing', 'locked', 'tool_loop', 'flue_running']);

class Typo3Api {
    url(routeName) {
        const url = globalThis.TYPO3?.settings?.ajaxUrls?.[routeName];
        if (!url) {
            throw new Error(`TYPO3 AJAX route "${routeName}" is unavailable.`);
        }
        return url;
    }

    async request(route, options = {}) {
        const response = await fetch(this.url(route), {
            credentials: 'same-origin',
            headers: {'Accept': 'application/json', ...(options.headers || {})},
            ...options,
        });
        const data = await response.json().catch(() => ({}));
        if (!response.ok) {
            throw new Error(data.error || `Request failed (${response.status})`);
        }
        return data;
    }

    get(route, params = {}) {
        const url = new URL(this.url(route), globalThis.location.href);
        for (const [key, value] of Object.entries(params)) {
            url.searchParams.set(key, String(value));
        }
        return this.requestUrl(url);
    }

    async requestUrl(url) {
        const response = await fetch(url, {
            credentials: 'same-origin',
            headers: {'Accept': 'application/json'},
        });
        const data = await response.json().catch(() => ({}));
        if (!response.ok) {
            throw new Error(data.error || `Request failed (${response.status})`);
        }
        return data;
    }

    post(route, body) {
        return this.request(route, {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(body),
        });
    }

    status() {
        return this.get('ai_chat_status');
    }

    conversations() {
        return this.get('ai_chat_conversations');
    }

    createConversation() {
        return this.post('ai_chat_conversation_create', {});
    }

    messages(conversationUid, after = 0) {
        return this.get('ai_chat_conversation_messages', {conversationUid, after});
    }

    sendMessage(conversationUid, content, fileUids) {
        return this.post('ai_chat_conversation_send', {conversationUid, content, fileUids});
    }

    approve(conversationUid, approved) {
        return this.post('ai_chat_conversation_approval', {conversationUid, approved});
    }

    triggerFlue(conversationUid, content, pageUid) {
        return this.post('ai_chat_flue_trigger', {conversationUid, content, pageUid});
    }

    flueStatus(conversationUid) {
        return this.get('ai_chat_flue_status', {conversationUid});
    }

    archive(conversationUid) {
        return this.post('ai_chat_conversation_archive', {conversationUid});
    }

    pin(conversationUid) {
        return this.post('ai_chat_conversation_pin', {conversationUid});
    }

    rename(conversationUid, title) {
        return this.post('ai_chat_conversation_rename', {conversationUid, title});
    }

    async upload(file) {
        const form = new FormData();
        form.append('file', file);
        return this.request('ai_chat_file_upload', {method: 'POST', body: form});
    }
}

const api = new Typo3Api();

function messageText(message) {
    const content = message?.content;
    if (typeof content === 'string') return content;
    if (!Array.isArray(content)) return '';
    return content.filter(part => part?.type === 'text').map(part => part.text || '').join('\n');
}

function storedAttachments(message) {
    if (Array.isArray(message?.attachments)) return message.attachments;
    if (message?.fileUid) {
        return [{
            fileUid: message.fileUid,
            fileName: message.fileName || 'Attachment',
            fileMimeType: message.fileMimeType || '',
        }];
    }
    return [];
}

function mapInitialMessages(messages) {
    return messages
        .filter(message => message.role === 'user' || message.role === 'assistant')
        .map(message => ({
            role: message.role,
            content: [{type: 'text', text: messageText(message)}],
            createdAt: message.createdAt ? new Date(message.createdAt) : new Date(),
            metadata: {
                custom: {
                    storedAttachments: storedAttachments(message),
                },
            },
        }));
}

function falData(attachment) {
    const dataPart = attachment?.content?.find(part => part.type === 'data' && part.name === 'typo3-fal');
    return dataPart?.data || null;
}

function createAttachmentAdapter() {
    return {
        accept: 'image/png,image/jpeg,image/webp,application/pdf,text/plain,.docx,.xlsx',
        async add({file}) {
            const isImage = file.type.startsWith('image/');
            const isPdf = file.type === 'application/pdf';
            return {
                id: crypto.randomUUID(),
                type: isImage ? 'image' : (isPdf ? 'document' : 'file'),
                name: file.name,
                contentType: file.type,
                file,
                previewUrl: URL.createObjectURL(file),
                status: {type: 'requires-action', reason: 'composer-send'},
            };
        },
        async send(attachment) {
            const uploaded = await api.upload(attachment.file);
            const base = {
                ...attachment,
                status: {type: 'complete'},
                fileUid: uploaded.fileUid,
                uploaded,
            };
            const falPart = {
                type: 'data',
                name: 'typo3-fal',
                data: uploaded,
            };
            if (attachment.type === 'image') {
                return {
                    ...base,
                    content: [
                        {type: 'image', image: attachment.previewUrl, filename: attachment.name},
                        falPart,
                    ],
                };
            }
            return {
                ...base,
                content: [
                    {
                        type: 'file',
                        data: attachment.previewUrl,
                        mimeType: attachment.contentType || 'application/octet-stream',
                        filename: attachment.name,
                    },
                    falPart,
                ],
            };
        },
        async remove(attachment) {
            if (attachment.previewUrl) URL.revokeObjectURL(attachment.previewUrl);
        },
    };
}

function AttachmentPreview({attachment, removable = false}) {
    const isImage = attachment.type === 'image' || attachment.contentType?.startsWith('image/');
    const isPdf = attachment.contentType === 'application/pdf';
    const previewUrl = attachment.previewUrl
        || attachment.content?.find(part => part.type === 'image')?.image
        || attachment.content?.find(part => part.type === 'file')?.data;

    return (
        <div className={`wc-attachment ${isImage ? 'is-image' : ''}`}>
            <div className="wc-attachment-preview">
                {isImage && previewUrl
                    ? <img src={previewUrl} alt="" />
                    : isPdf && previewUrl
                        ? <object data={`${previewUrl}#page=1&toolbar=0`} type="application/pdf" aria-label={`PDF preview: ${attachment.name}`} />
                        : <FileText size={24} aria-hidden="true" />}
            </div>
            <div className="wc-attachment-copy">
                <strong>{attachment.name}</strong>
                <span>{isPdf ? 'PDF document' : (attachment.contentType || 'File')}</span>
            </div>
            {removable && (
                <AttachmentPrimitive.Remove className="wc-icon-button wc-attachment-remove" aria-label={`Remove ${attachment.name}`}>
                    <X size={15} />
                </AttachmentPrimitive.Remove>
            )}
        </div>
    );
}

function MarkdownPart() {
    const part = useMessagePart();
    return (
        <div className="wc-markdown">
            <ReactMarkdown remarkPlugins={[remarkGfm]}>{part?.type === 'text' ? part.text : ''}</ReactMarkdown>
        </div>
    );
}

function ChatMessage() {
    const message = useMessage();
    const isUser = message.role === 'user';
    const savedAttachments = message.metadata?.custom?.storedAttachments || [];
    return (
        <MessagePrimitive.Root className={`wc-message ${isUser ? 'is-user' : 'is-assistant'}`}>
            <div className="wc-message-avatar" aria-hidden="true">
                {isUser ? <User size={16} /> : <Sparkles size={17} />}
            </div>
            <div className="wc-message-body">
                <div className="wc-message-kicker">{isUser ? 'You' : 'TYPO3 Operator'}</div>
                <MessagePrimitive.Parts components={{Text: MarkdownPart}} />
                {savedAttachments.length > 0 && (
                    <div className="wc-message-files">
                        {savedAttachments.map(file => (
                            <div className="wc-file-chip" key={file.fileUid}>
                                {file.fileMimeType?.startsWith('image/') ? <ImageIcon size={14} /> : <FileText size={14} />}
                                <span>{file.fileName}</span>
                            </div>
                        ))}
                    </div>
                )}
                <MessagePrimitive.Attachments>
                    {({attachment}) => <AttachmentPreview attachment={attachment} />}
                </MessagePrimitive.Attachments>
            </div>
        </MessagePrimitive.Root>
    );
}

function Composer({available, maxLength}) {
    return (
        <ComposerPrimitive.AttachmentDropzone className="wc-composer-dropzone">
            <ComposerPrimitive.Root className="wc-composer">
                <ComposerPrimitive.Attachments>
                    {({attachment}) => <AttachmentPreview attachment={attachment} removable />}
                </ComposerPrimitive.Attachments>
                <div className="wc-composer-row">
                    <ComposerPrimitive.AddAttachment
                        className="wc-icon-button wc-attach-button"
                        multiple
                        aria-label="Attach images or documents"
                        title="Attach images or documents"
                    >
                        <Paperclip size={18} />
                    </ComposerPrimitive.AddAttachment>
                    <ComposerPrimitive.Input
                        className="wc-composer-input"
                        placeholder={available ? 'Ask, inspect, or stage a TYPO3 change…' : 'Configure an nr-llm task to start'}
                        maxLength={maxLength || undefined}
                        disabled={!available}
                        rows={1}
                    />
                    <ComposerPrimitive.Cancel className="wc-send-button is-cancel" aria-label="Stop">
                        <Square size={15} fill="currentColor" />
                    </ComposerPrimitive.Cancel>
                    <ComposerPrimitive.Send className="wc-send-button" aria-label="Send">
                        <Send size={17} />
                    </ComposerPrimitive.Send>
                </div>
                <div className="wc-composer-meta">
                    <span><ShieldCheck size={13} /> TYPO3 permissions and approval gates stay active</span>
                    <span>Images · PDF · DOCX · TXT · XLSX</span>
                </div>
            </ComposerPrimitive.Root>
        </ComposerPrimitive.AttachmentDropzone>
    );
}

function wait(ms, signal) {
    return new Promise((resolve, reject) => {
        const timer = setTimeout(resolve, ms);
        signal?.addEventListener('abort', () => {
            clearTimeout(timer);
            reject(signal.reason || new DOMException('Aborted', 'AbortError'));
        }, {once: true});
    });
}

function ChatThread({
    conversationUid,
    initialMessages,
    initialCount,
    available,
    maxLength,
    onServerUpdate,
    executionMode,
    pageUid,
}) {
    const attachmentAdapter = useMemo(() => createAttachmentAdapter(), []);
    const modelAdapter = useMemo(() => ({
        async *run({messages, abortSignal}) {
            const last = messages.at(-1);
            const text = messageText(last);
            const fileUids = (last?.attachments || []).map(falData).filter(Boolean).map(file => file.fileUid);
            if (executionMode === 'flue') {
                if (fileUids.length > 0) {
                    throw new Error('Attachments currently run through the direct nr-llm lane. Switch to Direct tools for this message.');
                }
                await api.triggerFlue(conversationUid, text, pageUid);
                onServerUpdate({status: 'flue_running'});
            } else {
                await api.sendMessage(conversationUid, text, fileUids);
                onServerUpdate({status: 'processing'});
            }

            let after = initialCount;
            let lastData = null;
            while (!abortSignal.aborted) {
                await wait(executionMode === 'flue' ? 1800 : 900, abortSignal);
                const data = executionMode === 'flue'
                    ? await api.flueStatus(conversationUid)
                    : await api.messages(conversationUid, after);
                lastData = data;
                onServerUpdate(data);
                if (!ACTIVE_STATUSES.has(data.status)) break;
            }

            if (!lastData) throw new Error('The run produced no response.');
            if (lastData.status === 'failed') {
                throw new Error(lastData.errorMessage || 'The agent run failed.');
            }
            if (lastData.status === 'awaiting_approval') {
                yield {
                    content: [{
                        type: 'text',
                        text: 'I prepared a tool call that needs your approval. Review it in the execution ledger before continuing.',
                    }],
                    status: {type: 'complete', reason: 'stop'},
                };
                return;
            }

            const answer = [...(lastData.messages || [])].reverse().find(message => message.role === 'assistant');
            yield {
                content: [{type: 'text', text: answer ? messageText(answer) : 'The run completed.'}],
                status: {type: 'complete', reason: 'stop'},
            };
        },
    }), [conversationUid, executionMode, initialCount, onServerUpdate, pageUid]);

    const runtime = useLocalRuntime(modelAdapter, {
        initialMessages: mapInitialMessages(initialMessages),
        adapters: {attachments: attachmentAdapter},
    });

    return (
        <AssistantRuntimeProvider runtime={runtime}>
            <ThreadPrimitive.Root className="wc-thread">
                <ThreadPrimitive.Viewport className="wc-thread-viewport">
                    <ThreadPrimitive.Empty>
                        <div className="wc-empty-thread">
                            <div className="wc-empty-orbit"><Sparkles size={25} /></div>
                            <span className="wc-eyebrow">GOVERNED TYPO3 OPERATOR</span>
                            <h2>What should we improve?</h2>
                            <p>Inspect pages, audit content, use configured tools, or stage a change for review.</p>
                            <div className="wc-starter-grid">
                                <ComposerPrimitive.Root>
                                    <button type="button" onClick={() => runtime.main.composer.setText('Audit the current page and list the three most important improvements.')}>
                                        <Search size={16} /><span>Audit a page</span>
                                    </button>
                                    <button type="button" onClick={() => runtime.main.composer.setText('Find the latest TYPO3 errors and explain the likely cause.')}>
                                        <CircleAlert size={16} /><span>Inspect errors</span>
                                    </button>
                                    <button type="button" onClick={() => runtime.main.composer.setText('Create a draft content improvement for this page and show me every proposed tool call.')}>
                                        <Zap size={16} /><span>Stage an edit</span>
                                    </button>
                                </ComposerPrimitive.Root>
                            </div>
                        </div>
                    </ThreadPrimitive.Empty>
                    <ThreadPrimitive.Messages components={{Message: ChatMessage}} />
                    <ThreadPrimitive.ViewportFooter className="wc-thread-footer">
                        <ThreadPrimitive.ScrollToBottom className="wc-scroll-button" aria-label="Scroll to newest message">
                            <ChevronRight size={16} />
                        </ThreadPrimitive.ScrollToBottom>
                        <Composer available={available} maxLength={maxLength} />
                    </ThreadPrimitive.ViewportFooter>
                </ThreadPrimitive.Viewport>
            </ThreadPrimitive.Root>
        </AssistantRuntimeProvider>
    );
}

function StatusPill({status}) {
    const label = {
        idle: 'Ready',
        processing: 'Thinking',
        locked: 'Running',
        tool_loop: 'Using tools',
        awaiting_approval: 'Approval needed',
        flue_running: 'Flue workflow',
        failed: 'Failed',
    }[status] || status;
    return <span className={`wc-status is-${status || 'idle'}`}><i />{label}</span>;
}

function ToolRun({run, index}) {
    const [open, setOpen] = useState(index === 0);
    return (
        <article className={`wc-tool-run ${run.isError ? 'is-error' : ''}`}>
            <button type="button" onClick={() => setOpen(value => !value)}>
                <span className="wc-tool-icon"><Wrench size={15} /></span>
                <span className="wc-tool-title"><strong>{run.name}</strong><small>{run.isError ? 'Failed' : 'Completed'}</small></span>
                {run.isError ? <CircleAlert size={15} /> : <Check size={15} />}
            </button>
            {open && (
                <div className="wc-tool-detail">
                    <label>Arguments</label>
                    <pre>{JSON.stringify(run.arguments || {}, null, 2)}</pre>
                    <label>Result</label>
                    <pre>{run.result || 'No textual result.'}</pre>
                </div>
            )}
        </article>
    );
}

function ExecutionLedger({meta, onApproval, approvalBusy}) {
    const trace = meta.executionTrace || [];
    const pending = meta.pendingApproval || [];
    return (
        <aside className="wc-ledger">
            <header>
                <div>
                    <span className="wc-eyebrow">EXECUTION LEDGER</span>
                    <h2>Agent activity</h2>
                </div>
                <span className="wc-ledger-count">{trace.length + pending.length}</span>
            </header>
            <div className="wc-ledger-body">
                {pending.map((tool, index) => (
                    <article className="wc-approval" key={`${tool.name}-${index}`}>
                        <div className="wc-approval-head">
                            <span><ShieldCheck size={17} /></span>
                            <div><strong>Approval required</strong><small>{tool.name}</small></div>
                        </div>
                        <pre>{JSON.stringify(tool.arguments || {}, null, 2)}</pre>
                        <p>Review the exact arguments. TYPO3 permissions are checked again when execution resumes.</p>
                        <div className="wc-approval-actions">
                            <button disabled={approvalBusy} type="button" className="is-deny" onClick={() => onApproval(false)}>
                                <X size={15} /> Deny
                            </button>
                            <button disabled={approvalBusy} type="button" className="is-approve" onClick={() => onApproval(true)}>
                                <Check size={15} /> Approve once
                            </button>
                        </div>
                    </article>
                ))}
                {trace.map((run, index) => <ToolRun run={run} index={index} key={`${run.name}-${index}`} />)}
                {trace.length === 0 && pending.length === 0 && (
                    <div className="wc-ledger-empty">
                        <div><Wrench size={20} /></div>
                        <strong>No tools used yet</strong>
                        <p>Calls, arguments, results, and approval gates appear here as the agent works.</p>
                    </div>
                )}
            </div>
            <footer>
                <ShieldCheck size={14} />
                <span>Governed by nr-llm and TYPO3 access controls</span>
            </footer>
        </aside>
    );
}

function ConversationRail({items, activeUid, onSelect, onCreate, onArchive, collapsed, setCollapsed}) {
    const [query, setQuery] = useState('');
    const filtered = items.filter(item => item.title?.toLowerCase().includes(query.toLowerCase()));
    return (
        <aside className={`wc-rail ${collapsed ? 'is-collapsed' : ''}`}>
            <div className="wc-brand">
                <div className="wc-brand-mark"><Sparkles size={19} /></div>
                {!collapsed && <div><strong>TYPO3 AI Chat</strong><span>by Webconsulting</span></div>}
                <button className="wc-icon-button" type="button" onClick={() => setCollapsed(!collapsed)} aria-label={collapsed ? 'Expand conversations' : 'Collapse conversations'}>
                    {collapsed ? <ChevronRight size={17} /> : <ChevronLeft size={17} />}
                </button>
            </div>
            <button className="wc-new-chat" type="button" onClick={onCreate}>
                <MessageSquarePlus size={17} />
                {!collapsed && <span>New conversation</span>}
            </button>
            {!collapsed && (
                <>
                    <label className="wc-search">
                        <Search size={15} />
                        <input value={query} onChange={event => setQuery(event.target.value)} placeholder="Search conversations" />
                    </label>
                    <div className="wc-rail-label">RECENT</div>
                </>
            )}
            <nav className="wc-conversations" aria-label="Conversations">
                {filtered.map(item => (
                    <button
                        type="button"
                        className={item.uid === activeUid ? 'is-active' : ''}
                        onClick={() => onSelect(item.uid)}
                        key={item.uid}
                        title={item.title || 'New conversation'}
                    >
                        <span className="wc-conversation-icon">{item.pinned ? <Pin size={15} /> : <Bot size={15} />}</span>
                        {!collapsed && (
                            <>
                                <span className="wc-conversation-copy">
                                    <strong>{item.title || 'New conversation'}</strong>
                                    <small>{item.messageCount || 0} messages</small>
                                </span>
                                <span
                                    role="button"
                                    tabIndex={0}
                                    className="wc-conversation-menu"
                                    onClick={event => {event.stopPropagation(); onArchive(item.uid);}}
                                    onKeyDown={event => {
                                        if (event.key === 'Enter') {
                                            event.stopPropagation();
                                            onArchive(item.uid);
                                        }
                                    }}
                                    title="Archive conversation"
                                ><Archive size={14} /></span>
                            </>
                        )}
                    </button>
                ))}
            </nav>
            {!collapsed && (
                <div className="wc-thanks">
                    <Sparkles size={15} />
                    <p><strong>Thank you, Netresearch.</strong><br />Inspired by nr-mcp-agent and powered by nr-llm.</p>
                </div>
            )}
        </aside>
    );
}

function OperatorApp({maxLength = 10000, variant = 'module'}) {
    const inline = variant === 'inline';
    const [loading, setLoading] = useState(true);
    const [capabilities, setCapabilities] = useState({available: false, issues: []});
    const [conversations, setConversations] = useState([]);
    const [activeUid, setActiveUid] = useState(null);
    const [messages, setMessages] = useState([]);
    const [threadVersion, setThreadVersion] = useState(0);
    const [meta, setMeta] = useState({status: 'idle', executionTrace: [], pendingApproval: []});
    const [railCollapsed, setRailCollapsed] = useState(inline);
    const [ledgerOpen, setLedgerOpen] = useState(!inline);
    const [error, setError] = useState('');
    const [approvalBusy, setApprovalBusy] = useState(false);
    const [executionMode, setExecutionMode] = useState('direct');
    const [pageUid, setPageUid] = useState(() => Number(new URLSearchParams(globalThis.location.search).get('id') || 0));

    const loadConversations = useCallback(async () => {
        const result = await api.conversations();
        setConversations(result.conversations || []);
        return result.conversations || [];
    }, []);

    const selectConversation = useCallback(async uid => {
        setActiveUid(uid);
        setError('');
        const result = await api.messages(uid, 0);
        setMessages(result.messages || []);
        setMeta(result);
        setThreadVersion(version => version + 1);
    }, []);

    useEffect(() => {
        Promise.all([api.status(), loadConversations()])
            .then(([status, items]) => {
                setCapabilities(status);
                if (items.length > 0) return selectConversation(items[0].uid);
                return null;
            })
            .catch(exception => setError(exception.message))
            .finally(() => setLoading(false));
    }, [loadConversations, selectConversation]);

    const createConversation = useCallback(async () => {
        try {
            const result = await api.createConversation();
            await loadConversations();
            await selectConversation(result.uid);
        } catch (exception) {
            setError(exception.message);
        }
    }, [loadConversations, selectConversation]);

    const archiveConversation = useCallback(async uid => {
        try {
            await api.archive(uid);
            const items = await loadConversations();
            if (uid === activeUid) {
                if (items[0]) await selectConversation(items[0].uid);
                else {
                    setActiveUid(null);
                    setMessages([]);
                }
            }
        } catch (exception) {
            setError(exception.message);
        }
    }, [activeUid, loadConversations, selectConversation]);

    const onServerUpdate = useCallback(data => {
        setMeta(previous => ({...previous, ...data}));
        setConversations(previous => previous.map(item => item.uid === activeUid
            ? {...item, status: data.status || item.status, messageCount: data.totalCount ?? item.messageCount}
            : item));
    }, [activeUid]);

    const decideApproval = useCallback(async approved => {
        if (!activeUid) return;
        setApprovalBusy(true);
        setError('');
        try {
            await api.approve(activeUid, approved);
            await selectConversation(activeUid);
            await loadConversations();
        } catch (exception) {
            setError(exception.message);
        } finally {
            setApprovalBusy(false);
        }
    }, [activeUid, loadConversations, selectConversation]);

    if (loading) {
        return <div className="wc-loading"><div className="wc-loader" /><span>Preparing the TYPO3 operator…</span></div>;
    }

    const active = conversations.find(item => item.uid === activeUid);
    return (
        <div className={`wc-operator ${ledgerOpen ? 'has-ledger' : ''} ${inline ? 'is-inline' : 'is-module'}`}>
            <ConversationRail
                items={conversations}
                activeUid={activeUid}
                onSelect={selectConversation}
                onCreate={createConversation}
                onArchive={archiveConversation}
                collapsed={railCollapsed}
                setCollapsed={setRailCollapsed}
            />
            <main className="wc-workspace">
                <header className="wc-topbar">
                    <div className="wc-topbar-title">
                        <button className="wc-icon-button wc-mobile-menu" type="button" onClick={() => setRailCollapsed(!railCollapsed)}><Menu size={18} /></button>
                        <div>
                            <span className="wc-eyebrow">TYPO3 OPERATOR CONSOLE</span>
                            <h1>{active?.title || 'New conversation'}</h1>
                        </div>
                    </div>
                    <div className="wc-topbar-actions">
                        {inline && (
                            <button className="wc-icon-button wc-inline-new-chat" type="button" onClick={createConversation} aria-label="New conversation" title="New conversation">
                                <MessageSquarePlus size={17} />
                            </button>
                        )}
                        {capabilities.flueAvailable && (
                            <div className="wc-execution-mode" aria-label="Execution mode">
                                <button type="button" className={executionMode === 'direct' ? 'is-active' : ''} onClick={() => setExecutionMode('direct')}>
                                    <Zap size={13} /> Direct
                                </button>
                                <button type="button" className={executionMode === 'flue' ? 'is-active' : ''} onClick={() => setExecutionMode('flue')}>
                                    <Play size={13} /> Flue
                                </button>
                                {executionMode === 'flue' && (
                                    <label>
                                        <span>Page UID</span>
                                        <input type="number" min="1" value={pageUid || ''} onChange={event => setPageUid(Number(event.target.value || 0))} />
                                    </label>
                                )}
                            </div>
                        )}
                        <StatusPill status={meta.status || active?.status || 'idle'} />
                        <button className={`wc-ledger-toggle ${ledgerOpen ? 'is-active' : ''}`} type="button" onClick={() => setLedgerOpen(!ledgerOpen)}>
                            <Wrench size={15} /><span>Run ledger</span>
                        </button>
                        <button className="wc-icon-button" type="button" aria-label="More conversation actions"><MoreHorizontal size={18} /></button>
                    </div>
                </header>
                {capabilities.issues?.length > 0 && (
                    <div className="wc-notice">
                        <CircleAlert size={17} />
                        <div>{capabilities.issues.map(issue => <p key={issue}>{issue}</p>)}</div>
                    </div>
                )}
                {error && (
                    <div className="wc-error" role="alert">
                        <CircleAlert size={17} /><span>{error}</span>
                        <button type="button" onClick={() => setError('')} aria-label="Dismiss"><X size={15} /></button>
                    </div>
                )}
                <div className="wc-chat-stage">
                    {activeUid ? (
                        <ChatThread
                            key={`${activeUid}-${threadVersion}`}
                            conversationUid={activeUid}
                            initialMessages={messages}
                            initialCount={messages.length}
                            available={capabilities.available}
                            maxLength={maxLength}
                            onServerUpdate={onServerUpdate}
                            executionMode={executionMode}
                            pageUid={pageUid}
                        />
                    ) : (
                        <div className="wc-no-conversation">
                            <Sparkles size={26} />
                            <h2>Start an operator session</h2>
                            <p>Create a conversation to inspect or change this TYPO3 installation with governed tools.</p>
                            <button type="button" onClick={createConversation}><MessageSquarePlus size={16} /> New conversation</button>
                        </div>
                    )}
                </div>
                <div className="wc-credit-line">
                    Built with gratitude on Netresearch’s nr-mcp-agent foundation. Thank you, Netresearch, for nr-llm, nr-vault, and the original TYPO3 AI Chat.
                </div>
            </main>
            {ledgerOpen && <ExecutionLedger meta={meta} onApproval={decideApproval} approvalBusy={approvalBusy} />}
        </div>
    );
}

class Typo3AiChatElement extends HTMLElement {
    connectedCallback() {
        if (this._root) return;
        this._root = createRoot(this);
        this._root.render(
            <OperatorApp
                maxLength={Number(this.dataset.maxLength || 10000)}
                variant={this.dataset.variant || 'module'}
            />,
        );
    }

    disconnectedCallback() {
        this._root?.unmount();
        this._root = null;
    }
}

if (!customElements.get('wc-typo3-ai-chat')) {
    customElements.define('wc-typo3-ai-chat', Typo3AiChatElement);
}
