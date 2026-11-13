import { describe, expect, it } from 'vitest';

import nextConfig from '../next.config';
import { securityHeaders } from '../lib/security-headers';

function header(list: Array<{ key: string; value: string }>, key: string): string | undefined {
    return list.find((h) => h.key.toLowerCase() === key.toLowerCase())?.value;
}

describe('storefront security headers', () => {
    it('lets only this site and the admin origin frame a page (clickjacking)', () => {
        const list = securityHeaders({ ADMIN_URL: 'https://admin.toymartbd.test/admin', API_URL: 'https://api.internal' });
        expect(header(list, 'Content-Security-Policy')).toContain("frame-ancestors 'self' https://admin.toymartbd.test;");
        expect(header(list, 'X-Frame-Options')).toBeUndefined();
    });

    it('falls back to the API origin, and to self alone when neither parses', () => {
        expect(header(securityHeaders({ API_URL: 'http://127.0.0.1:8000' }), 'Content-Security-Policy')).toContain("frame-ancestors 'self' http://127.0.0.1:8000;");
        expect(header(securityHeaders({ API_URL: 'not a url' }), 'Content-Security-Policy')).toContain("frame-ancestors 'self';");
    });

    it('sends nosniff, a referrer policy, a permissions policy and HSTS', () => {
        const list = securityHeaders({});
        expect(header(list, 'X-Content-Type-Options')).toBe('nosniff');
        expect(header(list, 'Referrer-Policy')).toBe('strict-origin-when-cross-origin');
        expect(header(list, 'Permissions-Policy')).toContain('camera=()');
        expect(header(list, 'Strict-Transport-Security')).toMatch(/^max-age=\d{8,}/);
    });

    it('applies them to every route through next.config headers()', async () => {
        const rules = await nextConfig.headers!();
        expect(rules).toHaveLength(1);
        expect(rules[0]!.source).toBe('/:path*');
        expect(header(rules[0]!.headers, 'X-Content-Type-Options')).toBe('nosniff');
    });
});
