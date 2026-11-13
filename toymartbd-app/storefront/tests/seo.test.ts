import { readFileSync } from 'node:fs';

import type { Metadata } from 'next';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { getMessages, locales, type Locale } from '@/lib/i18n';
import { emptyState, parseListingState } from '@/lib/listing/state';
import type { StorefrontProduct } from '@/lib/product/variants';
import { alternatesFor } from '@/lib/seo';
import { articleJsonLd, breadcrumbJsonLd, faqPageJsonLd, organizationJsonLd, productJsonLd, websiteJsonLd } from '@/lib/seo/jsonld';
import {
    canonicalFor,
    contentMetadata,
    homeMetadata,
    listingPageMetadata,
    noindexMetadata,
    privateMetadata,
    productMetadata,
    searchMetadata,
    staticMetadata,
    trackMetadata,
} from '@/lib/seo/metadata';
import { redirectIndex, recordHit, resetRedirectCache } from '@/lib/seo/redirect-map';
import { indexRedirects, resolveRedirect } from '@/lib/seo/redirects';
import { defaultRobots, SITEMAP_FILE, withSitemapLine } from '@/lib/seo/robots';
import type { SeoDocument } from '@/lib/seo/types';
import { validateAll, validateJsonLd } from '@/lib/seo/validate';

const SITE = 'https://toymartbd.com';

const { _related: _unused, ...raw } = JSON.parse(readFileSync(new URL('../e2e/fixtures/product-detail.json', import.meta.url), 'utf8')) as StorefrontProduct & { _related: string[] };
const product: StorefrontProduct = {
    ...raw,
    related: [],
    images: [{ id: 1, url: `${SITE}/media/p/101/1200.webp`, width: 1200, height: 1200, alt: 'Bricks', alt_bn: 'ব্রিক', is_primary: true } as unknown as StorefrontProduct['images'][number]],
};

const seoDoc = (overrides: Partial<SeoDocument> = {}): SeoDocument => ({
    type: 'product',
    slug: 'lego-classic-creative-bricks',
    path: '/products/lego-classic-creative-bricks',
    robots: { index: true, follow: true },
    canonical_url: null,
    sitemap_exclude: false,
    twitter_card: 'summary_large_image',
    og_image: `${SITE}/media/og.webp`,
    en: { title: 'LEGO Classic Bricks | Toy Mart BD', description: 'English description', og_title: 'OG en', og_description: 'OG desc en' },
    bn: { title: 'লেগো ক্লাসিক | Toy Mart BD', description: 'বাংলা বিবরণ', og_title: 'OG bn', og_description: 'OG desc bn' },
    updated_at: '2026-11-28T10:00:00+06:00',
    ...overrides,
});

beforeEach(() => {
    vi.stubEnv('SITE_URL', SITE);
});

/** Every page: canonical in its locale, hreflang en + bn + x-default (rule 35), OG locale pair. */
function expectHead(meta: Metadata, locale: Locale, path: string): void {
    const canonical = locale === 'en' ? path : path === '/' ? '/bn' : `/bn${path}`;
    expect(meta.alternates?.canonical).toBe(canonical);
    expect(meta.alternates?.languages).toEqual({ en: path, bn: path === '/' ? '/bn' : `/bn${path}`, 'x-default': path });
    const og = meta.openGraph as Record<string, unknown>;
    expect(og.locale).toBe(locale === 'en' ? 'en_US' : 'bn_BD');
    expect(og.alternateLocale).toEqual([locale === 'en' ? 'bn_BD' : 'en_US']);
    expect(og.url).toBe(`${SITE}${canonical}`);
    expect(og.siteName).toBe(getMessages(locale).site.name);
    expect(meta.twitter).toBeDefined();
}

