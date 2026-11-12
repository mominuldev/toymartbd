import 'server-only';

import { cached } from '../api';
import type { Upazila } from '../delivery/types';
import type { Locale } from '../i18n/config';

// The upazilas of one district for the checkout address (docs/api/delivery.md
// `GET /districts/{id}/upazilas`), cached under the `delivery` tag like the rest of the geography.
// Empty on failure: the select then shows only its placeholder and the API's 422 explains.
export async function getUpazilas(districtId: number, locale: Locale): Promise<Upazila[]> {
    try {
        return (await cached<{ data: Upazila[] }>(`storefront/districts/${districtId}/upazilas`, ['delivery'], { locale })).data;
    } catch {
        return [];
    }
}
