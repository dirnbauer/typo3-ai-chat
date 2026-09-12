import '@testing-library/jest-dom/vitest';
import { afterEach, vi } from 'vitest';
import { cleanup } from '@testing-library/react';

/**
 * jsdom is not a browser, and the two gaps that matter here are both about the
 * things the element does on purpose.
 *
 * `matchMedia` does not exist at all, and `lib/theme.ts` asks it what the
 * operating system prefers whenever the backend's setting is "auto".
 *
 * The default is "light" and no listeners fire unless a test installs its own,
 * which keeps a test that is not about the theme from depending on one.
 */
if (typeof window !== 'undefined' && window.matchMedia === undefined) {
  window.matchMedia = ((query: string) => ({
    matches: false,
    media: query,
    onchange: null,
    addEventListener: () => undefined,
    removeEventListener: () => undefined,
    addListener: () => undefined,
    removeListener: () => undefined,
    dispatchEvent: () => false,
  })) as typeof window.matchMedia;
}

// Radix measures elements it is about to position. jsdom reports zero for all
// of it, which is fine — but ResizeObserver is missing entirely, and its
// absence throws rather than measuring zero.
if (typeof globalThis.ResizeObserver === 'undefined') {
  globalThis.ResizeObserver = class {
    observe(): void {}
    unobserve(): void {}
    disconnect(): void {}
  } as unknown as typeof ResizeObserver;
}

if (typeof Element !== 'undefined' && Element.prototype.scrollIntoView === undefined) {
  Element.prototype.scrollIntoView = () => undefined;
}

afterEach(() => {
  cleanup();
  vi.restoreAllMocks();
});
