import { fileURLToPath } from 'node:url';
import { defineConfig } from 'vite';
import tailwindcss from '@tailwindcss/vite';

const here = (path: string) => fileURLToPath(new URL(path, import.meta.url));

/**
 * One file out, and everything in it.
 *
 * `Configuration/JavaScriptModules.php` publishes exactly one import specifier
 * and no libraries, so nothing this bundle needs can be resolved at runtime —
 * React, Radix and the markdown pipeline have to be inside it. That is the
 * trade ADR-016 made: a build step in exchange for dependencies that cannot
 * collide with another extension's copy on the backend's module graph.
 *
 * The output is deliberately boring, because CI asserts it byte for byte:
 * no hash in the name, no sourcemap, no code splitting, no dynamic chunk. A
 * build that produced a different file from the same input would turn that
 * gate into noise, and a gate people ignore is worse than no gate.
 */
export default defineConfig({
  plugins: [tailwindcss()],
  resolve: {
    alias: { '@': here('./src') },
  },
  define: {
    // React reads this off `process.env`, which does not exist in a browser.
    // Pinning it also drops React's development-only warning machinery from
    // the bundle, which is most of the difference in its size.
    'process.env.NODE_ENV': JSON.stringify('production'),
  },
  build: {
    outDir: here('../../Resources/Public/JavaScript/Dist'),
    // `.gitkeep` lives there and the directory is tracked; emptying it would
    // delete the marker that keeps the directory in git at all.
    emptyOutDir: false,
    target: 'es2022',
    sourcemap: false,
    cssCodeSplit: false,
    minify: 'esbuild',
    lib: {
      entry: here('./src/main.ts'),
      formats: ['es'],
      fileName: () => 'app.js',
    },
    rollupOptions: {
      // Nothing is external. See above.
      external: [],
      output: {
        // One file: a split chunk would be a second URL the import map does
        // not publish, so it could never be fetched.
        codeSplitting: false,
        entryFileNames: 'app.js',
        // A chunk name that never gets used, so a stray split fails loudly
        // instead of quietly publishing a second file the import map cannot
        // reach.
        chunkFileNames: 'unexpected-chunk-[name].js',
        assetFileNames: 'app.[ext]',
      },
    },
  },
});
