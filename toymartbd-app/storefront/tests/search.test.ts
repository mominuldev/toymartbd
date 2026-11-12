import { describe, expect, it } from 'vitest';

import { getMessages } from '@/lib/i18n';
import { ageLabel } from '@/lib/listing/format';
import { hrefFor, parseListingState, sortsFor, toApiQuery, toSearchParams, withCategory, type ListingScope } from '@/lib/listing/state';
import { cardFromProduct, MAX_SHOWN, parseSlugParam, readSlugs, remember, sanitizeSlugs, STORAGE_KEY, writeSlugs } from '@/lib/recently-viewed';
import { MAX_PAGE, pageHref, pageWindow, parsePage, parseQuery, searchPath, toSearchApiQuery } from '@/lib/search/query';
import { normalizeSearch, normalizeSuggestions } from '@/lib/search/response';
import type { StorefrontProduct } from '@/lib/product/variants';

// P4-STO-04 search results, P4-STO-02 home links, P4-STO-07 recently viewed.

const search: ListingScope = { kind: 'search' };
const all: ListingScope = { kind: 'all' };

const card = (id: number, slug: string) => ({
    id,
    slug,
    name: `Toy ${id}`,
    name_bn: null,
    brand: null,
    price: '1250.00',
    compare_at_price: null,
    price_max: '1250.00',
    discount_percent: null,
    age_min_months: 24,
    age_max_months: 48,
    rating_avg: null,
    rating_count: 0,
    is_featured: false,
    variant_count: 1,
    in_stock: true,
    image: null,
});

describe('search query parsing', () => {
    it('trims, collapses whitespace, drops control characters and caps at 100', () => {
        expect(parseQuery('  lego   duplo ')).toBe('lego duplo');
        expect(parseQuery(['গাড়ি', 'x'])).toBe('গাড়ি');
        expect(parseQuery('a\u0000b')).toBe('a b');
        expect(parseQuery('x'.repeat(150))).toHaveLength(100);
        expect(parseQuery(undefined)).toBe('');
    });

    it('accepts page 1–41 and falls back to 1 for anything else', () => {
        expect(parsePage('2')).toBe(2);
        expect(parsePage(String(MAX_PAGE))).toBe(MAX_PAGE);
        for (const bad of ['0', '42', '-1', '2.5', 'abc', '', undefined]) expect(parsePage(bad)).toBe(1);
    });
});

describe('search URL state', () => {
    it('defaults to relevance, offers no "featured", and omits the default from the URL', () => {
        const state = parseListingState({ sort: 'featured' }, search);
        expect(state.sort).toBe('relevance');
        expect(sortsFor(search)).toEqual(['relevance', 'newest', 'price_asc', 'price_desc', 'rating', 'name']);
        expect(toSearchParams(state).has('sort')).toBe(false);
        expect(parseListingState({ sort: 'newest' }, search).sort).toBe('newest');
        expect(parseListingState({ sort: 'relevance' }, { kind: 'category', slug: 'x' }).sort).toBe('featured');
    });

    it('takes both category and brand as filters, like the all-toys listing', () => {
        for (const scope of [search, all]) {
            const state = parseListingState({ category: 'puzzles', brand: 'lego,hape' }, scope);
            expect(state).toMatchObject({ category: 'puzzles', brand: ['hape', 'lego'] });
            expect(Object.fromEntries(toApiQuery(state, scope))).toEqual({ 'filter[category]': 'puzzles', 'filter[brand]': 'hape,lego' });
        }
    });

    it('extends a path that already has a query', () => {
        const state = parseListingState({ age: '24-48' }, search);
        expect(hrefFor('/search?q=lego', state)).toBe('/search?q=lego&age=24-48');
        expect(hrefFor('/search?q=lego', parseListingState({}, search))).toBe('/search?q=lego');
    });

    it('builds the search path, page links and API query', () => {
        expect(searchPath('lego duplo')).toBe('/search?q=lego+duplo');
        expect(searchPath('')).toBe('/search');
        const state = withCategory(parseListingState({ sort: 'price_asc' }, search), 'puzzles');
        expect(pageHref('/bn/search?q=pazl', state, 1)).toBe('/bn/search?q=pazl&category=puzzles&sort=price_asc');
        expect(pageHref('/bn/search?q=pazl', state, 3)).toBe('/bn/search?q=pazl&category=puzzles&sort=price_asc&page=3');
        expect(Object.fromEntries(toSearchApiQuery('pazl', state, 3))).toEqual({ q: 'pazl', 'filter[category]': 'puzzles', sort: 'price_asc', page: '3' });
        expect(toSearchApiQuery('lego', parseListingState({ cursor: 'abc' }, search), 1).toString()).toBe('q=lego');
    });
});

