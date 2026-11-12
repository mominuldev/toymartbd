import 'server-only';

import type { Schema } from '@toymart/api-types';

import { cached } from '../api';
import type { Locale } from '../i18n/config';
import { productPageTags } from '../product/fetch';

export type StorefrontReview = Schema<'StorefrontReview'>;

export interface ReviewSummary {
    rating_avg: string | null;
    rating_count: number;
    /** Approved reviews by star, "5" → "1". */
    distribution: Record<string, number>;
}

export interface ReviewPage {
    data: StorefrontReview[];
    meta: { next_cursor?: string | null; summary: ReviewSummary };
}

/** Opaque Laravel cursor: base64url-ish; anything else is ignored rather than forwarded. */
const CURSOR = /^[A-Za-z0-9_=-]{1,512}$/;

export function validCursor(value: string | string[] | undefined): string | null {
    return typeof value === 'string' && CURSOR.test(value) ? value : null;
}

/**
 * Approved reviews and the rating summary (`GET /storefront/products/{slug}/reviews`, P4-CAT-12),
 * cached under the product page's tags: approving or rejecting a review fires ProductChanged, which
 * purges `product:{slug}`. Null when the API cannot answer — the page then omits the section rather
 * than printing figures of its own (rule 1).
 */
export async function getReviews(slug: string, locale: Locale, cursor: string | null): Promise<ReviewPage | null> {
    const query = cursor ? `?cursor=${encodeURIComponent(cursor)}` : '';
    try {
        return await cached<ReviewPage>(`storefront/products/${encodeURIComponent(slug)}/reviews${query}`, productPageTags(slug), { locale });
    } catch {
        return null;
    }
}
