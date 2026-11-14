import type { NextConfig } from 'next';
import { PHASE_PRODUCTION_BUILD, PHASE_PRODUCTION_SERVER } from 'next/constants';

import { assertProductionEnv } from './lib/env';
import { withMonitoring } from './lib/sentry-build';
import { baselineCsp, securityHeaders } from './lib/security-headers';

const nextConfig: NextConfig = {
    // The E2E suite builds into its own directory with fake credentials so it never
    // clobbers a developer's `.next` (see playwright.config.ts).
    distDir: process.env.NEXT_DIST_DIR ?? '.next',
    reactStrictMode: true,
    poweredByHeader: false,
    // nosniff, referrer policy, permissions policy and HSTS everywhere. Pages get their CSP (script
    // policy + frame-ancestors) from proxy.ts; a CSP here would replace it, so only route handlers
    // get the baseline one (lib/security-headers.ts).
    async headers() {
        return [
            { source: '/:path*', headers: securityHeaders() },
            { source: '/api/:path*', headers: [baselineCsp({ ADMIN_URL: process.env.ADMIN_URL, API_URL: process.env.API_URL })] },
        ];
    },
    // Static art (Home hero = LCP, About collage) is served AVIF with a WebP fallback
    // (storefront spec §B1.0). Catalog photos come pre-encoded from the media pipeline.
    images: { formats: ['image/avif', 'image/webp'] },
    // SEO: which user agents get BLOCKING metadata (all <head> tags in the first HTML) instead of
    // streamed metadata. Next.js docs (16.x):
    //   https://nextjs.org/docs/app/api-reference/functions/generate-metadata#streaming-metadata
    //   https://nextjs.org/docs/app/api-reference/config/next-config-js/htmlLimitedBots
    // Streaming lowers TTFB/LCP for people; Next.js has verified that JS-executing crawlers that read
    // the full DOM (Googlebot) handle it; HTML-limited bots that read only <head> get a blocking
    // render. `/.*/` (what we had) disables streaming for every visitor, so every shopper's TTFB
    // waited on the metadata fetches — the docs warn overriding "could lead to longer response
    // times". Setting this REPLACES Next's default list, so it is Next 16.4's own list
    // (next/dist/shared/lib/router/utils/html-bots.js — Google's non-Googlebot crawlers,
    // Bing, Yandex, Baidu, Apple, Facebook/Messenger, WhatsApp, LinkedIn, X, Slack, Discord…)
    // plus TelegramBot, a link-preview scraper our shoppers share to that the default list misses.
    // Re-check against that file when upgrading Next. (Per the same docs, a prerendered page whose
    // generateMetadata is not dynamic has its metadata in the initial HTML anyway.)
    htmlLimitedBots:
        /[\w-]+-Google|Google-[\w-]+|Chrome-Lighthouse|Slurp|DuckDuckBot|baiduspider|yandex|sogou|bitlybot|tumblr|vkShare|quora link preview|redditbot|ia_archiver|Bingbot|BingPreview|applebot|facebookexternalhit|facebookcatalog|Twitterbot|LinkedInBot|Slackbot|Discordbot|WhatsApp|SkypeUriPreview|Yeti|googleweblight|TelegramBot/i,
    experimental: {
        // app/global-not-found.tsx: a server-rendered, localised 404 for unmatched URLs. The root
        // layout is the dynamic app/[locale], so there is no single layout to compose one from.
        globalNotFound: true,
    },
    // No `env` block and no NEXT_PUBLIC_* secrets: API credentials stay server-side (CLAUDE.md rule 28).
};

// `next build` and `next start` refuse an incomplete production environment (lib/env.ts); `next dev`,
// and the unit tests do not check it. `next typegen` (npm run typecheck) loads the config in the
// build phase too, but only generates route types, so it is recognised by its CLI command and skipped.
export default function config(phase: string): NextConfig {
    const typegen = process.argv.slice(2).includes('typegen');
    if ((phase === PHASE_PRODUCTION_BUILD || phase === PHASE_PRODUCTION_SERVER) && !typegen) assertProductionEnv(process.env);
    // Sentry (P10): wraps the config only when a DSN is configured — lib/sentry-build.ts.
    return withMonitoring(nextConfig);
}
