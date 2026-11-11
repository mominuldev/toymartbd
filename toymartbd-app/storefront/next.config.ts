import type { NextConfig } from 'next';

const nextConfig: NextConfig = {
    // The E2E suite builds into its own directory with fake credentials so it never
    // clobbers a developer's `.next` (see playwright.config.ts).
    distDir: process.env.NEXT_DIST_DIR ?? '.next',
    reactStrictMode: true,
    poweredByHeader: false,
    experimental: {
        // app/global-not-found.tsx: a server-rendered, localised 404 for unmatched URLs. The root
        // layout is the dynamic app/[locale], so there is no single layout to compose one from.
        globalNotFound: true,
    },
    // No `env` block and no NEXT_PUBLIC_* secrets: API credentials stay server-side (CLAUDE.md rule 28).
};

export default nextConfig;
