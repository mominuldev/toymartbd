import { describe, expect, it } from 'vitest';

import { getMessages } from '@/lib/i18n';
import { chipLabel } from '@/lib/listing/chips';
import { ageLabel, formatAmount, formatPrice, priceBandLabel, sameAmount } from '@/lib/listing/format';
import { normalizeListing } from '@/lib/listing/response';
import { listingMetadata } from '@/lib/listing/seo';
import {
    activeCount,
    activeFilters,
    cleared,
    emptyState,
    hrefFor,
    indexing,
    parseListingState,
    toApiQuery,
    toSearchParams,
    withoutValue,
    withSort,
    type ListingScope,
} from '@/lib/listing/state';

// P4-STO-03: filter state lives in the URL (storefront spec §A7.5).

const category: ListingScope = { kind: 'category', slug: 'building-blocks' };
const brand: ListingScope = { kind: 'brand', slug: 'lego' };
const en = getMessages('en').listing;
const bn = getMessages('bn').listing;

describe('URL ↔ filter state', () => {
    it('round-trips a full state through the canonical query', () => {
        const state = parseListingState({ age: '24-48,0-12', brand: 'lego,hape', price: '1000-2500', gender: 'unisex', availability: 'in_stock', sort: 'newest', cursor: 'eyJvIjo0fQ' }, category);
        expect(state).toEqual({
            age: ['0-12', '24-48'],
            price: ['1000-2500'],
            brand: ['hape', 'lego'],
            gender: ['unisex'],
            availability: ['in_stock'],
            category: null,
            sort: 'newest',
            cursor: 'eyJvIjo0fQ',
        });
        const query = toSearchParams(state);
        expect(hrefFor('/categories/building-blocks', state)).toBe(
            '/categories/building-blocks?age=0-12,24-48&price=1000-2500&brand=hape,lego&gender=unisex&availability=in_stock&sort=newest&cursor=eyJvIjo0fQ',
        );
        expect(parseListingState(Object.fromEntries(query), category)).toEqual(state);
    });

    it('accepts the repeated keys a GET form submits', () => {
        const state = parseListingState({ brand: ['lego', 'hape'], age: ['24-48', '12-24'] }, category);
        expect(state.brand).toEqual(['hape', 'lego']);
        expect(state.age).toEqual(['12-24', '24-48']);
    });

    it('drops unknown or malformed values instead of failing the page', () => {
        const state = parseListingState({ age: '0-6,24-48', brand: 'LEGO!,lego', gender: 'kids', sort: 'popular', cursor: 'a b', price: '1-2', foo: 'bar' }, category);
        expect(state).toEqual({ ...emptyState(), age: ['24-48'], brand: ['lego'] });
    });

    it('is empty and default-sorted with no params, serialising to the bare path', () => {
        const state = parseListingState({}, category);
        expect(state).toEqual(emptyState());
        expect(hrefFor('/categories/x', state)).toBe('/categories/x');
    });

    it('keeps `category` on brand pages only, and ignores `brand` there', () => {
        expect(parseListingState({ category: 'toys', brand: 'hape' }, brand)).toMatchObject({ category: 'toys', brand: [] });
        expect(parseListingState({ category: 'toys' }, category).category).toBeNull();
    });

    it('maps to the API contract (filter[...], sort, cursor)', () => {
        const state = parseListingState({ age: '24-48', brand: 'lego', price: '0-500,5000-', gender: 'boys', sort: 'price_asc' }, category);
        expect(Object.fromEntries(toApiQuery(state, category))).toEqual({
            'filter[category]': 'building-blocks',
            'filter[brand]': 'lego',
            'filter[age_band]': '24-48',
            'filter[price_band]': '0-500,5000-',
            'filter[gender]': 'boys',
            sort: 'price_asc',
        });
        expect(toApiQuery(parseListingState({ availability: 'in_stock' }, category), category).get('filter[availability]')).toBe('in_stock');
        expect(parseListingState({ availability: 'pre_order' }, category).availability).toEqual([]);
        const onBrand = parseListingState({ category: 'puzzles' }, brand);
        expect(Object.fromEntries(toApiQuery(onBrand, brand))).toEqual({ 'filter[brand]': 'lego', 'filter[category]': 'puzzles' });
    });
});

