/**
 * The backend's colour scheme, mirrored onto the shadow host.
 *
 * A shadow root inherits inherited CSS properties from its host, so TYPO3's
 * `--typo3-*` custom properties do reach the chat. What does NOT reach it
 * reliably is the decision those properties are resolved against: TYPO3 writes
 * its light/dark choice as `data-color-scheme` on `<html>` and lets a CSS rule
 * turn that into `color-scheme: only light|dark` for `:root`. `:root` is the
 * backend's document element, not ours, so the rule stops at the boundary and
 * every `light-dark()` inside the shadow would resolve against the browser's
 * default instead of the user's setting — a dark backend with a white chat.
 *
 * Mirroring means copying that one decision across, and then watching it: the
 * user can switch schemes without reloading, and `user-settings-manager.js`
 * does exactly that by rewriting the attribute on both the top document and the
 * module iframe.
 */

export type ColorScheme = 'light' | 'dark' | 'auto';

export const SCHEME_ATTRIBUTE = 'data-color-scheme';

/**
 * What TYPO3 says the scheme is, read from the document that owns the element.
 *
 * Anything that is not an explicit choice is `auto`, which is TYPO3's own
 * default and means "follow the operating system".
 */
export function readScheme(element: Element): ColorScheme {
  const value = element.ownerDocument.documentElement.getAttribute(SCHEME_ATTRIBUTE);

  return value === 'dark' || value === 'light' ? value : 'auto';
}

/**
 * The `color-scheme` value that makes `light-dark()` inside the shadow resolve
 * the way it resolves outside it.
 */
export function colorSchemeValue(scheme: ColorScheme): string {
  switch (scheme) {
    case 'dark':
      return 'only dark';
    case 'light':
      return 'only light';
    default:
      return 'light dark';
  }
}

/**
 * Whether the chat should paint dark right now.
 *
 * `auto` has no answer of its own, so it asks the platform — the same question
 * the browser answers for TYPO3's own `light-dark()`.
 */
export function resolvesDark(scheme: ColorScheme, view: Window): boolean {
  if (scheme !== 'auto') {
    return scheme === 'dark';
  }

  try {
    return view.matchMedia('(prefers-color-scheme: dark)').matches;
  } catch {
    // jsdom has no matchMedia unless a test installs one.
    return false;
  }
}

/**
 * Watch the backend's scheme and report every change, including the first.
 *
 * Returns the function that stops watching. The media-query listener matters as
 * much as the attribute observer: while the setting is `auto`, nothing on the
 * document changes when the operating system flips at sunset.
 */
export function observeScheme(element: Element, onChange: (scheme: ColorScheme) => void): () => void {
  const root = element.ownerDocument.documentElement;
  const view = element.ownerDocument.defaultView ?? window;

  const report = () => onChange(readScheme(element));
  report();

  const observer = new MutationObserver(report);
  observer.observe(root, { attributes: true, attributeFilter: [SCHEME_ATTRIBUTE] });

  let media: MediaQueryList | null = null;
  try {
    media = view.matchMedia('(prefers-color-scheme: dark)');
    media.addEventListener('change', report);
  } catch {
    media = null;
  }

  return () => {
    observer.disconnect();
    media?.removeEventListener('change', report);
  };
}
