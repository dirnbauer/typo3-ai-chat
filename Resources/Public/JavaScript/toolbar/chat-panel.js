import ModuleMenu from '@typo3/backend/module-menu.js';
import '../Dist/operator.js';

class ChatPanelToolbarInit {
    static init() {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', () => ChatPanelToolbarInit._wire());
        } else {
            ChatPanelToolbarInit._wire();
        }
    }

    static _wire() {
        const btn = document.querySelector('.ai-chat-toolbar-btn');
        if (!btn) return;

        const shell = document.createElement('div');
        shell.className = 'wc-inline-shell';
        shell.hidden = true;
        shell.innerHTML = `
            <button class="wc-inline-backdrop" type="button" aria-label="Close TYPO3 AI Chat"></button>
            <section class="wc-inline-dialog" role="dialog" aria-modal="true" aria-label="TYPO3 AI Chat">
                <header class="wc-inline-dialog-header">
                    <div>
                        <span class="wc-inline-dialog-mark">✦</span>
                        <span><strong>TYPO3 AI Chat</strong><small>Thank you, Netresearch.</small></span>
                    </div>
                    <nav aria-label="Chat window actions">
                        <button class="wc-inline-expand" type="button" title="Open full operator module" aria-label="Open full operator module">↗</button>
                        <button class="wc-inline-close" type="button" title="Close" aria-label="Close">×</button>
                    </nav>
                </header>
                <wc-typo3-ai-chat data-variant="inline"></wc-typo3-ai-chat>
            </section>
        `;
        document.body.appendChild(shell);

        const close = () => {
            shell.classList.remove('is-open');
            btn.setAttribute('aria-expanded', 'false');
            window.setTimeout(() => {
                if (!shell.classList.contains('is-open')) shell.hidden = true;
            }, 220);
            btn.focus();
        };
        const open = () => {
            shell.hidden = false;
            window.requestAnimationFrame(() => shell.classList.add('is-open'));
            btn.setAttribute('aria-expanded', 'true');
            shell.querySelector('.wc-inline-close')?.focus();
        };

        btn.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            shell.classList.contains('is-open') ? close() : open();
        });
        btn.addEventListener('keydown', event => {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                shell.classList.contains('is-open') ? close() : open();
            }
        });
        shell.querySelector('.wc-inline-backdrop')?.addEventListener('click', close);
        shell.querySelector('.wc-inline-close')?.addEventListener('click', close);
        shell.querySelector('.wc-inline-expand')?.addEventListener('click', () => {
            close();
            ModuleMenu.App.showModule('webconsulting_ai_chat_chat');
        });
        document.addEventListener('keydown', event => {
            if (event.key === 'Escape' && shell.classList.contains('is-open')) close();
        });
    }
}

ChatPanelToolbarInit.init();