describe('chips', () => {
    const state = parseListingState({ age: '24-48', brand: 'hape,lego', price: '1000-2500', sort: 'newest', cursor: 'abc' }, category);

    it('lists every active value in facet order', () => {
        expect(activeFilters(state)).toEqual([
            { key: 'age', value: '24-48' },
            { key: 'price', value: '1000-2500' },
            { key: 'brand', value: 'hape' },
            { key: 'brand', value: 'lego' },
        ]);
        expect(activeCount(state)).toBe(4);
    });

    it('removing one value keeps the others and the sort, and restarts paging', () => {
        const next = withoutValue(state, 'brand', 'lego');
        expect(hrefFor('/c', next)).toBe('/c?age=24-48&price=1000-2500&brand=hape&sort=newest');
    });

    it('clear all keeps only the sort', () => {
        expect(hrefFor('/c', cleared(state))).toBe('/c?sort=newest');
        expect(hrefFor('/c', withSort(cleared(state), 'featured'))).toBe('/c');
    });

    it('labels chips in both locales with Western digits', () => {
        const facets = normalizeListing({
            meta: {
                facets: {
                    brand: [{ value: 'lego', label: 'LEGO', label_bn: 'লেগো', count: 2, selected: true }],
                    price_band: [{ value: '1000-2500', min: '1000.00', max: '2500.00', count: 1, selected: true }],
                },
            },
        }).facets;
        expect(chipLabel('age', '24-48', facets, 'en', en)).toEqual({ text: 'Age: 2–4 years' });
        expect(chipLabel('age', '24-48', facets, 'bn', bn)).toEqual({ text: 'বয়স: 2–4 বছর' });
        expect(chipLabel('brand', 'lego', facets, 'en', en).text).toBe('Brand: LEGO');
        expect(chipLabel('brand', 'lego', facets, 'bn', bn).text).toBe('ব্র্যান্ড: লেগো');
        expect(chipLabel('price', '1000-2500', facets, 'en', en).text).toBe('৳1,000–2,500');
        expect(chipLabel('gender', 'unisex', facets, 'bn', bn).text).toBe('সবার জন্য');
        expect(chipLabel('availability', 'in_stock', facets, 'en', en).text).toBe('In stock');
        expect(chipLabel('availability', 'in_stock', facets, 'bn', bn).text).toBe('স্টকে আছে');
        expect(normalizeListing({ meta: { facets: { availability: { count: 7, selected: true } } } }).facets.availability).toEqual([
            { value: 'in_stock', label: null, labelBn: null, count: 7, selected: true },
        ]);
    });
});

describe('facet rendering data', () => {
    it('normalises the API facets and drops malformed options', () => {
        const listing = normalizeListing({
            data: [{ id: 1, slug: 'a', name: 'A', price: '10.00' }, { nope: true }],
            meta: {
                total: 7,
                next_cursor: 'xyz',
                facets: {
                    category: [{ value: 'puzzles', label: 'Puzzles', label_bn: null, count: 3, selected: false }],
                    age_band: [{ value: '0-12', min_months: 0, max_months: 12, count: 0, selected: false }, { value: 5 }],
                    gender: 'broken',
                },
            },
        });
        expect(listing.products).toHaveLength(1);
        expect(listing.total).toBe(7);
        expect(listing.nextCursor).toBe('xyz');
        expect(listing.facets.category[0]).toMatchObject({ value: 'puzzles', label: 'Puzzles', labelBn: null, count: 3 });
        expect(listing.facets.age).toEqual([{ value: '0-12', label: null, labelBn: null, count: 0, selected: false }]);
        expect(listing.facets.gender).toEqual([]);
        expect(listing.facets.brand).toEqual([]);
    });

    it('labels price bands from the API bounds', () => {
        expect(priceBandLabel('0.00', '500.00', en)).toBe('Under ৳500');
        expect(priceBandLabel('500.00', '1000.00', en)).toBe('৳500–1,000');
        expect(priceBandLabel('5000.00', null, en)).toBe('৳5,000+');
        expect(priceBandLabel('0.00', '500.00', bn)).toBe('৳500 এর নিচে');
    });
});

