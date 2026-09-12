import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { act } from 'react';
import { defineChatElement, WcAiChatElement } from '@/element';
import type { ChatStatus } from '@/state/types';

/**
 * The element as the backend meets it.
 *
 * Two things are asserted here that no other test can reach, because both are
 * properties of the custom element rather than of a React tree: the shadow root
 * and what is inside it, and the colour scheme mirrored onto the host.
 */

const status: ChatStatus = {
  available: true,
  issues: [],
  configuration: { identifier: 'default', name: 'Default', provider: 'openai', model: 'gpt-5' },
  tools: [],
  budget: { allowed: true, reason: null },
  limits: {
    maxMessageLength: 4000,
    maxIterations: 10,
    turnsPerMinute: 10,
    turnsRemaining: 10,
    maxConversations: 50,
    maxActiveConversations: 3,
    activeConversations: 0,
  },
  suggestions: [],
  features: { sse: true, approvals: true, attachments: true },
};

function stubBackend(): void {
  window.TYPO3 = {
    settings: {
      ajaxUrls: {
        webconsulting_ai_chat_status: '/typo3/ajax/status?token=t',
        webconsulting_ai_chat_conversations: '/typo3/ajax/conversations?token=t',
        webconsulting_ai_chat_conversation_get: '/typo3/ajax/conversation?token=t',
      },
    },
  };

  vi.stubGlobal(
    'fetch',
    vi.fn(async (input: RequestInfo | URL) => {
      const url = String(input);
      let body: unknown = status;
      if (url.includes('/conversations?')) {
        body = { conversations: [] };
      } else if (url.includes('/conversation?')) {
        body = {
          conversation: {
            uid: 12,
            title: 'Test',
            status: 'idle',
            messageCount: 0,
            pinned: false,
            archived: false,
            autoApproveTools: false,
            runUuid: '',
            pendingApproval: {},
            errorMessage: '',
            lastMessageAt: 0,
            createdAt: 0,
          },
          messages: [],
        };
      }

      return new Response(JSON.stringify(body), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
      });
    }),
  );
}

async function mount(attributes: Record<string, string>): Promise<WcAiChatElement> {
  const element = document.createElement('wc-ai-chat') as WcAiChatElement;
  for (const [name, value] of Object.entries(attributes)) {
    element.setAttribute(name, value);
  }
  await act(async () => {
    document.body.appendChild(element);
  });

  return element;
}

beforeEach(() => {
  stubBackend();
  defineChatElement();
});

afterEach(() => {
  document.body.innerHTML = '';
  document.documentElement.removeAttribute('data-color-scheme');
  vi.unstubAllGlobals();
  delete window.TYPO3;
});

describe('<wc-ai-chat>', () => {
  it('registers itself once, idempotently', () => {
    defineChatElement();
    defineChatElement();

    expect(customElements.get('wc-ai-chat')).toBe(WcAiChatElement);
  });

  it('renders into an open shadow root, not into the backend document', async () => {
    const element = await mount({ variant: 'panel', open: '' });

    expect(element.shadowRoot).not.toBeNull();
    expect(element.shadowRoot?.querySelector('aside')).not.toBeNull();
    // The backend's own document never receives the chat's markup.
    expect(document.body.querySelector('aside')).toBeNull();
  });

  it('keeps a portal node beside the mount for Radix to use', async () => {
    const element = await mount({ variant: 'panel', open: '' });
    const portal = element.shadowRoot?.querySelector('[data-slot="portal"]');

    expect(portal).not.toBeNull();
    // A sibling, not a descendant: a dialog must never be inside the tree it
    // covers.
    expect(portal?.parentElement).toBeNull();
    expect(portal?.parentNode).toBe(element.shadowRoot);
  });

  it('renders nothing while the panel is closed', async () => {
    const element = await mount({ variant: 'panel' });

    expect(element.shadowRoot?.querySelector('aside')).toBeNull();
  });

  it('mirrors an explicit dark backend onto the host', async () => {
    document.documentElement.setAttribute('data-color-scheme', 'dark');
    const element = await mount({ variant: 'panel', open: '' });

    expect(element.style.colorScheme).toBe('only dark');
    expect(element.dataset.scheme).toBe('dark');
  });

  it('mirrors an explicit light backend onto the host', async () => {
    document.documentElement.setAttribute('data-color-scheme', 'light');
    const element = await mount({ variant: 'panel', open: '' });

    expect(element.style.colorScheme).toBe('only light');
  });

  it('follows the platform when the backend has made no choice', async () => {
    const element = await mount({ variant: 'panel', open: '' });

    expect(element.style.colorScheme).toBe('light dark');
    expect(element.dataset.scheme).toBe('auto');
  });

  it('follows the backend when the user switches without reloading', async () => {
    const element = await mount({ variant: 'panel', open: '' });

    await act(async () => {
      document.documentElement.setAttribute('data-color-scheme', 'dark');
    });
    await vi.waitFor(() => expect(element.style.colorScheme).toBe('only dark'));
  });

  it('mirrors onto the portal node as well, since a dialog is not inside the mount', async () => {
    document.documentElement.setAttribute('data-color-scheme', 'dark');
    const element = await mount({ variant: 'panel', open: '' });
    const portal = element.shadowRoot?.querySelector<HTMLElement>('[data-slot="portal"]');

    expect(portal?.style.colorScheme).toBe('only dark');
  });

  it('announces that it closed itself, composed so the event escapes the shadow root', async () => {
    const element = await mount({ variant: 'panel', open: '' });
    const heard = vi.fn();
    document.addEventListener('wc-ai-chat:closed', heard);

    await act(async () => {
      element.close();
    });

    expect(element.hasAttribute('open')).toBe(false);
    expect(heard).toHaveBeenCalledTimes(1);
    document.removeEventListener('wc-ai-chat:closed', heard);
  });

  it('stays quiet when close() is called on an already closed panel', async () => {
    const element = await mount({ variant: 'panel' });
    const heard = vi.fn();
    document.addEventListener('wc-ai-chat:closed', heard);

    element.close();

    expect(heard).not.toHaveBeenCalled();
    document.removeEventListener('wc-ai-chat:closed', heard);
  });

  it('renders the module variant on the conversation the URL asked for', async () => {
    const element = await mount({ variant: 'module', 'data-conversation': '12' });

    expect(element.shadowRoot?.querySelector('main')).not.toBeNull();
    expect(
      (globalThis.fetch as ReturnType<typeof vi.fn>).mock.calls.some(([url]) =>
        String(url).includes('conversation?'),
      ),
    ).toBe(true);
  });

  it('treats a missing conversation uid as none rather than as NaN', async () => {
    const element = await mount({ variant: 'module' });

    expect(element.shadowRoot?.querySelector('main')).not.toBeNull();
  });
});