describe.each(locales)('metadata templates (%s)', (locale) => {
    const t = getMessages(locale);

    it('home: absolute title, indexable', () => {
        const meta = homeMetadata(locale);
        expectHead(meta, locale, '/');
        expect(meta.title).toEqual({ absolute: `${t.site.name} · ${t.site.tagline}` });
        expect(meta.description).toBe(t.home.metaDescription);
        expect(meta.robots).toBeUndefined(); // index,follow: no robots meta
    });

    it.each([
        ['/categories', 'categories'],
        ['/brands', 'brands'],
        ['/pages/about', 'about'],
        ['/delivery-charge', 'delivery'],
    ])('static page %s', (path) => {
        const meta = staticMetadata(locale, path, 'Title', 'Description');
        expectHead(meta, locale, path);
        expect(meta.title).toBe('Title');
        expect(meta.robots).toBeUndefined(); // index,follow: no robots meta
    });

    it('track: public, indexable', () => {
        const meta = trackMetadata(locale, t.track.title, t.track.metaDescription);
        expectHead(meta, locale, '/track');
        expect(meta.robots).toBeUndefined(); // index,follow: no robots meta
    });

    it('category listing: bare, single facet, later page, facets, sort', () => {
        const scope = { kind: 'category', slug: 'building-blocks' } as const;
        const base = { locale, path: '/categories/building-blocks', title: 'Building Blocks', description: 'd' };
        expectHead(listingPageMetadata({ ...base, state: emptyState() }), locale, '/categories/building-blocks');
        const single = listingPageMetadata({ ...base, state: parseListingState({ age: '24-48' }, scope) });
        expectHead(single, locale, '/categories/building-blocks?age=24-48');
        expect(single.robots).toBeUndefined(); // index,follow: no robots meta
        const page2 = listingPageMetadata({ ...base, state: parseListingState({ cursor: 'eyJpZCI6MX0' }, scope) });
        expectHead(page2, locale, '/categories/building-blocks?cursor=eyJpZCI6MX0');
        expect(page2.robots).toBeUndefined(); // index,follow: no robots meta
        for (const params of [{ age: '24-48', brand: 'lego' }, { sort: 'newest' }, { price: '0-500,500-1000' }]) {
            const meta = listingPageMetadata({ ...base, state: parseListingState(params, scope) });
            expect(meta.robots).toEqual({ index: false, follow: true });
            expectHead(meta, locale, '/categories/building-blocks');
        }
    });

    it('category listing with an SEO document: per-locale text, noindex wins, override canonical', () => {
        const scope = { kind: 'category', slug: 'building-blocks' } as const;
        const doc = seoDoc({ type: 'category', canonical_url: `${SITE}/categories/blocks`, robots: { index: false, follow: true } });
        const meta = listingPageMetadata({ locale, path: '/categories/building-blocks', state: emptyState(), title: 'x', seo: doc });
        expect(meta.title).toEqual({ absolute: doc[locale].title });
        expect(meta.description).toBe(doc[locale].description);
        expect(meta.robots).toEqual({ index: false, follow: true });
        expect(meta.alternates?.canonical).toBe(locale === 'en' ? '/categories/blocks' : '/bn/categories/blocks');
        // A facet view never takes the bare listing's override.
        const facet = listingPageMetadata({ locale, path: '/categories/building-blocks', state: parseListingState({ age: '24-48' }, scope), title: 'x', seo: doc });
        expect(facet.alternates?.canonical).toBe(locale === 'en' ? '/categories/building-blocks?age=24-48' : '/bn/categories/building-blocks?age=24-48');
    });

    it('brand and all-toys listings', () => {
        expectHead(listingPageMetadata({ locale, path: '/brands/lego', state: emptyState(), title: 'LEGO' }), locale, '/brands/lego');
        expectHead(listingPageMetadata({ locale, path: '/toys', state: emptyState(), title: t.listing.allToys }), locale, '/toys');
    });

    it('product: entity defaults, then the SEO document overrides per locale', () => {
        const plain = productMetadata({ locale, slug: product.slug, name: 'Bricks', description: 'Plain', image: { url: `${SITE}/i.webp`, width: 1200, height: 1200, alt: 'a' } });
        expectHead(plain, locale, `/products/${product.slug}`);
        expect(plain.title).toBe('Bricks');
        expect((plain.twitter as Record<string, unknown>).card).toBe('summary_large_image');
        const doc = seoDoc();
        const meta = productMetadata({ locale, slug: product.slug, name: 'Bricks', description: 'Plain', seo: doc });
        expectHead(meta, locale, `/products/${product.slug}`);
        expect(meta.title).toEqual({ absolute: doc[locale].title });
        const og = meta.openGraph as Record<string, unknown>;
        expect(og.title).toBe(doc[locale].og_title);
        expect(og.description).toBe(doc[locale].og_description);
        expect(og.images).toEqual([{ url: `${SITE}/media/og.webp` }]);
    });

    it('content pages and articles', () => {
        const page = contentMetadata({ locale, path: '/pages/returns', title: 'Returns', description: 'd' });
        expectHead(page, locale, '/pages/returns');
        expect((page.openGraph as Record<string, unknown>).type).toBe('website');
        const post = contentMetadata({ locale, path: '/blog/best-toys', title: 'Best toys', kind: 'article', publishedTime: '2026-11-01T00:00:00+06:00' });
        expectHead(post, locale, '/blog/best-toys');
        expect(post.openGraph).toMatchObject({ type: 'article', publishedTime: '2026-11-01T00:00:00+06:00' });
    });

    it('search is noindex,follow with canonical /search', () => {
        const meta = searchMetadata(locale, 'Results', 'd');
        expectHead(meta, locale, '/search');
        expect(meta.robots).toEqual({ index: false, follow: true });
    });

    it.each(['/cart', '/checkout', '/account', '/account/orders', '/orders/TMB-1001'])('private %s is noindex', (path) => {
        const meta = privateMetadata(locale, path, 'Private', { follow: path === '/cart' });
        expectHead(meta, locale, path);
        expect(meta.robots).toEqual({ index: false, follow: path === '/cart' });
    });

    it('404 / maintenance: noindex, no canonical', () => {
        expect(noindexMetadata('Not found')).toEqual({ title: 'Not found', robots: { index: false, follow: false } });
    });
});

