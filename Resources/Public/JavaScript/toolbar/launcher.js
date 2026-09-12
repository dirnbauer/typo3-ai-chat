/**
 * The toolbar launcher.
 *
 * Everything this file does is put one <wc-ai-chat> element into the TOP
 * document and let it be found. The panel is a custom element defined by the
 * bundle in ../Dist/app.js; none of its behaviour lives here, because a
 * launcher that knows how the panel works is a second implementation of the
 * panel.
 *
 * It must run in the top document rather than inside a module iframe: the panel
 * has to survive module navigation, and an element inside the iframe is
 * destroyed by every click in the module menu.
 */

// TYPO3 appends a cache-busting query to the module URL it loads. A static
// relative import would drop that query, so the browser would happily keep
// serving the bundle from before the last extension update — the failure that
// looks like "my change did not deploy". Carrying the query across by hand is
// what keeps the two in step.
const bundleUrl = new URL('../Dist/app.js', import.meta.url);
bundleUrl.search = new URL(import.meta.url).search;

const ELEMENT = 'wc-ai-chat';

async function loadBundle() {
  try {
    await import(bundleUrl.href);
    return true;
  } catch (error) {
    // The bundle is built separately from the PHP package, so "not built yet"
    // is a real state of a development checkout. Say so once, in the console,
    // and leave the toolbar button inert rather than throwing on every page.
    console.warn('[typo3-ai-chat] The chat bundle could not be loaded.', error);
    return false;
  }
}

function panelElement() {
  const existing = document.querySelector(`${ELEMENT}[variant="panel"]`);
  if (existing) {
    return existing;
  }

  const panel = document.createElement(ELEMENT);
  panel.setAttribute('variant', 'panel');
  document.body.appendChild(panel);

  return panel;
}

function wire() {
  const button = document.querySelector('.ai-chat-toolbar-btn');
  if (!button || button.dataset.aiChatWired === '1') {
    return;
  }
  button.dataset.aiChatWired = '1';

  let loaded = null;

  const toggle = async (event) => {
    event.preventDefault();
    event.stopPropagation();

    loaded ??= loadBundle();
    if (!(await loaded)) {
      return;
    }

    const panel = panelElement();
    const open = panel.getAttribute('open') !== null;
    if (open) {
      panel.removeAttribute('open');
    } else {
      panel.setAttribute('open', '');
    }
    button.setAttribute('aria-expanded', String(!open));
  };

  button.addEventListener('click', toggle);
  button.addEventListener('keydown', (event) => {
    if (event.key === 'Enter' || event.key === ' ') {
      toggle(event);
    }
  });

  // The panel owns its own closing (backdrop, Escape, a close button), and
  // announces it so the toolbar button's aria-expanded stays truthful.
  document.addEventListener('wc-ai-chat:closed', () => {
    button.setAttribute('aria-expanded', 'false');
  });
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', wire);
} else {
  wire();
}
