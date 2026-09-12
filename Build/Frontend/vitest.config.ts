import { fileURLToPath } from 'node:url';
import { defineConfig } from 'vitest/config';

const here = (path: string) => fileURLToPath(new URL(path, import.meta.url));

export default defineConfig({
  resolve: {
    alias: { '@': here('./src') },
  },
  test: {
    environment: 'jsdom',
    globals: true,
    setupFiles: [here('./tests/setup.ts')],
    include: [here('./tests/**/*.test.{ts,tsx}')],
    css: false,
  },
});
