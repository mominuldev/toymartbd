import { expect, test, type BrowserContext, type Page } from '@playwright/test';

import { CONSENT_POLICY_VERSION } from './constants';

// S-11 in a real browser against the production build: per-request pages carry a fresh nonce on
// every script and a nonce + 'strict-dynamic' policy; ISR pages stay cached and get the static
// policy; and nothing the storefront itself loads — Next's chunks, hydration, the consent-gated
// GA4 / Meta tags — trips a CSP violation on either kind.

test.use({ javaScriptEnabled: true });

const PDP = '/products/lego-classic-creative-bricks?variant=501';
const ACCEPTED = { name: 'tm_consent', value: encodeURIComponent(`1|${CONSENT_POLICY_VERSION}|1|1|1763000000`), domain: '127.0.0.1', path: '/', expires: -1, httpOnly: false, secure: false, sameSite: 'Lax' as const };

function scriptSrc(policy: string): string[] {
    return (policy.split(';').map((d) => d.trim()).find((d) => d.startsWith('script-src ')) ?? '').split(/\s+/).slice(1);
}

/** Collects every CSP violation the page reports (console + the DOM event). */
async function watchViolations(context: BrowserContext, page: Page): Promise<string[]> {
    const violations: string[] = [];
    await context.addInitScript(() => {
        document.addEventListener('securitypolicyviolation', (e) => console.error(`CSP-VIOLATION ${e.violatedDirective} ${e.blockedURI}`));
    });
    page.on('console', (msg) => {
        const text = msg.text();
        if (text.startsWith('CSP-VIOLATION') || /Content Security Policy/i.test(text)) violations.push(text);
    });
    return violations;
}

test('a per-request page gets a fresh nonce, on every script, and no unsafe-inline', async ({ request }) => {
    const first = await request.get('/cart');
    const second = await request.get('/bn/cart');
    const policies = [first, second].map((r) => r.headers()['content-security-policy'] ?? '');
    const nonces = policies.map((p) => scriptSrc(p).find((s) => s.startsWith("'nonce-"))?.slice(7, -1));
    expect(nonces[0]).toBeTruthy();
    expect(nonces[1]).toBeTruthy();
    expect(nonces[0]).not.toBe(nonces[1]);
    for (const policy of policies) {
        expect(scriptSrc(policy)).toContain("'strict-dynamic'");
        expect(scriptSrc(policy)).not.toContain("'unsafe-inline'");
        expect(policy).toContain("object-src 'none'");
        expect(policy).toMatch(/frame-ancestors 'self'/);
    }
    // Every executable <script> in the HTML carries this response's nonce (JSON-LD data blocks are inert).
    const html = await first.text();
    const scripts = [...html.matchAll(/<script\b([^>]*)>/g)].map((m) => m[1]!).filter((attrs) => !/type="application\/ld\+json"/.test(attrs));
    expect(scripts.length).toBeGreaterThan(0);
    for (const attrs of scripts) expect(attrs).toContain(`nonce="${nonces[0]}"`);
});

test('a prerendered (ISR) page keeps its cache and gets the static policy (no nonce)', async ({ request }) => {
    for (const path of ['/brands', '/bn', '/flash-sale']) {
        await request.get(path);
        const cached = await request.get(path);
        expect(cached.headers()['x-nextjs-cache'], path).toMatch(/HIT|STALE/);
        const policy = cached.headers()['content-security-policy'] ?? '';
        expect(scriptSrc(policy)).toEqual(expect.arrayContaining(["'self'", "'unsafe-inline'", 'https://www.googletagmanager.com', 'https://connect.facebook.net']));
        expect(policy).not.toContain('nonce-');
        expect(policy).toContain("object-src 'none'");
        expect(await cached.text()).not.toMatch(/nonce="/);
    }
});

test('every page answers with a full script policy, and a nonce policy only where every script carries it', async ({ request }) => {
    const paths = ['/', '/bn', PDP, '/bn/products/lego-classic-creative-bricks', '/categories', '/categories/building-blocks', '/brands', '/brands/lego', '/flash-sale', '/pages/about', '/pages/privacy-policy', '/cart', '/checkout', '/toys', '/search?q=lego', '/track', '/account/login', '/blog', '/no-such-page'];
    for (const path of paths) {
        // Twice: the second answer is the cached one wherever the page is cached.
        await request.get(path);
        const res = await request.get(path);
        const policy = res.headers()['content-security-policy'] ?? '';
        const script = scriptSrc(policy);
        expect(script.length, `${path} has script-src`).toBeGreaterThan(0);
        expect(policy, path).toContain("object-src 'none'");
        expect(policy, path).toMatch(/frame-ancestors 'self'/);
        const nonce = script.find((s) => s.startsWith("'nonce-"))?.slice(7, -1);
        const html = await res.text();
        const scripts = [...html.matchAll(/<script\b([^>]*)>/g)].map((m) => m[1]!).filter((attrs) => !/type="application\/ld\+json"/.test(attrs));
        if (nonce) {
            for (const attrs of scripts) expect(attrs, `${path}: ${attrs}`).toContain(`nonce="${nonce}"`);
        } else {
            expect(script, path).toContain("'unsafe-inline'");
        }
    }
});

test('a client-sent CSP header cannot plant a nonce in a page', async ({ request }) => {
    const res = await request.get('/brands', { headers: { 'Content-Security-Policy': "script-src 'nonce-attacker'" } });
    expect(await res.text()).not.toContain('attacker');
    expect(res.headers()['content-security-policy']).not.toContain('attacker');
});

for (const path of ['/', PDP, '/bn/categories', '/cart', '/checkout', '/bn/toys']) {
    test(`no CSP violation with consented GA4 + Meta tags: ${path}`, async ({ context, page }) => {
        await context.addCookies([ACCEPTED]);
        const loaded: string[] = [];
        // Third-party scripts are answered locally with an empty script — nothing leaves the machine.
        await context.route(/googletagmanager\.com|connect\.facebook\.net/, (route) => {
            loaded.push(route.request().url());
            return route.fulfill({ status: 200, contentType: 'application/javascript', body: '' });
        });
        const violations = await watchViolations(context, page);
        await page.goto(path);
        await page.waitForLoadState('load');
        // lazyOnload tags load in idle time after `load`.
        await expect.poll(() => loaded.length, { timeout: 5_000 }).toBeGreaterThanOrEqual(2);
        // Hydrated: the client consent root ran and marked <html>.
        await expect(page.locator('html[data-consent-ready]')).toHaveCount(1);
        expect(violations).toEqual([]);
    });
}

test('client-side navigation from a static page into a per-request page loads its chunks', async ({ context, page }) => {
    const violations = await watchViolations(context, page);
    await page.goto(PDP);
    await page.waitForLoadState('load');
    await page.locator('a[href="/cart"]').first().click();
    await expect(page).toHaveURL(/\/cart$/);
    await page.waitForLoadState('networkidle');
    expect(violations).toEqual([]);
});
