import { clsx, type ClassValue } from 'clsx';
import { twMerge } from 'tailwind-merge';

export function cn(...inputs: ClassValue[]): string {
  return twMerge(clsx(inputs));
}

/**
 * Bytes as an editor would say them.
 */
export function formatBytes(bytes: number): string {
  if (!Number.isFinite(bytes) || bytes <= 0) {
    return '0 B';
  }
  const units = ['B', 'kB', 'MB', 'GB'];
  const exponent = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), units.length - 1);
  const value = bytes / 1024 ** exponent;

  return `${value >= 10 || exponent === 0 ? Math.round(value) : value.toFixed(1)} ${units[exponent]}`;
}

/**
 * A tool run's duration, rounded to what a person can act on. Sub-second
 * precision matters for a read; a two-minute write does not need its
 * milliseconds.
 */
export function formatDuration(milliseconds: number): string {
  if (!Number.isFinite(milliseconds) || milliseconds < 0) {
    return '';
  }
  if (milliseconds < 1000) {
    return `${Math.round(milliseconds)} ms`;
  }
  if (milliseconds < 60_000) {
    return `${(milliseconds / 1000).toFixed(1)} s`;
  }

  return `${Math.floor(milliseconds / 60_000)} min ${Math.round((milliseconds % 60_000) / 1000)} s`;
}

export function formatNumber(value: number): string {
  return new Intl.NumberFormat(undefined).format(Math.round(value));
}

/**
 * A unix timestamp as a backend user reads it. The API speaks seconds.
 */
export function formatTimestamp(seconds: number): string {
  if (!seconds) {
    return '';
  }
  const date = new Date(seconds * 1000);
  const today = new Date();
  const sameDay =
    date.getFullYear() === today.getFullYear() &&
    date.getMonth() === today.getMonth() &&
    date.getDate() === today.getDate();

  return sameDay
    ? date.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' })
    : date.toLocaleString(undefined, {
        day: '2-digit',
        month: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
      });
}

/**
 * `typo3_WriteTable` is the name the runtime knows. `WriteTable` is the name a
 * TYPO3 integrator recognises, and the prefix says nothing they do not already
 * know from the badge next to it.
 */
export function toolDisplayName(name: string): string {
  return name.startsWith('typo3_') ? name.slice('typo3_'.length) : name;
}