describe('page window (Figma Pagination 76:820)', () => {
    it.each([
        [1, 1, []],
        [1, 2, [1, 2]],
        [4, 7, [1, 2, 3, 4, 5, 6, 7]],
        [1, 18, [1, 2, 3, 4, 5, 'gap', 18]],
        [4, 18, [1, 2, 3, 4, 5, 'gap', 18]],
        [9, 18, [1, 'gap', 8, 9, 10, 'gap', 18]],
        [16, 18, [1, 'gap', 14, 15, 16, 17, 18]],
    ])('page %i of %i', (current, last, expected) => {
        expect(pageWindow(current, last)).toEqual(expected);
    });

    it('always keeps the row 7 wide once there are more than 7 pages', () => {
        for (let c = 1; c <= 30; c++) expect(pageWindow(c, 30)).toHaveLength(7);
    });
});

describe('search response', () => {
    it('reads results, paging, related searches and the zero-results extras', () => {
        const results = normalizeSearch({
            data: [card(1, 'a'), { bogus: true }],
            meta: {
                current_page: 2,
                last_page: 3,
                from: 25,
                to: 48,
                total: 60,
                facets: { age_band: [{ value: '24-48', count: 3, selected: false }] },
                related_searches: [{ category: 'puzzles', label: 'Puzzles', label_bn: 'পাজল', count: 12, selected: true }, { category: 'x' }],
                suggestions: [{ query: 'lego', count: 5 }, { query: 3 }],
                best_sellers: [card(2, 'b')],
                degraded: false,
            },
        });
        expect(results.listing.products.map((p) => p.slug)).toEqual(['a']);
        expect(results.listing.total).toBe(60);
        expect(results.listing.facets.age[0]?.count).toBe(3);
        expect(results).toMatchObject({ page: 2, lastPage: 3, from: 25, to: 48, degraded: false });
        expect(results.related).toEqual([{ category: 'puzzles', label: 'Puzzles', labelBn: 'পাজল', count: 12, selected: true }]);
        expect(results.suggestions).toEqual([{ query: 'lego', count: 5 }]);
        expect(results.bestSellers.map((p) => p.slug)).toEqual(['b']);
    });

    it('survives an empty or malformed body', () => {
        const results = normalizeSearch(null);
        expect(results).toMatchObject({ page: 1, lastPage: 1, from: null, related: [], suggestions: [], bestSellers: [], degraded: false });
        expect(normalizeSearch({ meta: { best_sellers: { data: [card(3, 'c')] } } }).bestSellers).toHaveLength(1);
    });

    it('reads suggestions bare or wrapped, capping each group, total as int or digit string', () => {
        const body = {
            query: 'le',
            products: Array.from({ length: 7 }, (_, i) => card(i + 1, `p${i}`)),
            categories: [{ slug: 'lego', name: 'LEGO', name_bn: null, icon: 'cube', count: 4 }, { slug: 'bad' }],
            brands: [{ slug: 'lego', name: 'LEGO', name_bn: 'লেগো', count: null }],
            total: '12',
            degraded: false,
        };
        const s = normalizeSuggestions(body);
        expect(s.products).toHaveLength(5);
        expect(s.categories.map((c) => c.slug)).toEqual(['lego']);
        expect(s.brands[0]).toMatchObject({ slug: 'lego', name_bn: 'লেগো', count: null });
        expect(s.total).toBe(12);
        expect(normalizeSuggestions({ data: { ...body, total: 3 } }).total).toBe(3);
        expect(normalizeSuggestions('nope')).toEqual({ query: '', products: [], categories: [], brands: [], total: 0 });
    });
});

