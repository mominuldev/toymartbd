import { describe, expect, it } from 'vitest';

import config from '../next.config';
import { contentSecurityPolicy } from '../lib/csp';
import { baselineCsp, securityHeaders } from '../lib/security-headers';

function header(list: Array<{ key: string; value: string }>, key: string): string | undefined {
    return list.find((h) => h.key.toLowerCase() === key.toLowerCase())?.value;
}

describe('storefront security headers', () => {
    it('lets only this site and the admin origin frame a page (clickjacking)', () => {
        const env = { ADMIN_URL: 'https://admin.toymartbd.test/admin', API_URL: 'https://api.internal' };
        expect(baselineCsp(env).value).toContain("frame-ancestors 'self' https://admin.toymartbd.test;");
        expect(contentSecurityPolicy(env, null)).toContain("frame-ancestors 'self' https://admin.toymartbd.test;");
        expect(header(securityHeaders(), 'X-Frame-Options')).toBeUndefined();
    });

    it('falls back to the API origin, and to self alone when neither parses', () => {
        expect(baselineCsp({ API_URL: 'http://127.0.0.1:8000' }).value).toContain("frame-ancestors 'self' http://127.0.0.1:8000;");
        expect(baselineCsp({ API_URL: 'not a url' }).value).toContain("frame-ancestors 'self';");
    });

    it('sends nosniff, a referrer policy, a permissions policy and HSTS', () => {
        const list = securityHeaders();
        expect(header(list, 'X-Content-Type-Options')).toBe('nosniff');
        expect(header(list, 'Referrer-Policy')).toBe('strict-origin-when-cross-origin');
        expect(header(list, 'Permissions-Policy')).toContain('camera=()');
        expect(header(list, 'Strict-Transport-Security')).toMatch(/^max-age=\d{8,}/);
    });

    it('applies them to every route through next.config headers(), and a CSP only to route handlers', async () => {
        const rules = await config('phase-development-server').headers!();
        const all = rules.find((r) => r.source === '/:path*');
        expect(header(all!.headers, 'X-Content-Type-Options')).toBe('nosniff');
        // A headers() CSP on pages would REPLACE proxy.ts's page policy (S-11).
        expect(header(all!.headers, 'Content-Security-Policy')).toBeUndefined();
        const api = rules.find((r) => r.source === '/api/:path*');
        expect(header(api!.headers, 'Content-Security-Policy')).toContain("object-src 'none'");
        expect(rules).toHaveLength(2);
    });
});
