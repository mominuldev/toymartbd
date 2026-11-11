import { defineConfig } from 'vitest/config';
import react from '@vitejs/plugin-react';
import { fileURLToPath, URL } from 'node:url';

// Separate from vite.config.ts so tests do not boot the Laravel plugin.
export default defineConfig({
    plugins: [react()],
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./resources/js/admin', import.meta.url)),
            '@tokens': fileURLToPath(new URL('./packages/tokens/src', import.meta.url)),
        },
    },
    test: {
        environment: 'jsdom',
        include: ['resources/js/**/*.test.{ts,tsx}'],
        restoreMocks: true,
    },
});
