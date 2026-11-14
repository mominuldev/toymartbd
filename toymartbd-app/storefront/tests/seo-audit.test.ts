import { existsSync, readdirSync, readFileSync } from 'node:fs';

import { beforeEach, describe, expect, it, vi } from 'vitest';

import { normalizeListing } from '@/lib/listing/response';
import { parseListingState } from '@/lib/listing/state';
import type { StorefrontProduct } from '@/lib/product/variants';
import { productJsonLd } from '@/lib/seo/jsonld';
import { listingPageMetadata } from '@/lib/seo/metadata';
import { validateAll } from '@/lib/seo/validate';
import { cmsPaths, footerColumns } from '@/lib/site-config';

// Phase 9 SEO audit (docs/audits/SEO-AND-COVERAGE.md). A crawl of every reachable URL against a
// DemoDataSeeder API found sitewide links that 404: the header wishlist (`/wishlist`), the footer
// legal bar and consent banner (`/pages/privacy` — the seeded slug is `privacy-policy`) and ten
// fallback footer links to routes that never existed. These tests pin every hard-coded layout link
// to a real route or to a CMS slug the production seeder creates.

const APP = new URL('../app/[locale]/', import.meta.url);
const SEEDER = new URL('../../../database/seeders/StaticPagesSeeder.php', import.meta.url);

/** Static (non-dynamic) storefront routes: every app/[locale]/…/page.tsx without a `[param]`. */
function staticRoutes(dir: URL = APP, prefix = ''): string[] {
    const routes: string[] = existsSync(new URL('page.tsx', dir)) ? [prefix || '/'] : [];
    for (const entry of readdirSync(dir, { withFileTypes: true })) {
        if (!entry.isDirectory() || entry.name.startsWith('[') || entry.name.startsWith('(') || entry.name.startsWith('_')) continue;
        routes.push(...staticRoutes(new URL(`${entry.name}/`, dir), `${prefix}/${entry.name}`));
    }
    return routes;
}

