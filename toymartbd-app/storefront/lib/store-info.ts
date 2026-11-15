import 'server-only';

import { cache } from 'react';

import { cached } from './api';
import { pendingLegal, sampleContact, sampleStoreInfo, type ContactValue, type StoreInfo } from './site-config';

// The shop's contact and legal details (launch item F4), read server-side only (CLAUDE.md rule 28)
// from `GET /storefront/store-info` — Admin → Settings → Store → Business. ISR-cached under the
// `store-info` tag, which Laravel purges on every Business / invoice-details save
// (docs/api/revalidation.md). Never throws: the header, footer and error copy must render even
// when the API is down, so a failure costs the live values, never the page.
//
// Fallback, field by field: a value the owner has not set (null or blank) becomes the marked
// sample from lib/site-config.ts with `placeholder: true`, which is never rendered as a link. A
// set value becomes a link (tel:, https://wa.me/…, mailto:, https://m.me/…) built from the API's own normalised
// form; the storefront does not re-validate or reformat a phone number. Messenger has no sample:
// unset (or not a valid Page username/id) is null, and no Messenger link is shown.

export const STORE_INFO_TAG = 'store-info';

/** `StoreInfoResource` (packages/api-types). Every field may be null except the names. */
export interface StoreInfoResponse {
    name_en?: unknown;
    name_bn?: unknown;
    hotline?: unknown;
    hotline_tel?: unknown;
    whatsapp?: unknown;
    whatsapp_display?: unknown;
    messenger?: unknown;
    email?: unknown;
    address_en?: unknown;
    address_bn?: unknown;
    trade_licence?: unknown;
    bin?: unknown;
}

function text(value: unknown): string | null {
    return typeof value === 'string' && value.trim() !== '' ? value.trim() : null;
}

function hotline(body: StoreInfoResponse): ContactValue {
    const display = text(body.hotline);
    const tel = text(body.hotline_tel);
    return display && tel?.startsWith('tel:') ? { display, href: tel, placeholder: false } : sampleContact.hotline;
}

function whatsapp(body: StoreInfoResponse): ContactValue {
    const e164 = text(body.whatsapp);
    const digits = e164?.replace(/\D+/g, '') ?? '';
    if (!e164 || digits === '') return sampleContact.whatsapp;
    return { display: text(body.whatsapp_display) ?? e164, href: `https://wa.me/${digits}`, placeholder: false };
}

/** The Page username or numeric id, as the admin validates it; anything else is treated as unset. */
const MESSENGER_PAGE = /^(?:[A-Za-z0-9.]{5,50}|[0-9]{1,50})$/;

function messenger(body: StoreInfoResponse): ContactValue | null {
    const page = text(body.messenger);
    return page && MESSENGER_PAGE.test(page) ? { display: page, href: `https://m.me/${page}`, placeholder: false } : null;
}

function email(body: StoreInfoResponse): ContactValue {
    const address = text(body.email);
    return address && address.includes('@') ? { display: address, href: `mailto:${address}`, placeholder: false } : sampleContact.email;
}

/** API answer → what the components render. Pure; exported for the unit tests. */
export function toStoreInfo(body: StoreInfoResponse): StoreInfo {
    return {
        name: { en: text(body.name_en) ?? sampleStoreInfo.name.en, bn: text(body.name_bn) ?? sampleStoreInfo.name.bn },
        contact: { hotline: hotline(body), whatsapp: whatsapp(body), email: email(body), messenger: messenger(body) },
        legal: { tradeLicence: text(body.trade_licence) ?? pendingLegal.tradeLicence, bin: text(body.bin) ?? pendingLegal.bin },
        address: { en: text(body.address_en), bn: text(body.address_bn) },
    };
}

/** The store details for this render, deduplicated per request. Never throws. */
export const getStoreInfo = cache(async (): Promise<StoreInfo> => {
    try {
        const body = await cached<StoreInfoResponse | { data: StoreInfoResponse }>('storefront/store-info', [STORE_INFO_TAG]);
        return toStoreInfo(body !== null && typeof body === 'object' && 'data' in body && body.data !== null && typeof body.data === 'object' ? body.data : (body as StoreInfoResponse));
    } catch (error) {
        console.error('[store-info] unavailable, showing samples:', error instanceof Error ? error.message : error);
        return sampleStoreInfo;
    }
});
