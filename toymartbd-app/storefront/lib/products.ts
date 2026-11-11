import 'server-only';

import type { Schema } from '@toymart/api-types';

import { ApiError, cached } from './api';
import type { Locale } from './i18n/config';
import { normalizeListing, type Listing } from './listing/response';
import { toApiQuery, type ListingScope, type ListingState } from './listing/state';

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
    const tags = [productTags.list, scope.kind === 'category' ? `category:${scope.slug}` : `brand:${scope.slug}`];
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