/** The CMS slugs StaticPagesSeeder creates (the pages the owner publishes at launch). */
function seededCmsSlugs(): string[] {
    const source = readFileSync(SEEDER, 'utf8');
    const block = source.slice(source.indexOf('PAGES = ['), source.indexOf('];', source.indexOf('PAGES = [')));
    return [...block.matchAll(/'([a-z0-9-]+)' => \[/g)].map((m) => m[1]!);
}

function resolves(path: string): boolean {
    const pathname = path.split('?')[0]!;
    if (staticRoutes().includes(pathname)) return true;
    const cms = /^\/pages\/([a-z0-9-]+)$/.exec(pathname);
    return cms !== null && seededCmsSlugs().includes(cms[1]!);
}

describe('layout links point at pages that exist', () => {
    it('reads the route table and the seeded CMS slugs', () => {
        expect(staticRoutes()).toEqual(expect.arrayContaining(['/', '/toys', '/track', '/delivery-charge', '/flash-sale', '/account/wishlist', '/pages/about']));
        expect(seededCmsSlugs()).toEqual(expect.arrayContaining(['contact', 'privacy-policy', 'returns-policy', 'terms', 'faq']));
    });

    it('links the consent banner, legal bar and contact fallbacks to seeded CMS pages', () => {
        expect(cmsPaths.privacy).toBe('/pages/privacy-policy');
        for (const path of Object.values(cmsPaths)) expect(resolves(path), path).toBe(true);
    });

    it('builds the fallback footer only from real routes and seeded pages', () => {
        const paths = footerColumns.flatMap((c) => c.links.map((l) => l.path));
        expect(paths.length).toBeGreaterThan(8);
        for (const path of paths) expect(resolves(path), path).toBe(true);
        expect(new Set(paths).size).toBe(paths.length);
    });

    it('links the header wishlist to the account wishlist page, never the old /wishlist', () => {
        const header = readFileSync(new URL('../components/layout/SiteHeader.tsx', import.meta.url), 'utf8');
        const hrefs = [...header.matchAll(/localizedPath\(locale, '([^']+)'\)/g)].map((m) => m[1]!);
        expect(hrefs).toContain('/account/wishlist');
        for (const path of hrefs) expect(resolves(path), path).toBe(true);
    });
});

describe('Product JSON-LD during a flash sale', () => {
    beforeEach(() => vi.stubEnv('SITE_URL', 'https://toymartbd.com'));

    const { _related: _unused, ...raw } = JSON.parse(readFileSync(new URL('../e2e/fixtures/product-detail.json', import.meta.url), 'utf8')) as StorefrontProduct & { _related: string[] };
    const base = { ...raw, related: [], images: [{ id: 1, url: 'https://toymartbd.com/media/p/101/1200.webp', width: 1200, height: 1200, alt: 'Bricks', alt_bn: null, is_primary: true }] } as unknown as StorefrontProduct;
    const offers = (product: StorefrontProduct) => {
        const node = productJsonLd(product, 'en', { home: 'Home' })[0]!;
        return ([] as Array<Record<string, unknown>>).concat(node.offers as Record<string, unknown>);
    };

    it('offers the price shown on the page: the API sale price, valid until the sale ends (Dhaka date)', () => {
        const flashed = {
            ...base,
            // 2026-11-17T18:30Z is already 18 November in Dhaka (UTC+6).
            flash_sale: { promotion_id: 7, name: 'Weekend', name_bn: null, ends_at: '2026-11-17T18:30:00Z', variant_id: 503, sale_price: '3790.00', regular_price: '4190.00', discount_percent: 10, remaining: 5, variants: { '503': { sale_price: '3790.00', discount_percent: 10, remaining: 5 } } },
        } as unknown as StorefrontProduct;

        const byId = Object.fromEntries(offers(flashed).map((o) => [String(o.url).split('variant=')[1], o]));
        expect(byId['503']).toMatchObject({ price: '3790.00', priceValidUntil: '2026-11-18', priceCurrency: 'BDT' });
        // A variant outside the sale keeps its regular price and no end date.
        const regular = base.variants.find((v) => v.id === 501)!;
        expect(byId['501']).toMatchObject({ price: regular.price });
        expect(byId['501']).not.toHaveProperty('priceValidUntil');
        expect(validateAll(productJsonLd(flashed, 'bn', { home: 'হোম' })).flatMap((r) => r.errors)).toEqual([]);
    });

    it('offers the regular price with no end date when no sale runs', () => {
        for (const offer of offers({ ...base, flash_sale: null } as unknown as StorefrontProduct)) {
            expect(offer).not.toHaveProperty('priceValidUntil');
            expect(base.variants.map((v) => v.price)).toContain(offer.price);
        }
    });
});

describe('listing cursor pages', () => {
    beforeEach(() => vi.stubEnv('SITE_URL', 'https://toymartbd.com'));

    const scope = { kind: 'category', slug: 'building-blocks' } as const;
    const meta = (query: Record<string, string>, prevCursor: string | null | undefined) =>
        listingPageMetadata({
            locale: 'bn',
            path: '/categories/building-blocks',
            state: parseListingState(query, scope),
            title: 'Building blocks',
            listing: prevCursor === undefined ? null : { prevCursor },
        });

    it('reads prev_cursor from the API listing', () => {
        expect(normalizeListing({ data: [], meta: { next_cursor: 'n', prev_cursor: 'p' } }).prevCursor).toBe('p');
        expect(normalizeListing({ data: [], meta: { next_cursor: null, prev_cursor: null } }).prevCursor).toBeNull();
    });

    it('keeps a real later page indexable and self-canonical', () => {
        const page = meta({ cursor: 'eyJvZmZzZXQiOjR9' }, 'eyJvZmZzZXQiOjB9');
        expect(page.alternates?.canonical).toBe('/bn/categories/building-blocks?cursor=eyJvZmZzZXQiOjR9');
        expect(page.robots).toBeUndefined();
    });

    it('canonicalises a cursor the API resolved to page 1 (junk or stale) to the bare listing, noindex', () => {
        const page = meta({ cursor: 'zzz' }, null);
        expect(page.alternates?.canonical).toBe('/bn/categories/building-blocks');
        expect(page.alternates?.languages).toMatchObject({ en: '/categories/building-blocks', bn: '/bn/categories/building-blocks', 'x-default': '/categories/building-blocks' });
        expect(page.robots).toEqual({ index: false, follow: true });

        // A single facet survives: the canonical is the facet view without the cursor.
        const facet = meta({ age: '24-48', cursor: 'zzz' }, null);
        expect(facet.alternates?.canonical).toBe('/bn/categories/building-blocks?age=24-48');
        expect(facet.robots).toEqual({ index: false, follow: true });
    });
});
