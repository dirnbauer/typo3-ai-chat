import { StrictMode } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { ModuleSurface } from '@/components/chat/module-surface';
import { PanelSurface } from '@/components/chat/panel-surface';
import { PortalContainerProvider } from '@/lib/portal';
import { TooltipProvider } from '@/components/ui/tooltip';
import { colorSchemeValue, observeScheme, type ColorScheme } from '@/lib/theme';
import styles from '@/styles/tailwind.css?inline';

/**
 * `<wc-ai-chat>` — the whole of the chat's public surface.
 *
 * Two variants and one implementation, as ADR-016 decided:
 *
 *   <wc-ai-chat variant="panel" [open]>       appended to the TOP document
 *   <wc-ai-chat variant="module" data-conversation="12">   rendered by Fluid
 *
 * Everything below the element is a React tree inside a shadow root. The shadow
 * root is why the backend's stylesheet and the chat's cannot fight, and it is
 * also why three things have to be arranged by hand that a normal page gets for
 * free: the stylesheet (adopted, not linked), the colour scheme (mirrored, not
 * inherited) and the portal container (given to Radix, not defaulted to
 * `document.body`).
 */

/**
 * One `CSSStyleSheet` for every instance on the page.
 *
 * `adoptedStyleSheets` shares a constructed sheet across shadow roots, so the
 * panel and a module element on the same document parse the CSS once and the
 * browser keeps one copy. Building a sheet per element would also mean one
 * re-parse per element on every theme change.
 */
let sheet: CSSStyleSheet | null = null;

function styleSheet(): CSSStyleSheet | null {
  if (sheet !== null) {
    return sheet;
  }
  try {
    const constructed = new CSSStyleSheet();
    constructed.replaceSync(styles);
    sheet = constructed;

    return sheet;
  } catch {
    // Constructable stylesheets are everywhere the backend runs, but a test
    // environment (jsdom) is not a browser. Falling back to a <style> element
    // keeps the element mountable there.
    return null;
  }
}

export class WcAiChatElement extends HTMLElement {
  static readonly tagName = 'wc-ai-chat';

  static get observedAttributes(): string[] {
    return ['open', 'variant', 'data-conversation'];
  }

  private root: Root | null = null;

  private mount: HTMLDivElement | null = null;

  private portal: HTMLDivElement | null = null;

  private stopObservingTheme: (() => void) | null = null;

  private scheme: ColorScheme = 'auto';

  connectedCallback(): void {
    if (this.shadowRoot === null) {
      const shadow = this.attachShadow({ mode: 'open' });

      const constructed = styleSheet();
      if (constructed === null) {
        const style = document.createElement('style');
        style.textContent = styles;
        shadow.appendChild(style);
      } else {
        shadow.adoptedStyleSheets = [constructed];
      }

      this.mount = document.createElement('div');
      shadow.appendChild(this.mount);

      // Radix mounts every floating layer here. It is a sibling of the mount
      // rather than a child so that a dialog is never inside the element it
      // covers, which is what `aria-hidden` on the rest of the tree expects.
      this.portal = document.createElement('div');
      this.portal.className = 'wc-root';
      this.portal.dataset.slot = 'portal';
      shadow.appendChild(this.portal);
    }

    this.stopObservingTheme = observeScheme(this, (scheme) => {
      this.scheme = scheme;
      this.applyScheme();
    });

    this.render();
  }

  disconnectedCallback(): void {
    this.stopObservingTheme?.();
    this.stopObservingTheme = null;

    // Unmounting in a microtask: a `connectedCallback` that follows a move in
    // the DOM would otherwise race an unmount React has not finished.
    const root = this.root;
    this.root = null;
    queueMicrotask(() => root?.unmount());
  }

  attributeChangedCallback(): void {
    if (this.isConnected) {
      this.render();
    }
  }

  /**
   * The backend's light/dark choice, put where `light-dark()` inside the shadow
   * can see it.
   *
   * It is set on the HOST, not on the mount: `color-scheme` is inherited, so
   * the host is the one place that covers the mount, the portal and anything
   * Radix puts inside either.
   */
  private applyScheme(): void {
    this.style.colorScheme = colorSchemeValue(this.scheme);
    // A data attribute as well, so a component can branch on the theme without
    // reading a computed style — and so a test can assert what was mirrored.
    this.dataset.scheme = this.scheme;
    if (this.mount !== null) {
      this.mount.dataset.scheme = this.scheme;
    }
    if (this.portal !== null) {
      this.portal.dataset.scheme = this.scheme;
      this.portal.style.colorScheme = colorSchemeValue(this.scheme);
    }
  }

  private render(): void {
    if (this.mount === null) {
      return;
    }
    this.root ??= createRoot(this.mount);
    this.applyScheme();

    const variant = this.getAttribute('variant') === 'module' ? 'module' : 'panel';
    const conversation = Number.parseInt(this.dataset.conversation ?? '', 10);

    this.root.render(
      <StrictMode>
        <PortalContainerProvider container={this.portal}>
          <TooltipProvider delayDuration={400}>
            {variant === 'module' ? (
              <ModuleSurface conversationUid={Number.isFinite(conversation) ? conversation : 0} />
            ) : (
              <PanelSurface open={this.hasAttribute('open')} onRequestClose={() => this.close()} />
            )}
          </TooltipProvider>
        </PortalContainerProvider>
      </StrictMode>,
    );
  }

  /**
   * Close the panel and say so.
   *
   * The toolbar button owns `aria-expanded`, and it has no way to know the
   * panel closed itself unless the panel tells it. The event is composed so it
   * escapes the shadow root, and it bubbles so a listener on `document` — which
   * is where `toolbar/launcher.js` puts one — actually hears it.
   */
  close(): void {
    if (!this.hasAttribute('open')) {
      return;
    }
    this.removeAttribute('open');
    this.dispatchEvent(new CustomEvent('wc-ai-chat:closed', { bubbles: true, composed: true }));
  }
}

export function defineChatElement(): void {
  if (customElements.get(WcAiChatElement.tagName) === undefined) {
    customElements.define(WcAiChatElement.tagName, WcAiChatElement);
  }
}
