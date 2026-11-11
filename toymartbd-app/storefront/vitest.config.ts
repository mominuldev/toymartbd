import { fileURLToPath, URL } from 'node:url';
import { defineConfig } from 'vitest/config';

export default defineConfig({
    resolve: {
        alias: {
            '@/': fileURLToPath(new URL('./', import.meta.url)),
            // `server-only` throws outside the react-server condition; unit tests run in plain Node.
            'server-only': fileURLToPath(new URL('./tests/stubs/server-only.ts', import.meta.url)),
        },
    },
    test: {
        environment: 'node',
        include: ['tests/**/*.test.ts'],
        restoreMocks: true,
        unstubEnvs: true,
        unstubGlobals: true,
    },
});
