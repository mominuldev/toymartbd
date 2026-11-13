// Response security headers for every storefront route (P9 security review, docs/audits/SECURITY-REVIEW.md).
//
// Read by next.config.ts `headers()`, which Next evaluates at BUILD time — ADMIN_URL / API_URL must be
// set for `next build`, as they already are for the server.
//
// Framing: the only page that may be framed is the homepage preview inside the admin's live builder
// (components/content/PreviewBridge.tsx), so `frame-ancestors` allows this site and the admin origin and
// nothing else (clickjacking). X-Frame-Options cannot name a second origin, so it is not sent; every
// browser we support honours frame-ancestors.
//
// A full script CSP is deliberately not set here: Next's inline bootstrap scripts need per-request nonces
// threaded through proxy.ts, and the consented tracking tags load third-party scripts. Listed as a
// follow-up in the audit file rather than shipped half-working.

import { adminOrigin } from './content/preview-bridge';

export interface SecurityHeader {
    key: string;
    value: string;
}

export function securityHeaders(env: { ADMIN_URL?: string; API_URL?: string }): SecurityHeader[] {
    const admin = adminOrigin(env);
    const ancestors = ["'self'", ...(admin ? [admin] : [])].join(' ');

    return [
        { key: 'Content-Security-Policy', value: `frame-ancestors ${ancestors}; base-uri 'self'; object-src 'none'` },
        { key: 'X-Content-Type-Options', value: 'nosniff' },
        { key: 'Referrer-Policy', value: 'strict-origin-when-cross-origin' },
        { key: 'Permissions-Policy', value: 'camera=(), microphone=(), geolocation=(), payment=(), usb=()' },
        // Browsers ignore HSTS on plain http, so this is inert on localhost and binding in production.
        { key: 'Strict-Transport-Security', value: 'max-age=31536000; includeSubDomains' },
    ];
}