describe('price formatting (display only, no arithmetic)', () => {
    it.each([
        ['4250.00', '৳4,250'],
        ['990.50', '৳990.50'],
        ['1234567.5', '৳1,234,567.50'],
        ['0.00', '৳0'],
        ['not-money', '৳not-money'],
    ])('%s → %s', (input, output) => {
        expect(formatPrice(input)).toBe(output);
    });

    it('compares API amounts by their display form', () => {
        expect(sameAmount('1250.00', '1250.0')).toBe(true);
        expect(sameAmount('1250.00', '1250.50')).toBe(false);
        expect(formatAmount('1000')).toBe('1,000');
    });
});

describe('age label (AgeRangeIndicator §A11)', () => {
    it.each([
        [0, 12, '0–12 months'],
        [6, 12, '6–12 months'],
        [12, 36, '1–3 years'],
        [18, 48, '1½–4 years'],
        [6, 36, '½–3 years'],
        [48, 144, '4–12 years'],
        [36, null, '3+ years'],
        [9, null, '9 months+'],
        [null, 36, 'Up to 3 years'],
        [10, 30, '10–30 months'],
    ] as const)('%s–%s → %s', (min, max, label) => {
        expect(ageLabel(min, max, en)).toBe(label);
    });

    it('has no label for an all-ages toy, and Bangla words with Western digits', () => {
        expect(ageLabel(null, null, en)).toBeNull();
        expect(ageLabel(12, 36, bn)).toBe('1–3 বছর');
    });
});

describe('canonical and robots', () => {
    it('indexes the bare listing and a single-value single facet, self-canonical', () => {
        expect(indexing(emptyState()).indexable).toBe(true);
        const age = parseListingState({ age: '24-48' }, category);
        expect(indexing(age).indexable).toBe(true);
        const meta = listingMetadata('bn', '/categories/blocks', age);
        expect(meta.robots).toBeUndefined();
        expect(meta.alternates?.canonical).toBe('/bn/categories/blocks?age=24-48');
        expect(meta.alternates?.languages).toEqual({
            en: '/categories/blocks?age=24-48',
            bn: '/bn/categories/blocks?age=24-48',
            'x-default': '/categories/blocks?age=24-48',
        });
    });

    it('keeps a later page self-canonical and indexable (pagination SEO)', () => {
        const page2 = parseListingState({ age: '24-48', cursor: 'abc' }, category);
        expect(indexing(page2).indexable).toBe(true);
        const meta = listingMetadata('bn', '/categories/blocks', page2);
        expect(meta.robots).toBeUndefined();
        expect(meta.alternates?.canonical).toBe('/bn/categories/blocks?age=24-48&cursor=abc');
    });

    it.each([
        [{ age: '24-48', brand: 'lego' }],
        [{ brand: 'lego,hape' }],
        [{ sort: 'price_asc' }],
        [{ cursor: 'abc', age: '24-48', brand: 'lego' }],
    ])('noindex,follow with the canonical on the bare listing: %o', (params) => {
        const meta = listingMetadata('en', '/categories/blocks', parseListingState(params, category));
        expect(meta.robots).toEqual({ index: false, follow: true });
        expect(meta.alternates?.canonical).toBe('/categories/blocks');
    });
});
