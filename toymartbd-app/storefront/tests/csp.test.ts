import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join, relative, sep } from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';

import { contentSecurityPolicy, isNonceRoute, newNonce, NONCE_ROUTES, PAYMENT_FORM_TARGETS, TRACKING_SOURCES } from '../lib/csp';

const root = fileURLToPath(new URL('..', import.meta.url));

function directive(policy: string, name: string): string[] {
    const found = policy
        .split(';')
        .map((d) => d.trim())
        .find((d) => d === name || d.startsWith(`${name} `));
    return found ? found.split(/\s+/).slice(1) : [];
}

function pages(dir: string): string[] {
    return readdirSync(dir).flatMap((name) => {
        const path = join(dir, name);
        return statSync(path).isDirectory() ? pages(path) : name === 'page.tsx' ? [path] : [];
    });
}

describe('storefront script CSP (S-11)', () => {
    const env = { API_URL: 'https://api.toymartbd.test', ADMIN_URL: 'https://admin.toymartbd.test/admin', SITE_URL: 'https://toymartbd.test', NODE_ENV: 'production' };

    it('gives per-request pages a nonce + strict-dynamic policy with no unsafe-inline or eval', () => {
        const policy = contentSecurityPolicy(env, 'abc123==');
        const script = directive(policy, 'script-src');
        expect(script).toEqual(expect.arrayContaining(["'self'", "'nonce-abc123=='", "'strict-dynamic'", ...TRACKING_SOURCES.script]));
        expect(script).not.toContain("'unsafe-inline'");
        expect(script).not.toContain("'unsafe-eval'");
    });

    it('gives ISR pages a host-restricted policy (no nonce, which cached HTML cannot carry)', () => {
        const script = directive(contentSecurityPolicy(env, null), 'script-src');
        expect(script).toEqual(["'self'", "'unsafe-inline'", ...TRACKING_SOURCES.script]);
        expect(script.join(' ')).not.toContain('nonce-');
    });

    it('locks objects, base, forms, framing, images and connections on both policies', () => {
        for (const policy of [contentSecurityPolicy(env, 'n0nce'), contentSecurityPolicy(env, null)]) {
            expect(directive(policy, 'object-src')).toEqual(["'none'"]);
            expect(directive(policy, 'base-uri')).toEqual(["'self'"]);
            expect(directive(policy, 'form-action')).toEqual(["'self'", ...PAYMENT_FORM_TARGETS]);
            expect(directive(policy, 'frame-ancestors')).toEqual(["'self'", 'https://admin.toymartbd.test']);
            expect(directive(policy, 'img-src')).toEqual(expect.arrayContaining(["'self'", 'data:', 'https://api.toymartbd.test', ...TRACKING_SOURCES.img]));
            expect(directive(policy, 'connect-src')).toEqual(["'self'", ...TRACKING_SOURCES.connect]);
            expect(policy).toContain('upgrade-insecure-requests');
        }
    });

    it('adds MEDIA_ORIGINS to img-src, ignoring junk, and skips upgrade-insecure-requests on http', () => {
        const policy = contentSecurityPolicy({ API_URL: 'http://127.0.0.1:8000', MEDIA_ORIGINS: 'https://cdn.toymartbd.test/m, not a url, javascript:alert(1)', SITE_URL: 'http://127.0.0.1:3000' }, null);
        const img = directive(policy, 'img-src');
        expect(img).toContain('https://cdn.toymartbd.test');
        expect(img).toContain('http://127.0.0.1:8000');
        expect(img.join(' ')).not.toContain('javascript');
        expect(policy).not.toContain('upgrade-insecure-requests');
    });

    it("allows 'unsafe-eval' only in development", () => {
        expect(directive(contentSecurityPolicy({ NODE_ENV: 'development' }, 'n'), 'script-src')).toContain("'unsafe-eval'");
        expect(directive(contentSecurityPolicy({ NODE_ENV: 'production' }, 'n'), 'script-src')).not.toContain("'unsafe-eval'");
    });

    it('mints a fresh 128-bit base64 nonce that Next can parse', () => {
        const a = newNonce();
        expect(a).toMatch(/^[A-Za-z0-9+/]{22}==$/);
        expect(newNonce()).not.toBe(a);
    });

    it('matches exactly the per-request routes, and nothing that is ISR', () => {
        for (const path of ['/cart', '/checkout', '/account', '/account/orders/TM-1001', '/orders/TM-1001/payment', '/blog', '/blog/preview/tok', '/toys', '/search', '/track/']) {
            expect(isNonceRoute(path), path).toBe(true);
        }
        for (const path of ['/', '/products/lego-city', '/categories/blocks', '/brands', '/blog/some-post', '/blog/preview', '/guides/age-3', '/pages/about', '/cartx', '/account/orders/1/extra']) {
            expect(isNonceRoute(path), path).toBe(false);
        }
    });

    it('lists exactly the pages that export dynamic = force-dynamic (a static page with a nonce policy would lose its scripts)', () => {
        const localeDir = join(root, 'app', '[locale]');
        const dynamic = pages(localeDir)
            .filter((file) => /export const dynamic = ['"]force-dynamic['"]/.test(readFileSync(file, 'utf8')))
            .map((file) => {
                const rel = relative(localeDir, file).split(sep).slice(0, -1).join('/');
                return rel === '' ? '/' : `/${rel}`;
            })
            .sort();
        expect([...NONCE_ROUTES].sort()).toEqual(dynamic);
    });

    it('allows the no-JS checkout redirect to every SSLCommerz host in config/payments.php', () => {
        const config = readFileSync(join(root, '..', '..', 'config', 'payments.php'), 'utf8');
        const hosts = [...config.matchAll(/'(https:\/\/[a-z0-9.-]*sslcommerz\.com)'/g)].map((m) => m[1]);
        expect(hosts.length).toBeGreaterThan(0);
        expect([...PAYMENT_FORM_TARGETS].sort()).toEqual([...new Set(hosts)].sort());
    });
});
