/**
 * `localStorage` that cannot take the chat down with it.
 *
 * Reading it throws outright in a few real situations — a browser configured to
 * block site data, Safari's private mode on an old release, an iframe whose
 * storage is partitioned away — and the chat's use for it is remembering a
 * drawer width. A preference is never worth an exception, so every access is
 * wrapped and every failure degrades to the caller's default.
 */

const PREFIX = 'wcAiChat.';

export function readSetting(key: string): string | null {
  try {
    return window.localStorage.getItem(PREFIX + key);
  } catch {
    return null;
  }
}

export function writeSetting(key: string, value: string): void {
  try {
    window.localStorage.setItem(PREFIX + key, value);
  } catch {
    // A preference that cannot be stored is still a preference that works for
    // this session; there is nothing useful to tell the user about it.
  }
}

export function readNumber(key: string, fallback: number): number {
  const raw = readSetting(key);
  if (raw === null) {
    return fallback;
  }
  const parsed = Number.parseFloat(raw);

  return Number.isFinite(parsed) ? parsed : fallback;
}

export function readBoolean(key: string, fallback: boolean): boolean {
  const raw = readSetting(key);

  return raw === null ? fallback : raw === '1';
}

export function writeBoolean(key: string, value: boolean): void {
  writeSetting(key, value ? '1' : '0');
}