describe('canonical override', () => {
    it('re-prefixes an on-site override for Bangla and keeps an off-site one', () => {
        expect(canonicalFor('bn', '/products/a', `${SITE}/products/b`)).toBe('/bn/products/b');
        expect(canonicalFor('bn', '/products/a', `${SITE}/bn/products/b`)).toBe('/bn/products/b');
        expect(canonicalFor('en', '/products/a', `${SITE}/bn/products/b`)).toBe('/products/b');
        expect(canonicalFor('bn', '/products/a', 'https://example.org/x')).toBe('https://example.org/x');
        expect(canonicalFor('en', '/products/a', 'not a url')).toBe('/products/a');
    });

    it('alternatesFor still matches the builder', () => {
        expect(alternatesFor('bn', '/brands')).toEqual({ canonical: '/bn/brands', languages: { en: '/brands', bn: '/bn/brands', 'x-default': '/brands' } });
    });
});

describe('JSON-LD per template validates against the Rich Results requirements', () => {
    const clean = (data: unknown) => validateAll(data).flatMap((r) => r.errors.map((e) => `${r.type}: ${e}`));

    it.each(locales)('PDP Product + BreadcrumbList (%s)', (locale) => {
        const nodes = productJsonLd(product, locale, { home: getMessages(locale).listing.home });
        expect(clean(nodes)).toEqual([]);
        const [ld] = nodes;
        expect(ld!.name).toBe(locale === 'bn' ? product.name_bn : product.name);
        expect(String(ld!.description)).toContain(locale === 'bn' ? '484টি' : '484 colourful');
        expect(ld!.url).toBe(locale === 'bn' ? `${SITE}/bn/products/${product.slug}` : `${SITE}/products/${product.slug}`);
        for (const offer of ld!.offers as Array<Record<string, unknown>>) expect(offer.priceCurrency).toBe('BDT');
    });

    it('omits AggregateRating without approved reviews and still validates', () => {
        const nodes = productJsonLd({ ...product, rating_avg: null, rating_count: 0 }, 'en', { home: 'Home' });
        expect(nodes[0]).not.toHaveProperty('aggregateRating');
        expect(clean(nodes)).toEqual([]);
    });

    it.each(locales)('home Organization + WebSite with SearchAction (%s)', (locale) => {
        const org = organizationJsonLd(locale);
        const site = websiteJsonLd(locale);
        expect(clean([org, site])).toEqual([]);
        // Placeholders (hotline, email, logo) are never published.
        expect(JSON.stringify(org)).not.toContain('09610');
        expect(org).not.toHaveProperty('logo');
        const target = ((site.potentialAction as Record<string, unknown>).target as Record<string, string>).urlTemplate;
        expect(target).toBe(locale === 'bn' ? `${SITE}/bn/search?q={search_term_string}` : `${SITE}/search?q={search_term_string}`);
    });

    it.each(locales)('category / brand BreadcrumbList (%s)', (locale) => {
        expect(clean(breadcrumbJsonLd(locale, [{ name: 'Home', path: '/' }, { name: 'Brands', path: '/brands' }, { name: 'LEGO', path: '/brands/lego' }]))).toEqual([]);
    });

    it.each(locales)('Article and FAQPage (%s)', (locale) => {
        const article = articleJsonLd({ locale, path: '/blog/best-toys', headline: 'Best toys for a 3 year old', images: ['/media/blog/1.webp'], datePublished: '2026-11-01T09:00:00+06:00', author: { name: 'Nusrat' } });
        const faq = faqPageJsonLd([{ question: 'Do you deliver outside Dhaka?', answer: 'Yes, to all 64 districts.' }]);
        expect(clean([article, faq])).toEqual([]);
    });
});

