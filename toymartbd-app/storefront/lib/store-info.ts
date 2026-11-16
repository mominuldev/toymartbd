import 'server-only';

import { cache } from 'react';

import { cached } from './api';
import { pendingLegal, sampleContact, sampleStoreInfo, type BrandImage, type ContactValue, type StoreBranding, type StoreIcons, type StoreInfo } from './site-config';

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
//
// Site & branding (Admin → Settings → Store → Site & branding, same tag): site name, tagline and
// search description per locale, the logo, the share image and the rendered PNG favicons. Every
// field is optional and narrowed from `unknown`: anything malformed (a non-http(s) URL, a missing
// or non-positive size) counts as unset, so the header keeps the wordmark rather than drawing an
// image it cannot reserve space for.

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
    site_name_en?: unknown;
    site_name_bn?: unknown;
    tagline_en?: unknown;
    tagline_bn?: unknown;
    description_en?: unknown;
    description_bn?: unknown;
    logo?: unknown;
    logo_dark?: unknown;
    share_image?: unknown;
    icons?: unknown;
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

/** An absolute http(s) URL, or null. Never a `javascript:`/`data:` value from a malformed answer. */
function httpUrl(value: unknown): string | null {
    const raw = text(value);
    if (raw === null) return null;
    try {
        const url = new URL(raw);
        return url.protocol === 'https:' || url.protocol === 'http:' ? url.toString() : null;
    } catch {
        return null;
    }
}

function dimension(value: unknown): number | null {
    return typeof value === 'number' && Number.isFinite(value) && value > 0 ? Math.round(value) : null;
}

/** `{ url, width, height }` with all three valid, else null (no size = no reserved box = layout shift). */
function image(value: unknown): BrandImage | null {
    if (value === null || typeof value !== 'object') return null;
    const { url, width, height } = value as Record<string, unknown>;
    const src = httpUrl(url);
    const w = dimension(width);
    const h = dimension(height);
    return src !== null && w !== null && h !== null ? { url: src, width: w, height: h } : null;
}

function icons(value: unknown): StoreIcons | null {
    if (value === null || typeof value !== 'object') return null;
    const raw = value as Record<string, unknown>;
    const set: StoreIcons = { png32: httpUrl(raw.png32), png180: httpUrl(raw.png180), png192: httpUrl(raw.png192), png512: httpUrl(raw.png512) };
    return Object.values(set).some((url) => url !== null) ? set : null;
}

function branding(body: StoreInfoResponse, name: StoreInfo['name']): StoreBranding {
    return {
        // Blank site name → the business name (already defaulted to the catalog name above).
        siteName: { en: text(body.site_name_en) ?? name.en, bn: text(body.site_name_bn) ?? name.bn },
        tagline: { en: text(body.tagline_en), bn: text(body.tagline_bn) },
        description: { en: text(body.description_en), bn: text(body.description_bn) },
        logo: image(body.logo),
        logoDark: image(body.logo_dark),
        shareImage: image(body.share_image),
        icons: icons(body.icons),
    };
}

/** API answer → what the components render. Pure; exported for the unit tests. */
export function toStoreInfo(body: StoreInfoResponse): StoreInfo {
    const name = { en: text(body.name_en) ?? sampleStoreInfo.name.en, bn: text(body.name_bn) ?? sampleStoreInfo.name.bn };
    return {
        name,
        contact: { hotline: hotline(body), whatsapp: whatsapp(body), email: email(body), messenger: messenger(body) },
        legal: { tradeLicence: text(body.trade_licence) ?? pendingLegal.tradeLicence, bin: text(body.bin) ?? pendingLegal.bin },
        address: { en: text(body.address_en), bn: text(body.address_bn) },
        branding: branding(body, name),
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
