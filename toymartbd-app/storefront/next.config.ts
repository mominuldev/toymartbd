import type { NextConfig } from 'next';

const nextConfig: NextConfig = {
    // The E2E suite builds into its own directory with fake credentials so it never
    // clobbers a developer's `.next` (see playwright.config.ts).
    distDir: process.env.NEXT_DIST_DIR ?? '.next',
    reactStrictMode: true,
    poweredByHeader: false,
    // No `env` block and no NEXT_PUBLIC_* secrets: API credentials stay server-side (CLAUDE.md rule 28).
};

export default nextConfig;