describe('validator catches what Google rejects', () => {
    const errors = (node: unknown) => validateJsonLd(node).errors.join(' | ');

    it('Product without name, offers, a price or a currency', () => {
        expect(errors({ '@context': 'https://schema.org', '@type': 'Product' })).toMatch(/name.*offers/s);
        expect(errors({ '@context': 'https://schema.org', '@type': 'Product', name: 'x', offers: { '@type': 'Offer', price: '৳500', priceCurrency: 'Taka' } })).toMatch(/price.*priceCurrency/s);
        expect(errors({ '@context': 'https://schema.org', '@type': 'Product', name: 'x', aggregateRating: { ratingValue: '4.5', reviewCount: 0 } })).toMatch(/zero reviews/);
    });

    it('BreadcrumbList with one item or relative URLs', () => {
        expect(errors({ '@context': 'https://schema.org', '@type': 'BreadcrumbList', itemListElement: [{ position: 1, name: 'Home', item: '/' }] })).toMatch(/at least two/);
        expect(errors({ '@context': 'https://schema.org', '@type': 'BreadcrumbList', itemListElement: [{ position: 1, name: 'Home', item: '/' }, { position: 2, name: 'x' }] })).toMatch(/absolute/);
    });

    it('WebSite whose SearchAction target lacks the variable', () => {
        expect(errors({ '@context': 'https://schema.org', '@type': 'WebSite', name: 'x', url: SITE, potentialAction: { '@type': 'SearchAction', target: `${SITE}/search?q=`, 'query-input': 'required name=search_term_string' } })).toMatch(/lacks/);
    });

    it('Article without date or author, FAQPage without an answer, wrong context', () => {
        expect(errors({ '@context': 'https://schema.org', '@type': 'Article', headline: 'x' })).toMatch(/datePublished.*author/s);
        expect(errors({ '@context': 'https://schema.org', '@type': 'FAQPage', mainEntity: [{ '@type': 'Question', name: 'q' }] })).toMatch(/acceptedAnswer/);
        expect(errors({ '@context': 'http://schema.org', '@type': 'Organization', name: 'x', url: SITE })).toMatch(/@context/);
    });
});

