import 'server-only';

import type { Schema } from '@toymart/api-types';

import { ApiError, cached, noStore } from './api';
import type { Locale } from './i18n/config';
import { normalizeListing, type Listing } from './listing/response';
import { emptyState, toApiQuery, type ListingScope, type ListingState, type Sort } from './listing/state';
import { toSearchApiQuery } from './search/query';
import { normalizeSearch, type SearchResults } from './search/response';

export type StorefrontCategory = Schema<'StorefrontCategory'>;

export const productTags = {
    list: 'products',
} as const;

/**
 * One page of a listing (`GET /storefront/products`, P4-CAT-13), ISR-cached under `products` plus
 * the page's own `category:{slug}` / `brand:{slug}` tag (docs/api/revalidation.md). The state is
 * already sanitised by parseListingState, so the API's 400 cannot be reached from a URL.
 */
export async function fetchListing(state: ListingState, scope: ListingScope, locale: Locale): Promise<Listing> {
    const query = toApiQuery(state, scope).toString();
    const tags: string[] = [productTags.list];
    if (scope.kind === 'category') tags.push(`category:${scope.slug}`);
    if (scope.kind === 'brand') tags.push(`brand:${scope.slug}`);
    return normalizeListing(await cached<unknown>(`storefront/products?${query}`, tags, { locale }));
}

/** One active category with breadcrumbs and children, or null on 404. */
export async function getCategoryPage(slug: string, locale: Locale): Promise<StorefrontCategory | null> {
    try {
        const body = await cached<StorefrontCategory | { data: StorefrontCategory }>(
            `storefront/categories/${encodeURIComponent(slug)}`,
            ['categories', `category:${slug}`],
            { locale },
        );
        return 'data' in body ? body.data : body;
    } catch (error) {
        if (error instanceof ApiError && error.isNotFound) return null;
        throw error;
    }
}

/**
 * One page of search results (`GET /storefront/search`, P4-CAT-14). Not tag-cached: results
 * depend on free text and the index is kept fresh by queued sync, not by revalidation
 * (SearchController). The query and state are sanitised by the page, so a 400 cannot be reached
 * from a URL.
 */
export async function fetchSearch(q: string, state: ListingState, page: number, locale: Locale): Promise<SearchResults> {
    return normalizeSearch(await noStore<unknown>(`storefront/search?${toSearchApiQuery(q, state, page).toString()}`, { locale }));
}

/**
 * A Home rail: the first page of the whole catalogue in one order (`featured` = best sellers until
 * orders exist — the API's BestSellers ranks featured first too; `newest` = new arrivals). Tagged
 * `products`, so any product write refreshes Home. Null when the API cannot answer: the section
 * hides rather than taking the page down.
 */
export async function fetchRail(sort: Sort, locale: Locale): Promise<Listing | null> {
    try {
        return await fetchListing({ ...emptyState(), sort }, { kind: 'all' }, locale);
    } catch (error) {
        console.error(`[home] ${sort} rail unavailable:`, error instanceof Error ? error.message : error);
        return null;
    }
}
