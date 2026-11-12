import { expect, test } from '@playwright/test';

import { MOCK_API_URL } from './constants';

// P4-STO-07 (part): the PDP "You looked at these" rail. The browser stores slugs only; the cards
// come from GET /api/recently-viewed, i.e. from the API — prices exactly as it sends them.
test.use({ javaScriptEnabled: true, viewport: { width: 1440, height: 1000 } });

test.beforeAll(async ({ request }) => {
    await request.post(`${MOCK_API_URL}/__control/reset`);
});

const rail = '#pdp-recently-viewed';

test('the rail is absent on a first visit and lists earlier products afterwards, in both locales', async ({ page }) => {
    await page.goto('/products/hot-wheels-5-pack');
    await expect(page.locator('main h1')).toContainText('Hot Wheels');
    await page.waitForFunction(() => window.localStorage.getItem('tm:recently-viewed')?.includes('hot-wheels-5-pack'));
    await expect(page.locator(rail)).toHaveCount(0);

    await page.goto('/products/lego-classic-creative-bricks');
    const section = page.locator(`section:has(${rail})`);
    await expect(page.locator(rail)).toHaveText('You looked at these');
    await expect(section.locator('article')).toHaveCount(1);
    await expect(section.locator('article')).toContainText('Hot Wheels 5-Car Gift Pack');
    expect(await page.evaluate(() => JSON.parse(window.localStorage.getItem('tm:recently-viewed') ?? '[]'))).toEqual(['lego-classic-creative-bricks', 'hot-wheels-5-pack']);

    await page.goto('/bn/products/hot-wheels-5-pack');
    await expect(page.locator(rail)).toHaveText('আপনি এগুলো দেখেছেন');
    await expect(page.locator(`section:has(${rail}) article a[href="/bn/products/lego-classic-creative-bricks"]`).first()).toBeAttached();
});

test('corrupt or blocked storage just means no rail', async ({ page }) => {
    await page.addInitScript(() => window.localStorage.setItem('tm:recently-viewed', '{broken'));
    await page.goto('/products/lego-classic-creative-bricks');
    await expect(page.locator('main h1')).toBeVisible();
    await expect(page.locator(rail)).toHaveCount(0);

    const blocked = await page.context().browser()!.newContext();
    const other = await blocked.newPage();
    await other.addInitScript(() => {
        Object.defineProperty(window, 'localStorage', {
            get() {
                throw new Error('SecurityError');
            },
        });
    });
    const errors: string[] = [];
    other.on('pageerror', (e) => errors.push(e.message));
    await other.goto(`${page.url()}`);
    await expect(other.locator('main h1')).toBeVisible();
    await expect(other.locator(rail)).toHaveCount(0);
    expect(errors).toEqual([]);
    await blocked.close();
});

test('the route handler drops unknown and malformed slugs', async ({ request }) => {
    const res = await request.get('/api/recently-viewed?slugs=hot-wheels-5-pack,no-such-toy,../etc&locale=bn');
    expect(res.status()).toBe(200);
    const body = (await res.json()) as { data: Array<{ slug: string; price: string }> };
    expect(body.data.map((c) => c.slug)).toEqual(['hot-wheels-5-pack']);
    expect(body.data[0]?.price).toMatch(/^\d+\.\d{2}$/);
});