describe('copy', () => {
    it('labels the suggestion rows with the card age label', () => {
        expect(ageLabel(18, 48, getMessages('en').listing)).toBe('1½–4 years');
    });

    it('has a sort label for every search sort in both locales', () => {
        for (const locale of ['en', 'bn'] as const) {
            const sorts = getMessages(locale).listing.sorts as Record<string, string>;
            for (const sort of sortsFor(search)) expect(sorts[sort], `${locale} ${sort}`).toBeTruthy();
        }
    });
});

describe('recently viewed', () => {
    it('keeps valid, unique slugs, newest first, capped', () => {
        expect(sanitizeSlugs(['a', 'a', 'B!', 3, 'b-c', null])).toEqual(['a', 'b-c']);
        expect(remember(['a', 'b', 'c'], 'b')).toEqual(['b', 'a', 'c']);
        expect(remember(Array.from({ length: 20 }, (_, i) => `p${i}`), 'new')).toHaveLength(12);
        expect(parseSlugParam('a,b,,../x,c')).toEqual(['a', 'b', 'c']);
        expect(parseSlugParam(Array.from({ length: 20 }, (_, i) => `p${i}`).join(','))).toHaveLength(MAX_SHOWN);
        expect(parseSlugParam(null)).toEqual([]);
    });

    it('treats unavailable or corrupt storage as empty and never throws', () => {
        const broken = {
            getItem: () => {
                throw new Error('SecurityError');
            },
            setItem: () => {
                throw new Error('QuotaExceededError');
            },
        };
        expect(readSlugs(broken)).toEqual([]);
        expect(() => writeSlugs(broken, ['a'])).not.toThrow();
        expect(readSlugs(null)).toEqual([]);
        expect(readSlugs({ getItem: () => '{not json', setItem: () => undefined })).toEqual([]);

        const store = new Map<string, string>();
        const memory = { getItem: (k: string) => store.get(k) ?? null, setItem: (k: string, v: string) => void store.set(k, v) };
        writeSlugs(memory, ['lego', 'duplo']);
        expect(store.get(STORAGE_KEY)).toBe('["lego","duplo"]');
        expect(readSlugs(memory)).toEqual(['lego', 'duplo']);
    });

    it("builds a card from the product page's default variant, copying every amount", () => {
        const product = {
            id: 7,
            slug: 'blocks',
            name: 'Blocks',
            name_bn: 'ব্লক',
            brand: { name: 'Hape', name_bn: null, slug: 'hape' },
            age_min_months: 12,
            age_max_months: 36,
            rating_avg: '4.50',
            rating_count: 3,
            in_stock: true,
            default_variant_id: 2,
            images: [{ id: 1, url: '/a.jpg', thumb_url: '/t.jpg', srcset_webp: null, srcset_avif: null, alt: 'A', alt_bn: null, width: 800, height: 800 }],
            variants: [
                { id: 1, price: '900.00', compare_at_price: null, discount_percent: null, is_default: false },
                { id: 2, price: '1450.00', compare_at_price: '1700.00', discount_percent: 15, is_default: true },
            ],
        } as unknown as StorefrontProduct;
        expect(cardFromProduct(product)).toMatchObject({
            slug: 'blocks',
            price: '1450.00',
            price_max: '1450.00',
            compare_at_price: '1700.00',
            discount_percent: 15,
            in_stock: true,
            brand: { slug: 'hape' },
            image: { url: '/a.jpg', alt: 'A', width: 800 },
        });
    });
});
