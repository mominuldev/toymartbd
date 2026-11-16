import 'server-only';

import { cache } from 'react';

import { cached } from './api';
import type { Locale } from './i18n';
import { siteMetaFor, type SiteMeta } from './seo/metadata';
import { pendingLegal, sampleContact, sampleStoreInfo, type ContactValue, type SiteIdentity, type SiteImage, type StoreInfo } from './site-config';

// The shop's contact and legal details (launch item F4), read server-side only (CLAUDE.md rule 28)
// from `GET /storefront/store-info` — Admin → Settings → Store → Business, and its `site` part from
// Store → Site & branding (name, tagline, description, logo, favicons, share image). ISR-cached under the
// `store-info` tag, which Laravel purges on every Business / invoice-details / Site & branding save
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
    /** Site & branding (StoreInfoResource `site`). */
    site?: unknown;
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

function record(value: unknown): Record<string, unknown> | null {
    return value !== null && typeof value === 'object' && !Array.isArray(value) ? (value as Record<string, unknown>) : null;
}

/** An absolute http(s) URL, else null — a URL from the API is never trusted to be anything else. */
function httpUrl(value: unknown): string | null {
    const raw = text(value);
    if (!raw) return null;
    try {
        const url = new URL(raw);
        return url.protocol === 'https:' || url.protocol === 'http:' ? url.href : null;
    } catch {
        return null;
    }
}

function dimension(value: unknown): number | null {
    return typeof value === 'number' && Number.isInteger(value) && value > 0 ? value : null;
}

function image(value: unknown): SiteImage | null {
    const body = record(value);
    const url = body ? httpUrl(body.url) : null;
    return body && url ? { url, width: dimension(body.width), height: dimension(body.height) } : null;
}

/** `site` → SiteIdentity. Names fall back to the business name (`name_*`), then the sample. */
export function toSite(value: unknown, businessName: { en: string; bn: string }): SiteIdentity {
    const site = record(value) ?? {};
    const taglineEn = text(site.tagline_en);
    const descriptionEn = text(site.description_en);
    const icons = Array.isArray(site.icons)
        ? site.icons.flatMap((icon) => {
              const body = record(icon);
              const size = body ? dimension(body.size) : null;
              const url = body ? httpUrl(body.url) : null;
              return size && url ? [{ size, url }] : [];
          })
        : [];
    return {
        name: { en: text(site.name_en) ?? businessName.en, bn: text(site.name_bn) ?? businessName.bn },
        tagline: { en: taglineEn, bn: text(site.tagline_bn) ?? taglineEn },
        description: { en: descriptionEn, bn: text(site.description_bn) ?? descriptionEn },
        logo: image(site.logo),
        logoDark: image(site.logo_dark),
        icons: icons.sort((a, b) => a.size - b.size),
        socialImage: image(site.social_image),
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
        site: toSite(body.site, name),
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

/** The store's identity in one locale, for a page's metadata (Open Graph site name, share image, favicons). Never throws. */
export async function getSiteMeta(locale: Locale): Promise<SiteMeta> {
    return siteMetaFor(locale, (await getStoreInfo()).site);
}
