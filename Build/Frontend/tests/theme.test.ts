import { afterEach, describe, expect, it, vi } from 'vitest';
import { colorSchemeValue, observeScheme, readScheme, resolvesDark } from '@/lib/theme';

/**
 * The mirror.
 *
 * A shadow root inherits custom properties from its host, so TYPO3's tokens do
 * reach the chat. What does not reach it is the rule that decides which half of
 * every `light-dark()` wins: TYPO3 applies that to `:root`, and `:root` is the
 * backend's document element, not ours. Copying the decision across is the
 * whole of lib/theme.ts, and getting it wrong is a dark backend with a white
 * chat in it.
 */

function host(): HTMLElement {
  const element = document.createElement('div');
  document.body.appendChild(element);

  return element;
}

afterEach(() => {
  document.documentElement.removeAttribute('data-color-scheme');
  document.body.innerHTML = '';
});

describe('readScheme', () => {
  it('reads an explicit dark setting', () => {
    document.documentElement.setAttribute('data-color-scheme', 'dark');

    expect(readScheme(host())).toBe('dark');
  });

  it('reads an explicit light setting', () => {
    document.documentElement.setAttribute('data-color-scheme', 'light');

    expect(readScheme(host())).toBe('light');
  });

  it('treats an absent attribute as auto, which is what TYPO3 defaults to', () => {
    expect(readScheme(host())).toBe('auto');
  });

  it('treats a value it does not recognise as auto rather than guessing', () => {
    document.documentElement.setAttribute('data-color-scheme', 'sepia');

    expect(readScheme(host())).toBe('auto');
  });
});

describe('colorSchemeValue', () => {
  it('pins the scheme when the user chose one', () => {
    expect(colorSchemeValue('dark')).toBe('only dark');
    expect(colorSchemeValue('light')).toBe('only light');
  });

  it('follows the platform when the user did not', () => {
    expect(colorSchemeValue('auto')).toBe('light dark');
  });
});

describe('resolvesDark', () => {
  it('answers from the setting when there is one', () => {
    expect(resolvesDark('dark', window)).toBe(true);
    expect(resolvesDark('light', window)).toBe(false);
  });

  it('asks the platform when the setting is auto', () => {
    const matchMedia = vi
      .spyOn(window, 'matchMedia')
      .mockReturnValue({ matches: true } as unknown as MediaQueryList);

    expect(resolvesDark('auto', window)).toBe(true);
    expect(matchMedia).toHaveBeenCalledWith('(prefers-color-scheme: dark)');
  });

  it('falls back to light where matchMedia throws', () => {
    vi.spyOn(window, 'matchMedia').mockImplementation(() => {
      throw new Error('not implemented');
    });

    expect(resolvesDark('auto', window)).toBe(false);
  });
});

describe('observeScheme', () => {
  it('reports the current scheme immediately', () => {
    document.documentElement.setAttribute('data-color-scheme', 'dark');
    const seen: string[] = [];

    const stop = observeScheme(host(), (scheme) => seen.push(scheme));
    stop();

    expect(seen).toEqual(['dark']);
  });

  it('reports a switch the user makes without reloading', async () => {
    const seen: string[] = [];
    const stop = observeScheme(host(), (scheme) => seen.push(scheme));

    document.documentElement.setAttribute('data-color-scheme', 'dark');
    await vi.waitFor(() => expect(seen).toContain('dark'));

    document.documentElement.setAttribute('data-color-scheme', 'light');
    await vi.waitFor(() => expect(seen).toContain('light'));

    stop();
  });

  it('stops reporting once it is stopped', async () => {
    const seen: string[] = [];
    const stop = observeScheme(host(), (scheme) => seen.push(scheme));
    stop();

    document.documentElement.setAttribute('data-color-scheme', 'dark');
    await new Promise((resolve) => setTimeout(resolve, 10));

    expect(seen).toEqual(['auto']);
  });

  it('listens for the platform flipping while the setting is auto', () => {
    const listeners: (() => void)[] = [];
    vi.spyOn(window, 'matchMedia').mockReturnValue({
      matches: false,
      addEventListener: (_: string, listener: () => void) => listeners.push(listener),
      removeEventListener: () => undefined,
    } as unknown as MediaQueryList);

    const seen: string[] = [];
    const stop = observeScheme(host(), (scheme) => seen.push(scheme));

    expect(listeners).toHaveLength(1);
    listeners[0]?.();
    stop();

    // Nothing on the document changed, and the element still re-evaluated —
    // which is the point: at sunset the operating system flips and TYPO3's own
    // `light dark` follows it silently.
    expect(seen).toEqual(['auto', 'auto']);
  });
});
