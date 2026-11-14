// Script Content-Security-Policy for storefront pages (audit S-11, docs/audits/SECURITY-REVIEW.md).
//
// Two policies, chosen per route by proxy.ts:
//
//  • NONCE  — the per-request pages (cart, checkout, account, order, tracking, search…: every page
//    that already exports `dynamic = 'force-dynamic'`). proxy.ts mints a fresh nonce, puts the policy
//    on the REQUEST so Next reads the nonce and stamps it on its own scripts, and on the response.
//    `'strict-dynamic'` lets those trusted scripts load the rest (route chunks, next/script's
//    consent-gated GA4 / Meta tags); any other inline or injected <script> is refused.
//
//  • STATIC — the ISR / prerendered pages (home, product, category, brand, CMS, blog…). Their HTML is
//    rendered once and served to everybody from the cache, so it cannot carry a per-request nonce,
//    and Next's inline RSC payload scripts change with every revalidation, so they cannot be hashed
//    up front either. These pages keep `'unsafe-inline'` for INLINE scripts but restrict every
//    script URL to this origin and the two tracking hosts — an injected `<script src>` from anywhere
//    else, `<object>`, `<base>` hijacking, off-site form posts and exfiltration through fetch/XHR
//    to an unlisted host are all refused. Making these pages dynamic to give them nonces would end
//    ISR for the whole catalogue (measured trade-off in SECURITY-REVIEW.md S-11).
//
// The page HTML on both kinds is sanitised server-side (sanitize-html, twice for CMS/description
// HTML), so the inline-script gap on static pages is defence in depth, not the only defence.

import { adminOrigin } from './content/preview-bridge';

/**
 * Locale-independent paths of the per-request pages, `[param]` for a dynamic segment. Must equal the
 * set of app/[locale]/** /page.tsx files exporting `dynamic = 'force-dynamic'` — tests/csp.test.ts
 * enforces it, because a STATIC page given a nonce policy would have every script blocked.
 */
export const NONCE_ROUTES = [
    '/account',
    '/account/addresses',
    '/account/login',
    '/account/orders',
    '/account/orders/[number]',
    '/account/profile',
    '/account/verify',
    '/account/wishlist',
    '/blog',
    '/blog/preview/[token]',
    '/cart',
    '/checkout',
    '/delivery-charge',
    '/guides',
    '/orders/[number]',
    '/orders/[number]/payment',
    '/search',
    '/toys',
    '/track',
] as const;

const NONCE_PATTERNS = NONCE_ROUTES.map((route) => new RegExp(`^${route.replace(/\[[^\]]+\]/g, '[^/]+')}/?$`));

/** Is this locale-independent path one of the per-request pages that get a nonce policy? */
export function isNonceRoute(path: string): boolean {
    return NONCE_PATTERNS.some((re) => re.test(path));
}

/**
 * Hosts the consent-gated tags need (components/tracking/TrackingRoot.tsx, lib/tracking/browser.ts).
 *
 * Google: "Google Analytics" without Ads features, from Google's CSP guide
 * (https://developers.google.com/tag-platform/security/guides/csp, read 2026-11-14). Google Ads /
 * Signals features need more hosts (*.g.doubleclick.net, google.<TLD>) and are not enabled.
 * Meta: fbevents.js and its signals config load from connect.facebook.net and the pixel reports to
 * www.facebook.com/tr. UNVERIFIED: Meta publishes no CSP list; these are the hosts the pixel
 * script is documented to load and post to. Re-check with a real pixel before launch.
 */
export const TRACKING_SOURCES = {
    script: ['https://www.googletagmanager.com', 'https://connect.facebook.net'],
    img: ['https://www.googletagmanager.com', 'https://*.google-analytics.com', 'https://www.facebook.com'],
    connect: ['https://www.googletagmanager.com', 'https://*.google-analytics.com', 'https://*.google.com', 'https://www.facebook.com', 'https://connect.facebook.net'],
} as const;

/**
 * Where a no-JS checkout form may end up: POST /api/checkout answers 303 to the gateway's hosted
 * page, and browsers apply `form-action` to that redirect. These are SSLCommerz's sandbox and live
 * hosts from config/payments.php (tests/csp.test.ts keeps the two in step).
 */
export const PAYMENT_FORM_TARGETS = ['https://sandbox.sslcommerz.com', 'https://securepay.sslcommerz.com'] as const;

export interface CspEnv {
    ADMIN_URL?: string;
    API_URL?: string;
    /** Optional, comma-separated: extra origins product media is served from (a CDN / object store). */
    MEDIA_ORIGINS?: string;
    SITE_URL?: string;
    NODE_ENV?: string;
}

function origin(url: string | undefined): string | null {
    if (!url) return null;
    try {
        const parsed = new URL(url.trim());
        return parsed.protocol === 'https:' || parsed.protocol === 'http:' ? parsed.origin : null;
    } catch {
        return null;
    }
}

/** A fresh, unguessable nonce (128 bits, base64). Web Crypto: works in the Node and edge runtimes. */
export function newNonce(): string {
    const bytes = crypto.getRandomValues(new Uint8Array(16));
    return btoa(String.fromCharCode(...bytes));
}

/** `frame-ancestors`: this site and the admin's live homepage builder only (clickjacking). */
export function frameAncestors(env: Pick<CspEnv, 'ADMIN_URL' | 'API_URL'>): string {
    const admin = adminOrigin(env);
    return ["'self'", ...(admin ? [admin] : [])].join(' ');
}

/** The full page policy: a nonce policy when `nonce` is given, else the static (ISR) policy. */
export function contentSecurityPolicy(env: CspEnv, nonce: string | null): string {
    const dev = env.NODE_ENV === 'development';
    // React's dev build uses eval for server error stacks; never in production.
    const evalSrc = dev ? ["'unsafe-eval'"] : [];
    const script = nonce
        ? ["'self'", `'nonce-${nonce}'`, "'strict-dynamic'", ...TRACKING_SOURCES.script, ...evalSrc]
        : ["'self'", "'unsafe-inline'", ...TRACKING_SOURCES.script, ...evalSrc];

    const media = (env.MEDIA_ORIGINS ?? '')
        .split(',')
        .map((o) => origin(o))
        .filter((o): o is string => o !== null);
    const api = origin(env.API_URL);
    const img = [...new Set(["'self'", 'data:', 'blob:', ...(api ? [api] : []), ...media, ...TRACKING_SOURCES.img])];

    const directives = [
        `script-src ${script.join(' ')}`,
        "object-src 'none'",
        "base-uri 'self'",
        `form-action 'self' ${PAYMENT_FORM_TARGETS.join(' ')}`,
        `frame-ancestors ${frameAncestors(env)}`,
        `img-src ${img.join(' ')}`,
        `connect-src 'self' ${TRACKING_SOURCES.connect.join(' ')}`,
    ];
    if ((env.SITE_URL ?? '').startsWith('https://')) directives.push('upgrade-insecure-requests');
    return directives.join('; ');
}
