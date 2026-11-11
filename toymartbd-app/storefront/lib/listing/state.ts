// Listing filter state ↔ URL (storefront spec §A7.5). Client-safe and pure: the server page, the
// filter form, the chips and the unit tests all share it.
//
// The URL is the single source of truth. Every control is a plain link or a GET form field, so a
// filtered view is shareable, bookmarkable and restored by the back button — with JavaScript off.
//
//   /categories/building-blocks?age=24-48&brand=lego,hape&price=1000-2500&gender=unisex&sort=newest
//
// Lists are comma-separated in canonical URLs; a GET form submits repeated keys
// (`brand=lego&brand=hape`), and both parse to the same state. Unknown values are dropped rather
// than failing the page: a stale shared link still renders.

export const AGE_BANDS = ['0-12', '12-24', '24-48', '48-72', '72-96', '96-144', '144-'] as const;
export const PRICE_BANDS = ['0-500', '500-1000', '1000-2500', '2500-5000', '5000-'] as const;
export const GENDERS = ['unisex', 'boys', 'girls'] as const;
export const SORTS = ['featured', 'newest', 'price_asc', 'price_desc', 'rating', 'name'] as const;
export const DEFAULT_SORT = 'featured';

export type AgeBand = (typeof AGE_BANDS)[number];
export type PriceBand = (typeof PRICE_BANDS)[number];
export type Gender = (typeof GENDERS)[number];
export type Sort = (typeof SORTS)[number];

/** `category`: a listing scoped by the path (a category page) has no category filter. */
export type ListingScope = { kind: 'category'; slug: string } | { kind: 'brand'; slug: string };

export interface ListingState {
    age: AgeBand[];
    price: PriceBand[];
    brand: string[];
    gender: Gender[];
    /** Brand pages only: narrow to one category. */
    category: string | null;
    sort: Sort;
    cursor: string | null;
}

/** Filter keys, in URL and chip order (Age → Price → Category → Brand → Gender, §A7). */
export type FilterKey = 'age' | 'price' | 'category' | 'brand' | 'gender';
export const FILTER_KEYS: readonly FilterKey[] = ['age', 'price', 'category', 'brand', 'gender'];

export type RawSearchParams = Record<string, string | string[] | undefined>;

const SLUG = /^[a-z0-9]+(?:-[a-z0-9]+)*$/;
const CURSOR = /^[A-Za-z0-9_=-]{1,1000}$/;
const MAX_LIST = 20;

export const emptyState = (): ListingState => ({ age: [], price: [], brand: [], gender: [], category: null, sort: DEFAULT_SORT, cursor: null });

function values(raw: string | string[] | undefined): string[] {
    const parts = (Array.isArray(raw) ? raw : raw === undefined ? [] : [raw]).flatMap((v) => v.split(','));
    const seen: string[] = [];
    for (const part of parts) {
        const v = part.trim();
        if (v !== '' && !seen.includes(v)) seen.push(v);
    }
    return seen.slice(0, MAX_LIST);
}

function oneOf<T extends string>(allowed: readonly T[], raw: string | string[] | undefined): T[] {
    // Keep the allowlist's order so equal states serialise identically.
    const picked = values(raw);
    return allowed.filter((v) => picked.includes(v));
}

function first(raw: string | string[] | undefined): string | null {
    const v = Array.isArray(raw) ? raw[0] : raw;
    return v === undefined || v.trim() === '' ? null : v.trim();
}

export function parseListingState(params: RawSearchParams, scope: ListingScope): ListingState {
    const sort = first(params.sort);
    const cursor = first(params.cursor);
    const category = first(params.category);
    return {
        age: oneOf(AGE_BANDS, params.age),
        price: oneOf(PRICE_BANDS, params.price),
        brand: scope.kind === 'brand' ? [] : values(params.brand).filter((s) => SLUG.test(s)).sort(),
        gender: oneOf(GENDERS, params.gender),
        category: scope.kind === 'brand' && category !== null && SLUG.test(category) ? category : null,
        sort: sort !== null && (SORTS as readonly string[]).includes(sort) ? (sort as Sort) : DEFAULT_SORT,
        cursor: cursor !== null && CURSOR.test(cursor) ? cursor : null,
    };
}