describe('redirect resolution', () => {
    const index = indexRedirects([
        { id: null, from: '/products/old-bricks', to: '/products/lego-classic-creative-bricks', status: 301 },
        { id: 7, from: '/old-shop', to: '/categories/building-blocks?age=24-48', status: 302 },
        { id: 8, from: '/a', to: '/b', status: 301 },
        { id: 9, from: '/b', to: '/c', status: 301 },
        { id: 10, from: '/loop-1', to: '/loop-2', status: 301 },
        { id: 11, from: '/loop-2', to: '/loop-1', status: 301 },
        { id: 12, from: '/away', to: 'https://example.org/x', status: 301 },
        { id: 13, from: '/bad', to: 'javascript:alert(1)', status: 301 },
    ]);

    it('301s an old product slug in both locales and keeps the query', () => {
        expect(resolveRedirect(index, '/products/old-bricks')).toEqual({ location: '/products/lego-classic-creative-bricks', status: 301, id: null });
        expect(resolveRedirect(index, '/bn/products/old-bricks', '?variant=501')).toEqual({ location: '/bn/products/lego-classic-creative-bricks?variant=501', status: 301, id: null });
        expect(resolveRedirect(index, '/products/old-bricks/')?.location).toBe('/products/lego-classic-creative-bricks');
    });

    it('a target query replaces the request query; 302 stays 302', () => {
        expect(resolveRedirect(index, '/bn/old-shop', '?utm=x')).toEqual({ location: '/bn/categories/building-blocks?age=24-48', status: 302, id: 7 });
    });

    it('collapses a chain to one hop and refuses loops', () => {
        expect(resolveRedirect(index, '/a')).toEqual({ location: '/c', status: 301, id: 8 });
        expect(resolveRedirect(index, '/loop-1')).toBeNull();
    });

    it('external https only, unknown paths pass', () => {
        expect(resolveRedirect(index, '/bn/away')).toEqual({ location: 'https://example.org/x', status: 301, id: 12 });
        expect(resolveRedirect(index, '/bad')).toBeNull();
        expect(resolveRedirect(index, '/products/lego-classic-creative-bricks')).toBeNull();
        expect(resolveRedirect(new Map(), '/a')).toBeNull();
    });
});

describe('redirect map cache (proxy)', () => {
    afterEach(() => resetRedirectCache());

    it('loads once per TTL, keeps the last map on failure, and is empty without config', async () => {
        vi.stubEnv('API_URL', 'http://api.test');
        vi.stubEnv('STOREFRONT_API_TOKEN', 'tok');
        const ok = vi.fn(async () => Response.json({ redirects: [{ id: 1, from: '/x', to: '/y', status: 301 }] }));
        const first = await redirectIndex(Date.now(), ok as unknown as typeof fetch);
        expect(first.get('/x')?.to).toBe('/y');
        await redirectIndex(Date.now(), ok as unknown as typeof fetch);
        expect(ok).toHaveBeenCalledTimes(1);
        const [url, init] = ok.mock.calls[0] as unknown as [string, RequestInit];
        expect(url).toBe('http://api.test/api/v1/storefront/redirects');
        expect((init.headers as Record<string, string>).Authorization).toBe('Bearer tok');

        const failing = vi.fn(async () => new Response('down', { status: 503 }));
        const stale = await redirectIndex(Date.now() + 120_000, failing as unknown as typeof fetch);
        expect(stale.get('/x')?.to).toBe('/y'); // served at once while refreshing in the background
    });

    it('answers an empty map when the API is not configured', async () => {
        vi.stubEnv('API_URL', '');
        expect((await redirectIndex()).size).toBe(0);
    });

    it('records a hit with a POST and swallows errors', async () => {
        vi.stubEnv('API_URL', 'http://api.test');
        vi.stubEnv('STOREFRONT_API_TOKEN', 'tok');
        const post = vi.fn(async () => {
            throw new Error('network');
        });
        await expect(recordHit(7, post as unknown as typeof fetch)).resolves.toBeUndefined();
        expect((post.mock.calls[0] as unknown as [string, RequestInit])[0]).toBe('http://api.test/api/v1/storefront/redirects/7/hit');
    });
});

