import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';
import { fileURLToPath, URL } from 'node:url';
import { sentryVitePlugin } from '@sentry/vite-plugin';

// Admin source maps go to Sentry at build time only when the CI has an auth token (P10,
// docs/ops/observability.md). Then maps are built "hidden" (no sourceMappingURL comment),
// uploaded under SENTRY_RELEASE (the git SHA — the release the admin reports at runtime) and
// deleted from public/build, so they are never served. Without the token: no maps, no upload,
// no network call. Telemetry to Sentry is always off.
const sentryUpload = Boolean(process.env.SENTRY_AUTH_TOKEN && process.env.SENTRY_ORG && process.env.SENTRY_ADMIN_PROJECT);

// This Vite build serves ONE app: the admin SPA at /admin, hosted by Laravel.
// The customer storefront is a separate Next.js app in toymartbd-app/storefront
// and is never part of this bundle.
export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/admin.css', 'resources/js/admin/main.tsx'],
            refresh: ['resources/views/**', 'routes/**', 'app/**'],
        }),
        react(),
        tailwindcss(),
        ...(sentryUpload
            ? [
                  sentryVitePlugin({
                      org: process.env.SENTRY_ORG,
                      project: process.env.SENTRY_ADMIN_PROJECT,
                      authToken: process.env.SENTRY_AUTH_TOKEN,
                      url: process.env.SENTRY_URL,
                      telemetry: false,
                      release: { name: process.env.SENTRY_RELEASE, inject: false },
                      sourcemaps: { filesToDeleteAfterUpload: ['public/build/**/*.map'] },
                  }),
              ]
            : []),
    ],
    build: {
        sourcemap: sentryUpload ? 'hidden' : false,
    },
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./resources/js/admin', import.meta.url)),
            '@tokens': fileURLToPath(new URL('./packages/tokens/src', import.meta.url)),
        },
    },
    server: {
        watch: { ignored: ['**/storage/framework/views/**'] },
    },
});
