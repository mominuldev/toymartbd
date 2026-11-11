import { expect, test, type Page } from '@playwright/test';

import { MOCK_API_URL, STOREFRONT_URL } from './constants';

// P3-STO-04 / P3-I18N-02: everything here is asserted with JavaScript DISABLED, i.e. against the
// server-rendered HTML a crawler sees.
test.use({ javaScriptEnabled: false });

test.beforeAll(async ({ request }) => {
    await request.post(`${MOCK_API_URL}/__control/reset`);
});

async function hreflang(page: Page) {
    const read = (lang: string) => page.locator(`head link[rel="alternate"][hreflang="${lang}"]`).getAttribute('href');
    return { en: await read('en'), bn: await read('bn'), xDefault: await read('x-default') };
}

const abs = (path: string) => `${STOREFRONT_URL}${path}`;

test('/brands renders brand names server-side in English', async ({ page }) => {
    const response = await page.goto('/brands');
    expect(response?.status()).toBe(200);
    expect(response?.url()).toBe(abs('/brands')); // not redirected
    await expect(page.locator('html')).toHaveAttribute('lang', 'en');

    const html = await page.content();
    for (const name of ['LEGO', 'Hot Wheels', 'Barbie']) expect(html).toContain(name);
    await expect(page.locator('h1')).toHaveCount(1);
    await expect(page.locator('h1')).toHaveText('Brands');

    expect(await hreflang(page)).toEqual({ en: abs('/brands'), bn: abs('/bn/brands'), xDefault: abs('/brands') });
    await expect(page.locator('head link[rel="canonical"]')).toHaveAttribute('href', abs('/brands'));

    // The locale toggle is a real link to the same page in Bangla.
    const toggle = page.locator('header a[hreflang="bn"]');
    await expect(toggle).toHaveAttribute('href', '/bn/brands');
    await expect(toggle).toHaveText('বাংলা');
});

test('/bn/brands renders Bangla names, with English fallback when name_bn is null', async ({ page }) => {
    const response = await page.goto('/bn/brands');
    expect(response?.status()).toBe(200);
    await expect(page.locator('html')).toHaveAttribute('lang', 'bn');

    const html = await page.content();
    expect(html).toContain('লেগো');
    expect(html).toContain('হট হুইলস');
    expect(html).toContain('Barbie'); // fallback
    expect(html).not.toContain('Hot Wheels');
    await expect(page.locator('h1')).toHaveCount(1);
    await expect(page.locator('h1')).toHaveText('ব্র্যান্ড');
    await expect(page.locator('h2[lang="en"]')).toHaveText('Barbie'); // fallback marked as English

    expect(await hreflang(page)).toEqual({ en: abs('/brands'), bn: abs('/bn/brands'), xDefault: abs('/brands') });
    await expect(page.locator('head link[rel="canonical"]')).toHaveAttribute('href', abs('/bn/brands'));
    await expect(page.locator('header a[hreflang="en"]')).toHaveAttribute('href', '/brands');
});

test('brand detail pages render in both locales with reciprocal hreflang', async ({ page }) => {
    await page.goto('/brands/lego');
    await expect(page.locator('html')).toHaveAttribute('lang', 'en');
    await expect(page.locator('h1')).toHaveText('LEGO');
    await expect(page.getByText('Building bricks for every age.')).toBeVisible();
    expect(await hreflang(page)).toEqual({ en: abs('/brands/lego'), bn: abs('/bn/brands/lego'), xDefault: abs('/brands/lego') });

    await page.goto('/bn/brands/lego');
    await expect(page.locator('html')).toHaveAttribute('lang', 'bn');
    await expect(page.locator('h1')).toHaveText('লেগো');
    expect(await hreflang(page)).toEqual({ en: abs('/brands/lego'), bn: abs('/bn/brands/lego'), xDefault: abs('/brands/lego') });
});

test('an unknown brand answers 404 + noindex in the server HTML', async ({ page }) => {
    // Next 16.4.3 serves notFound() from a page as an HTTP 404 whose HTML body is its empty
    // `__next_error__` shell; the not-found UI is rendered by the client (next test). What a
    // crawler needs — the status and noindex — is server-side.
    for (const path of ['/brands/no-such-brand', '/bn/brands/no-such-brand']) {
        const res = await page.goto(path);
        expect(res?.status(), path).toBe(404);
        await expect(page.locator('meta[name="robots"]'), path).toHaveAttribute('content', /noindex/);
    }
});

test.describe('with JavaScript', () => {
    test.use({ javaScriptEnabled: true });

    test('the 404 UI renders in the right language', async ({ page }) => {
        await page.goto('/brands/no-such-brand');
        await expect(page.locator('h1')).toHaveText('Page not found');
        await expect(page.locator('html')).toHaveAttribute('lang', 'en');

        await page.goto('/bn/brands/no-such-brand');
        await expect(page.locator('h1')).toHaveText('পাতাটি পাওয়া যায়নি');
        await expect(page.locator('html')).toHaveAttribute('lang', 'bn');
    });
});

test('home renders in both locales with hreflang', async ({ page }) => {
    await page.goto('/');
    await expect(page.locator('html')).toHaveAttribute('lang', 'en');
    expect(await hreflang(page)).toEqual({ en: STOREFRONT_URL, bn: abs('/bn'), xDefault: STOREFRONT_URL }); // Next drops the root's trailing slash
    await page.goto('/bn');
    await expect(page.locator('html')).toHaveAttribute('lang', 'bn');
    expect(await hreflang(page)).toEqual({ en: STOREFRONT_URL, bn: abs('/bn'), xDefault: STOREFRONT_URL }); // Next drops the root's trailing slash
});

test.describe('no locale redirect, ever (P3-I18N-02)', () => {
    const agents = {
        'Bangla browser': { 'Accept-Language': 'bn-BD,bn;q=0.9,en;q=0.5' },
        Googlebot: {
            'User-Agent': 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
            'Accept-Language': 'bn',
        },
        'no Accept-Language': {},
    } as const;

    for (const [label, headers] of Object.entries(agents)) {
        test(`GET / and /brands answer 200 with English for: ${label}`, async ({ request }) => {
            for (const path of ['/', '/brands']) {
                const res = await request.get(path, { headers, maxRedirects: 0 });
                expect(res.status(), path).toBe(200);
                expect(res.headers()['location'], path).toBeUndefined();
                expect(await res.text()).toContain('<html lang="en"');
            }
        });
    }
});
