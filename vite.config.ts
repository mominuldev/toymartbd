import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';
import { fileURLToPath, URL } from 'node:url';

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
    ],
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
