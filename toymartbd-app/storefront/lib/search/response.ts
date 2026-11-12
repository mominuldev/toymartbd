import type { Schema } from '@toymart/api-types';

import { isCard, normalizeListing, type Listing, type ProductCard } from '../listing/response';

// Search responses as the pages consume them (P4-STO-04). Pure and client-safe: the search page,
// the suggestion route handler and the unit tests share it. Like the listing, every field is
// validated here, so a malformed part of the body costs that part — never the page.

/** `meta.related_searches[]`: a category refinement of the query (`filter[category]`). */
export interface RelatedSearch {
    category: string;
    label: string;
    labelBn: string | null;
    count: number;
    selected: boolean;
}

/** `meta.suggestions[]`: a corrected query that does return products (zero results only). */
export interface SpellingSuggestion {
    query: string;
    count: number;
}

export interface SearchResults {
    listing: Listing;
    page: number;
    lastPage: number;
    /** 1-based positions of the first and last card on this page (null when empty). */
    from: number | null;
    to: number | null;
    related: RelatedSearch[];
    suggestions: SpellingSuggestion[];
    /** "Popular right now" under zero results. */
    bestSellers: ProductCard[];
    /** Meilisearch unavailable: a plain name match, no facets or typo tolerance. */
    degraded: boolean;
}

const obj = (v: unknown): Record<string, unknown> | null => (typeof v === 'object' && v !== null && !Array.isArray(v) ? (v as Record<string, unknown>) : null);
const str = (v: unknown): string | null => (typeof v === 'string' && v !== '' ? v : null);
const int = (v: unknown, fallback: number): number => (typeof v === 'number' && Number.isInteger(v) && v >= 0 ? v : fallback);

export function normalizeSearch(body: unknown): SearchResults {
    const root = obj(body) ?? {};
    const meta = obj(root.meta) ?? {};
    const listing = normalizeListing(body);
    const page = Math.max(1, int(meta.current_page, 1));
    const related = (Array.isArray(meta.related_searches) ? meta.related_searches : []).flatMap((item): RelatedSearch[] => {
        const o = obj(item);
        const category = str(o?.category);
        const label = str(o?.label);
        if (!o || !category || !label || typeof o.count !== 'number') return [];
        return [{ category, label, labelBn: str(o.label_bn), count: o.count, selected: o.selected === true }];
    });
    const suggestions = (Array.isArray(meta.suggestions) ? meta.suggestions : []).flatMap((item): SpellingSuggestion[] => {
        const o = obj(item);
        const query = str(o?.query);
        return o && query && typeof o.count === 'number' ? [{ query, count: o.count }] : [];
    });
    const sellers = obj(meta.best_sellers)?.data ?? meta.best_sellers;
    return {
        listing,
        page,
        lastPage: Math.max(page, int(meta.last_page, page)),
        from: typeof meta.from === 'number' ? meta.from : null,
        to: typeof meta.to === 'number' ? meta.to : null,
        related,
        suggestions,
        bestSellers: Array.isArray(sellers) ? sellers.filter(isCard) : [],
        degraded: meta.degraded === true,
    };
}

export type SuggestCategory = Schema<'StorefrontSearchSuggestions'>['categories'][number];
export type SuggestBrand = Schema<'StorefrontSearchSuggestions'>['brands'][number];

/** `GET /search/suggest`, trimmed to what the dropdown shows (Figma 115:887). */
export interface Suggestions {
    query: string;
    products: ProductCard[];
    categories: SuggestCategory[];
    brands: SuggestBrand[];
    total: number;
}

function named<T extends { slug: string; name: string }>(raw: unknown, limit: number): T[] {
    if (!Array.isArray(raw)) return [];
    return raw
        .flatMap((item) => {
            const o = obj(item);
            const slug = str(o?.slug);
            const name = str(o?.name);
            if (!o || !slug || !name) return [];
            return [{ ...o, slug, name, name_bn: str(o.name_bn), count: typeof o.count === 'number' ? o.count : null } as unknown as T];
        })
        .slice(0, limit);
}

export function normalizeSuggestions(body: unknown): Suggestions {
    const root = obj(body) ?? {};
    const data = obj(root.data) ?? root; // bare (ApiResource) or wrapped
    const products = (Array.isArray(data.products) ? data.products : []).filter(isCard).slice(0, 5);
    // OpenAPI types `total` as a string; the API sends an integer. Accept both.
    const total = typeof data.total === 'number' ? data.total : typeof data.total === 'string' && /^\d+$/.test(data.total) ? Number(data.total) : products.length;
    return {
        query: str(data.query) ?? '',
        products,
        categories: named<SuggestCategory>(data.categories, 3),
        brands: named<SuggestBrand>(data.brands, 3),
        total,
    };
}
