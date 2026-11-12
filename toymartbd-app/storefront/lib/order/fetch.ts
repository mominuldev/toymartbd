import 'server-only';

import { ApiError, noStore, send } from '../api';
import type { StorefrontOrder } from '../cart/types';
import type { Locale } from '../i18n/config';

// Order reads for P4-STO-10 (docs/api/checkout.md). Never cached: an order's stage changes.

/** The confirmation object, for the cart token that placed it; null on the API's indistinguishable 404. */
export async function getPlacedOrder(number: string, token: string, locale: Locale): Promise<StorefrontOrder | null> {
    try {
        return await noStore<StorefrontOrder>(`storefront/orders/${encodeURIComponent(number)}`, { locale, headers: { 'X-Cart-Token': token } });
    } catch (error) {
        if (error instanceof ApiError && error.isNotFound) return null;
        throw error;
    }
}

export type TrackResult =
    | { ok: true; order: StorefrontOrder }
    | { ok: false; reason: 'not-found' | 'invalid' | 'too-many' | 'failed' };

/** Public tracking by number + phone digits (rule 22). The API's 404 never says which part was wrong. */
export async function trackOrder(number: string, phone: string, locale: Locale): Promise<TrackResult> {
    try {
        const { data } = await send<StorefrontOrder>('POST', 'storefront/orders/track', { locale, body: { number, phone } });
        return { ok: true, order: data };
    } catch (error) {
        if (!(error instanceof ApiError)) return { ok: false, reason: 'failed' };
        if (error.status === 404) return { ok: false, reason: 'not-found' };
        if (error.status === 422) return { ok: false, reason: 'invalid' };
        if (error.status === 429) return { ok: false, reason: 'too-many' };
        return { ok: false, reason: 'failed' };
    }
}
