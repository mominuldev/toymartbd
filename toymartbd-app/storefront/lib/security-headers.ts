// Response security headers for every storefront route (P9 security review, docs/audits/SECURITY-REVIEW.md).
//
// Read by next.config.ts `headers()`, which Next evaluates at BUILD time — ADMIN_URL / API_URL must be
// set for `next build` (lib/env.ts enforces it in production).
//
// Framing: the only page that may be framed is the homepage preview inside the admin's live builder
// (components/content/PreviewBridge.tsx), so `frame-ancestors` allows this site and the admin origin and
// nothing else (clickjacking). X-Frame-Options cannot name a second origin, so it is not sent; every
// browser we support honours frame-ancestors.
//
// Content-Security-Policy is NOT in this list. Pages get the full script policy from proxy.ts
// (lib/csp.ts), and a `headers()` entry with the same key REPLACES the proxy's response header — so a
// CSP here would silently erase the page policy (it did, in the first S-11 run). Route handlers under
// /api get `baselineCsp` through their own next.config rule instead.

import { frameAncestors } from './csp';

export interface SecurityHeader {
    key: string;
    value: string;
}

/** The CSP for non-page responses (route handlers): no framing beyond the admin, no base, no plugins. */
export function baselineCsp(env: { ADMIN_URL?: string; API_URL?: string }): SecurityHeader {
    return { key: 'Content-Security-Policy', value: `frame-ancestors ${frameAncestors(env)}; base-uri 'self'; object-src 'none'` };
}

/** Every route, pages included. Deliberately no CSP (see above). */
export function securityHeaders(): SecurityHeader[] {
    return [
        { key: 'X-Content-Type-Options', value: 'nosniff' },
        { key: 'Referrer-Policy', value: 'strict-origin-when-cross-origin' },
        { key: 'Permissions-Policy', value: 'camera=(), microphone=(), geolocation=(), payment=(), usb=()' },
        // Browsers ignore HSTS on plain http, so this is inert on localhost and binding in production.
        { key: 'Strict-Transport-Security', value: 'max-age=31536000; includeSubDomains' },
    ];
}
