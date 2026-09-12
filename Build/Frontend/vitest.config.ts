import { fileURLToPath } from 'node:url';
import { defineConfig } from 'vitest/config';
import tailwindcss from '@tailwindcss/vite';

const here = (path: string) => fileURLToPath(new URL(path, import.meta.url));

export default defineConfig({
  // Tailwind runs in the test build too, so `tailwind.css?inline` is the real
  // stylesheet rather than an empty string. One test depends on that: the
  // shadow-root property fallback is a transform over Tailwind's own output,
  // and a Tailwind upgrade that changed its shape would otherwise pass.
  plugins: [tailwindcss()],
  resolve: {
    alias: { '@': here('./src') },
  },
  test: {
    environment: 'jsdom',
    globals: true,
    setupFiles: [here('./tests/setup.ts')],
    include: [here('./tests/**/*.test.{ts,tsx}')],
    css: true,
  },
});
