import {defineConfig} from 'vite';
import react from '@vitejs/plugin-react';

export default defineConfig({
    plugins: [react()],
    build: {
        target: 'es2022',
        outDir: 'Resources/Public/JavaScript/Dist',
        emptyOutDir: true,
        cssCodeSplit: false,
        sourcemap: true,
        chunkSizeWarningLimit: 700,
        rolldownOptions: {
            input: 'Build/Frontend/operator.jsx',
            output: {
                entryFileNames: 'operator.js',
                chunkFileNames: 'operator-[name].js',
                assetFileNames: asset => asset.name?.endsWith('.css') ? 'operator.css' : 'operator-[name][extname]',
                codeSplitting: false,
            },
        },
    },
});
