import 'server-only';

import { cached, noStore } from '../api';
import type { Locale } from '../i18n/config';
import type { DeliveryQuote, DeliveryRates, DeliveryReference, DeliverySelection, District, Upazila } from './types';

// Server-side reads for the delivery estimator (P4-STO-06, docs/api/delivery.md). The reference
// data (rates, districts, upazilas) changes rarely and is cached under the `delivery` tag; a
// quote depends on the clock (cut-off) and price, so it is never cached.

const TAGS = ['delivery'];

/** Rates, districts and the upazilas of every district whose charge depends on the upazila. Never throws. */
export async function getDeliveryReference(locale: Locale): Promise<DeliveryReference> {
    const [rates, districts] = await Promise.all([
        cached<DeliveryRates>('storefront/delivery-rates', TAGS, { locale }).catch(() => null),
        cached<{ data: District[] }>('storefront/districts', TAGS, { locale })
            .then((r) => r.data)
            .catch(() => [] as District[]),
    ]);

    const required = districts.filter((d) => d.upazila_required);
    const lists = await Promise.all(
        required.map((d) =>
            cached<{ data: Upazila[] }>(`storefront/districts/${d.id}/upazilas`, TAGS, { locale })
                .then((r) => r.data)
                .catch(() => [] as Upazila[]),
        ),
    );

    const upazilas: Record<number, Upazila[]> = {};
    required.forEach((d, i) => {
        upazilas[d.id] = lists[i] ?? [];
    });

    return { rates, districts, upazilas };
}

export interface QuoteRequest extends DeliverySelection {
    variantId: number | null;
    quantity: number;
}

/** The API query for a quote. Only whole positive ids leave the server. */
export function quotePath(request: QuoteRequest): string {
    const q = new URLSearchParams({ district_id: String(request.districtId), quantity: String(request.quantity) });
    if (request.upazilaId !== null) q.set('upazila_id', String(request.upazilaId));
    if (request.variantId !== null) q.set('variant_id', String(request.variantId));
    return `storefront/delivery-quote?${q.toString()}`;
}

export function getDeliveryQuote(request: QuoteRequest, locale: Locale): Promise<DeliveryQuote> {
    return noStore<DeliveryQuote>(quotePath(request), { locale });
}

const ID = /^[1-9]\d{0,8}$/;

/** Parse `district_id`, `upazila_id`, `variant_id`, `quantity` from a query; null when the district is missing or malformed. */
export function parseQuoteRequest(get: (key: string) => string | null | undefined): QuoteRequest | null {
    const id = (key: string): number | null => {
        const v = get(key)?.trim();
        return v && ID.test(v) ? Number(v) : null;
    };
    const districtId = id('district_id');
    if (districtId === null) return null;
    const quantity = id('quantity');
    return {
        districtId,
        upazilaId: id('upazila_id'),
        variantId: id('variant_id'),
        quantity: quantity !== null && quantity <= 99 ? quantity : 1,
    };
}
