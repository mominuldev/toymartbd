import { expect, test, type Page } from '@playwright/test';

import { MOCK_API_URL, STOREFRONT_URL } from './constants';

// Phase 7 SEO plumbing (docs/api/seo.md): the server-rendered <head> a crawler sees, JS disabled.
test.use({ javaScriptEnabled: false });

test.beforeAll(async ({ request }) => {
    await request.post(`${MOCK_API_URL}/__control/reset`);
});

const abs = (path: string) => `${STOREFRONT_URL}${path}`;
const PRODUCT = '/products/lego-classic-creative-bricks';

async function head(page: Page) {
    const attr = (selector: string, name: string) => page.locator(`head ${selector}`).first().getAttribute(name);
    return {
        title: await page.title(),
        canonical: await attr('link[rel="canonical"]', 'href'),
        en: await attr('link[rel="alternate"][hreflang="en"]', 'href'),
        bn: await attr('link[rel="alternate"][hreflang="bn"]', 'href'),
        xDefault: await attr('link[rel="alternate"][hreflang="x-default"]', 'href'),
        robots: (await page.locator('head meta[name="robots"]').count()) === 0 ? null : await attr('meta[name="robots"]', 'content'),
        ogLocale: await attr('meta[property="og:locale"]', 'content'),
        ogTitle: await attr('meta[property="og:title"]', 'content'),
        twitter: await attr('meta[name="twitter:card"]', 'content'),
    };
}

async function jsonLdTypes(page: Page): Promise<string[]> {
    const blocks = await page.locator('script[type="application/ld+json"]').allTextContents();
    return blocks.map((b) => String((JSON.parse(b) as { '@type': string })['@type']));
}

for (const locale of ['en', 'bn'] as const) {
    const prefix = locale === 'bn' ? '/bn' : '';

    test(`home head + Organization/WebSite JSON-LD (${locale})`, async ({ page }) => {
        await page.goto(prefix || '/');
        const h = await head(page);
        // Next renders the root URL without its trailing slash.
        expect(h.canonical).toBe(abs(prefix));
        expect([h.en, h.bn, h.xDefault]).toEqual([abs(''), abs('/bn'), abs('')]);
        expect(h.robots).toBeNull(); // index,follow is the default: no robots meta
        expect(h.ogLocale).toBe(locale === 'bn' ? 'bn_BD' : 'en_US');
        expect(await jsonLdTypes(page)).toEqual(expect.arrayContaining(['Organization', 'WebSite']));
    });

    test(`category head, facet noindex, BreadcrumbList (${locale})`, async ({ page }) => {
        await page.goto(`${prefix}/categories/building-blocks`);
        let h = await head(page);
        expect(h.canonical).toBe(abs(`${prefix}/categories/building-blocks`));
        expect(h.xDefault).toBe(abs('/categories/building-blocks'));
        expect(h.robots).toBeNull(); // index,follow is the default: no robots meta
        expect(await jsonLdTypes(page)).toContain('BreadcrumbList');

        await page.goto(`${prefix}/categories/building-blocks?age=24-48&sort=newest`);
        h = await head(page);
        expect(h.robots).toBe('noindex, follow');
        expect(h.canonical).toBe(abs(`${prefix}/categories/building-blocks`));
    });

    test(`product head from the SEO document + Product JSON-LD (${locale})`, async ({ page }) => {
        await page.goto(`${prefix}${PRODUCT}`);
        const h = await head(page);
        expect(h.title).toBe(locale === 'bn' ? 'লেগো ক্লাসিক ক্রিয়েটিভ ব্রিকস | Toy Mart BD' : 'LEGO Classic Creative Bricks — 484 pcs | Toy Mart BD');
        expect(h.ogTitle).toBe(locale === 'bn' ? 'লেগো ক্লাসিক ব্রিক' : 'LEGO Classic bricks');
        expect(h.canonical).toBe(abs(`${prefix}${PRODUCT}`));
        expect([h.en, h.bn, h.xDefault]).toEqual([abs(PRODUCT), abs(`/bn${PRODUCT}`), abs(PRODUCT)]);
        expect(h.twitter).toBe('summary_large_image');
        const blocks = await page.locator('script[type="application/ld+json"]').allTextContents();
        const productLd = blocks.map((b) => JSON.parse(b) as Record<string, unknown>).find((b) => b['@type'] === 'Product')!;
        expect(productLd.name).toBe(locale === 'bn' ? 'লেগো ক্লাসিক ক্রিয়েটিভ ব্রিকস 484 পিস' : 'LEGO Classic Creative Bricks 484 pcs');
    });

    test(`search and cart are noindex (${locale})`, async ({ page }) => {
        await page.goto(`${prefix}/search?q=lego`);
        expect((await head(page)).robots).toBe('noindex, follow');
        await page.goto(`${prefix}/cart`);
        const cart = await head(page);
        expect(cart.robots).toBe('noindex, follow');
        expect(cart.xDefault).toBe(abs('/cart'));
    });

    test(`an old product slug 301s to the current one (${locale})`, async ({ request }) => {
        const response = await request.get(`${prefix}/products/lego-classic-bricks-old?variant=501`, { maxRedirects: 0 });
        expect(response.status()).toBe(301);
        expect(new URL(response.headers().location!, STOREFRONT_URL).pathname + new URL(response.headers().location!, STOREFRONT_URL).search).toBe(`${prefix}${PRODUCT}?variant=501`);
    });
}

test('a manual 302 keeps its status', async ({ request }) => {
    const response = await request.get('/bn/old-blocks', { maxRedirects: 0 });
    expect(response.status()).toBe(302);
    expect(new URL(response.headers().location!, STOREFRONT_URL).pathname).toBe('/bn/categories/building-blocks');
});

test('/robots.txt and /sitemap.xml are served', async ({ request }) => {
    const robots = await request.get('/robots.txt');
    expect(robots.status()).toBe(200);
    expect(robots.headers()['content-type']).toContain('text/plain');
    const text = await robots.text();
    expect(text).toContain('Disallow: /checkout');
    expect(text).toContain(`Sitemap: ${STOREFRONT_URL}/sitemap.xml`);

    const index = await request.get('/sitemap.xml');
    expect(index.status()).toBe(200);
    expect(index.headers()['content-type']).toContain('application/xml');
    expect(await index.text()).toContain('<sitemapindex');
    expect((await request.get('/sitemaps/sitemap-products-bn-1.xml')).status()).toBe(200);
    expect((await request.get('/sitemaps/secret.xml')).status()).toBe(404);
});
