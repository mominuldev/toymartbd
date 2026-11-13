import type { NextConfig } from 'next';

const nextConfig: NextConfig = {
    // The E2E suite builds into its own directory with fake credentials so it never
    // clobbers a developer's `.next` (see playwright.config.ts).
    distDir: process.env.NEXT_DIST_DIR ?? '.next',
    reactStrictMode: true,
    poweredByHeader: false,
    // Static art (Home hero = LCP, About collage) is served AVIF with a WebP fallback
    // (storefront spec §B1.0). Catalog photos come pre-encoded from the media pipeline.
    images: { formats: ['image/avif', 'image/webp'] },
    // SEO (Phase 7): never stream metadata into <body>. Next streams generateMetadata output for
    // clients it does not recognise as bots; canonical, hreflang and robots must sit in the <head>
    // of the first HTML for every client (social scrapers, SEO tools, JS-off). Costs the metadata
    // fetches on TTFB, which are tag-cached.
    htmlLimitedBots: /.*/,
    experimental: {
        // app/global-not-found.tsx: a server-rendered, localised 404 for unmatched URLs. The root
        // layout is the dynamic app/[locale], so there is no single layout to compose one from.
        globalNotFound: true,
    },
    // No `env` block and no NEXT_PUBLIC_* secrets: API credentials stay server-side (CLAUDE.md rule 28).
};

export default nextConfig;
