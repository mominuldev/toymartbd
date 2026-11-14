import { randomUUID } from 'node:crypto';

import { expect, test, type APIRequestContext } from '@playwright/test';

import { bn } from '../lib/i18n/bn';
import { en } from '../lib/i18n/en';
import { MOCK_API_URL, STOREFRONT_URL } from './constants';

// Gate 5 "Routing the shop to its maintenance page during planned downtime". The mock API plays
// `php artisan down --retry=30`: every /api/v1 call answers 503 problem `maintenance` +
// Retry-After (e2e/mock-api.mjs /__control/maintenance). The storefront must show its maintenance
// page — 503, Retry-After, the request's locale, never cached — instead of the 500 page, without
// probing the API on its own; and come back by itself once the API is up. JavaScript disabled:
// what a crawler and a no-JS shopper get.
test.use({ javaScriptEnabled: false });

const PRODUCT = '/products/lego-classic-creative-bricks';
const RETRY = 30;

async function maintenance(request: APIRequestContext, on: boolean) {
    await request.post(`${MOCK_API_URL}/__control/maintenance`, { data: { on, retry_after: RETRY } });
}

test.beforeEach(async ({ request }) => {
    await maintenance(request, false);
    await request.post(`${MOCK_API_URL}/__control/reset`);
});

test.afterEach(async ({ request }) => {
    await maintenance(request, false);
});

test('API maintenance → home, a product page and checkout answer the maintenance page as a 503, then the shop comes back', async ({ page, request, browser }) => {
    test.setTimeout(150_000);

    // A cart exists before the downtime (no-JS add to cart).
    await page.goto(`${PRODUCT}?variant=501`);
    await page.getByRole('button', { name: en.pdp.addToCart, exact: true }).first().click();
    await expect(page).toHaveURL(/\/cart$/);

    await maintenance(request, true);

    // The first page that calls the API learns of the downtime from that real answer: it renders the
    // maintenance look (error boundary), never the 500 page. Search is per-request, so it does call.
    // The error boundary is a client component: Next streams the render error to the browser and
    // error.tsx draws it there, so this one step needs JavaScript. Without it the first response is
    // an empty 500 shell — the cost of the window before the proxy knows (DEPLOYMENT.md §5).
    const withJs = await browser.newContext({ javaScriptEnabled: true, baseURL: STOREFRONT_URL });
    try {
        const jsPage = await withJs.newPage();
        const first = await jsPage.goto('/search?q=lego');
        expect([500, 503]).toContain(first?.status());
        await expect(jsPage.locator('h1')).toHaveText(en.maintenance.title);
        await expect(jsPage.getByText(en.serverError.title)).toHaveCount(0);
    } finally {
        await withJs.close();
    }

    // From then on the proxy answers every page itself, without touching the API.
    const hitsBefore = (await (await request.get(`${MOCK_API_URL}/__control/hits`)).json()) as Record<string, number>;
    for (const [path, t] of [
        ['/', en],
        [PRODUCT, en],
        ['/checkout', en],
        ['/bn', bn],
        [`/bn${PRODUCT}`, bn],
        ['/bn/checkout', bn],
    ] as const) {
        const response = await page.goto(path);
        expect(response?.status(), path).toBe(503);
        expect(response?.headers()['retry-after'], path).toBe(String(RETRY));
        expect(response?.headers()['cache-control'], path).toBe('no-store');
        await expect(page.locator('h1'), path).toHaveText(t.maintenance.title);
        await expect(page.locator('html'), path).toHaveAttribute('lang', t === bn ? 'bn' : 'en');
        expect(page.url(), path).toMatch(new RegExp(`${path.replace(/[?]/g, '\\?')}$`)); // no redirect
    }
    const hitsAfter = (await (await request.get(`${MOCK_API_URL}/__control/hits`)).json()) as Record<string, number>;
    const pageCalls = Object.entries(hitsAfter).filter(([path, n]) => !path.includes('/redirects') && n !== (hitsBefore[path] ?? 0));
    expect(pageCalls, 'no page probed the API while it was down').toEqual([]);

    // A checkout POST during the downtime: the "can't take orders right now" notice, and the page
    // it lands on is the maintenance page — never a 500.
    const placed = await page.request.post('/api/checkout', {
        form: { intent: 'place', locale: 'en', idempotency_key: randomUUID(), expected_total: '1520.00' },
        maxRedirects: 0,
    });
    expect(placed.status()).toBe(303);
    expect(placed.headers()['location']).toMatch(/\/checkout$/);
    const landed = await page.goto('/checkout');
    expect(landed?.status()).toBe(503);

    // The API is back: within the signal's window (≤ 60 s) the shop answers normally again, and
    // nothing from the downtime was cached — the product page renders the product, with a 200.
    await maintenance(request, false);
    await expect
        .poll(async () => (await request.get('/', { maxRedirects: 0 })).status(), { timeout: 90_000, intervals: [2_000] })
        .toBe(200);
    const product = await page.goto(PRODUCT);
    expect(product?.status()).toBe(200);
    await expect(page.locator('h1')).toContainText('LEGO');
    const checkout = await page.goto('/checkout');
    expect(checkout?.status()).toBe(200);
    await expect(page.locator('h1')).not.toHaveText(en.maintenance.title);
});