describe('robots.txt and sitemap files', () => {
    it('always ends with our sitemap line, once', () => {
        const site = new URL(SITE);
        expect(withSitemapLine('User-agent: *\nDisallow: /cart', site)).toBe(`User-agent: *\nDisallow: /cart\n\nSitemap: ${SITE}/sitemap.xml\n`);
        expect(withSitemapLine(`User-agent: *\nSitemap: ${SITE}/sitemap.xml\n`, site)).toBe(`User-agent: *\nSitemap: ${SITE}/sitemap.xml\n`);
        expect(defaultRobots(site)).toContain('Disallow: /checkout');
        expect(defaultRobots(site)).toContain(`Sitemap: ${SITE}/sitemap.xml`);
    });

    it('allowlists sitemap file names', () => {
        expect(SITEMAP_FILE.test('sitemap-products-bn-1.xml')).toBe(true);
        expect(SITEMAP_FILE.test('sitemap-products-fr-1.xml')).toBe(false);
        expect(SITEMAP_FILE.test('../.env')).toBe(false);
        expect(SITEMAP_FILE.test('sitemap.xml')).toBe(false);
    });
});

describe('route handlers', () => {
    beforeEach(() => {
        vi.stubEnv('API_URL', 'http://api.test');
        vi.stubEnv('STOREFRONT_API_TOKEN', 'tok');
    });

    it('/robots.txt serves the editor text with the sitemap line, or the default on failure', async () => {
        const { GET } = await import('@/app/robots.txt/route');
        vi.stubGlobal('fetch', vi.fn(async () => new Response('User-agent: *\nDisallow: /secret', { headers: { 'Content-Type': 'text/plain' } })));
        const res = await GET();
        expect(res.headers.get('content-type')).toContain('text/plain');
        expect(await res.text()).toBe(`User-agent: *\nDisallow: /secret\n\nSitemap: ${SITE}/sitemap.xml\n`);
        vi.stubGlobal('fetch', vi.fn(async () => new Response('', { status: 500 })));
        expect(await (await GET()).text()).toContain('Disallow: /checkout');
    });

    it('/sitemap.xml and /sitemaps/[file] proxy the XML; unknown names never reach the API', async () => {
        const xml = '<?xml version="1.0"?><sitemapindex/>';
        const fetchMock = vi.fn(async () => new Response(xml, { headers: { 'Content-Type': 'application/xml' } }));
        vi.stubGlobal('fetch', fetchMock);
        const index = await (await import('@/app/sitemap.xml/route')).GET();
        expect(index.headers.get('content-type')).toContain('application/xml');
        expect(await index.text()).toBe(xml);
        const file = await import('@/app/sitemaps/[file]/route');
        expect((await file.GET(new Request('http://x'), { params: Promise.resolve({ file: 'sitemap-products-en-1.xml' }) })).status).toBe(200);
        expect(String((fetchMock.mock.calls[1] as unknown as [string])[0])).toBe('http://api.test/api/v1/storefront/sitemaps/sitemap-products-en-1.xml');
        expect((await file.GET(new Request('http://x'), { params: Promise.resolve({ file: '..%2F.env' }) })).status).toBe(404);
        expect(fetchMock).toHaveBeenCalledTimes(2);
    });
});