/** Canonical query: fixed key order, comma lists, default sort and empty lists omitted. */
export function toSearchParams(state: ListingState): URLSearchParams {
    const out = new URLSearchParams();
    if (state.age.length) out.set('age', state.age.join(','));
    if (state.price.length) out.set('price', state.price.join(','));
    if (state.category) out.set('category', state.category);
    if (state.brand.length) out.set('brand', state.brand.join(','));
    if (state.gender.length) out.set('gender', state.gender.join(','));
    if (state.sort !== DEFAULT_SORT) out.set('sort', state.sort);
    if (state.cursor) out.set('cursor', state.cursor);
    return out;
}

/** `path` + canonical query, e.g. `/categories/lego?age=24-48`. Commas stay readable. */
export function hrefFor(path: string, state: ListingState): string {
    const query = toSearchParams(state).toString().replace(/%2C/g, ',');
    return query ? `${path}?${query}` : path;
}

/** The API query for this state (P4-CAT-13 contract: `filter[...]`, `sort`, `cursor`). */
export function toApiQuery(state: ListingState, scope: ListingScope): URLSearchParams {
    const q = new URLSearchParams();
    if (scope.kind === 'category') q.set('filter[category]', scope.slug);
    else {
        q.set('filter[brand]', scope.slug);
        if (state.category) q.set('filter[category]', state.category);
    }
    if (scope.kind === 'category' && state.brand.length) q.set('filter[brand]', state.brand.join(','));
    if (state.age.length) q.set('filter[age_band]', state.age.join(','));
    if (state.price.length) q.set('filter[price_band]', state.price.join(','));
    if (state.gender.length) q.set('filter[gender]', state.gender.join(','));
    if (state.sort !== DEFAULT_SORT) q.set('sort', state.sort);
    if (state.cursor) q.set('cursor', state.cursor);
    return q;
}

function selected(state: ListingState, key: FilterKey): string[] {
    if (key === 'category') return state.category ? [state.category] : [];
    return state[key];
}

/** Every selected (key, value) pair, in chip order. */
export function activeFilters(state: ListingState): Array<{ key: FilterKey; value: string }> {
    return FILTER_KEYS.flatMap((key) => selected(state, key).map((value) => ({ key, value })));
}

export function activeCount(state: ListingState): number {
    return activeFilters(state).length;
}

/** The state with one value removed (a chip's ×). Any change of filter restarts at page 1. */
export function withoutValue(state: ListingState, key: FilterKey, value: string): ListingState {
    const next: ListingState = { ...state, cursor: null };
    if (key === 'category') next.category = null;
    else if (key === 'age') next.age = state.age.filter((v) => v !== value);
    else if (key === 'price') next.price = state.price.filter((v) => v !== value);
    else if (key === 'gender') next.gender = state.gender.filter((v) => v !== value);
    else next.brand = state.brand.filter((v) => v !== value);
    return next;
}

/** Every filter cleared; the sort is kept (it is not a filter). */
export function cleared(state: ListingState): ListingState {
    return { ...emptyState(), sort: state.sort };
}

export function withSort(state: ListingState, sort: Sort): ListingState {
    return { ...state, sort, cursor: null };
}

export function withCursor(state: ListingState, cursor: string | null): ListingState {
    return { ...state, cursor };
}

export function withCategory(state: ListingState, category: string | null): ListingState {
    return { ...state, category, cursor: null };
}

/**
 * Indexing rule (§A7.5, §A8): the bare listing and a single-value, single-facet view are canonical
 * and indexable (`?age=24-48` is a landing page). Anything else — several facets, several values,
 * a non-default sort or a later page — is `noindex, follow` with its canonical on the bare listing.
 */
export function indexing(state: ListingState): { indexable: boolean; canonical: ListingState } {
    const filters = activeFilters(state);
    const plain = state.sort === DEFAULT_SORT && state.cursor === null;
    if (plain && filters.length <= 1) return { indexable: true, canonical: { ...state, cursor: null } };
    return { indexable: false, canonical: emptyState() };
}
