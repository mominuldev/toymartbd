import 'server-only';

import type { Schema } from '@toymart/api-types';

import { ApiError, cached } from '../api';
import type { Locale } from '../i18n/config';
import type { StorefrontProduct } from './variants';

export type DeliveryRates = Schema<'DeliveryRates'>;

/** Cache tags of a product page (docs/api/revalidation.md). `products` because the page carries a product grid (related). */
export function productPageTags(slug: string): string[] {
    return ['products', `product:${slug}`];
}

/** One live product (`GET /storefront/products/{slug}`, P4-STO-05), or null on 404. */
export async function getProduct(slug: string, locale: Locale): Promise<StorefrontProduct | null> {
    try {
        return await cached<StorefrontProduct>(`storefront/products/${encodeURIComponent(slug)}`, productPageTags(slug), { locale });
    } catch (error) {
        if (error instanceof ApiError && error.isNotFound) return null;
        throw error;
    }
}

/**
 * The published delivery rate table (`GET /storefront/delivery-rates`, P4-STO-06) for the
 * "Delivery & returns" panel — the same numbers the estimator and checkout use. Null when the
 * API cannot give it: the panel then drops the table rather than printing numbers of its own.
 */
export async function getDeliveryRates(locale: Locale): Promise<DeliveryRates | null> {
    try {
        return await cached<DeliveryRates>('storefront/delivery-rates', ['delivery'], { locale });
    } catch {
        return null;
    }
}
