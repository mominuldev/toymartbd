import { clientIpFrom } from '../client-ip';
import { DISABLED_CONFIG, type TrackingConfig } from './config';
import { consentPayload, CONSENT_COOKIE, effectiveConsent, parseConsent, type ConsentPayload, type EffectiveConsent } from './consent';
import { BROWSER_EVENTS, UUID, type BrowserEventName, type TrackingItem } from './types';
import { cookieValue, FBC_COOKIE, FBP_COOKIE, gaClientId, gaSessionCookieName, gaSessionId, resolveFbc, validFbp } from './identifiers';

// The browser → Route Handler → API event contract (K-01/K-02). The browser may only report the
// three funnel events; `purchase` is server-originated (the API fires it from the order), so a
// browser cannot inflate conversions by POSTing one.

export { BROWSER_EVENTS, UUID, type BrowserEventName, type TrackingItem };

export function isBrowserEvent(value: unknown): value is BrowserEventName {
    return typeof value === 'string' && (BROWSER_EVENTS as readonly string[]).includes(value);
}

/** What the browser POSTs to /api/events. Identifiers and consent are NOT in it: the handler reads them from cookies. */
export interface BrowserEventBody {
    event_id: string;
    event_name: BrowserEventName;
    event_source_url: string;
    value?: string;
    currency?: 'BDT';
    items?: TrackingItem[];
}

export interface TrackingClient {
    ip: string | null;
    user_agent: string | null;
    ga_client_id?: string;
    ga_session_id?: string;
    fbp?: string;
    fbc?: string;
}

/** `POST /api/v1/storefront/tracking/events` body. */
export interface ApiEventBody extends Omit<BrowserEventBody, 'event_name'> {
    event_name: BrowserEventName;
    occurred_at: string;
    client: TrackingClient;
    consent: ConsentPayload;
}

const DECIMAL = /^\d{1,12}(\.\d{1,2})?$/;
/** The API accepts BDT only (docs/api/tracking.md §2.2). */
const CURRENCY = /^BDT$/;
export const MAX_BODY_BYTES = 16_384;
const MAX_ITEMS = 50;

export type Validation<T> = { ok: true; value: T } | { ok: false; errors: Array<{ field: string; message: string }> };

function text(value: unknown, max: number): string | null {
    if (typeof value !== 'string') return null;
    const trimmed = value.trim();
    return trimmed !== '' && trimmed.length <= max ? trimmed : null;
}

function validItem(raw: unknown): TrackingItem | null {
    if (!raw || typeof raw !== 'object' || Array.isArray(raw)) return null;
    const r = raw as Record<string, unknown>;
    const item_id = text(r.item_id, 64);
    const item_name = text(r.item_name, 200);
    const price = typeof r.price === 'string' && DECIMAL.test(r.price) ? r.price : null;
    const quantity = typeof r.quantity === 'number' && Number.isInteger(r.quantity) && r.quantity >= 1 && r.quantity <= 999 ? r.quantity : null;
    if (!item_id || !item_name || price === null || quantity === null) return null;
    const item: TrackingItem = { item_id, item_name, price, quantity };
    for (const key of ['item_brand', 'item_category', 'item_variant'] as const) {
        if (r[key] === undefined || r[key] === null) continue;
        const value = text(r[key], 200);
        if (value === null) return null;
        item[key] = value;
    }
    return item;
}

/**
 * Validates the browser's body. An unknown event name, a non-UUID id, a float or a cross-site
 * `event_source_url` is a 422 — never coerced. `siteOrigins`: the origins this storefront answers on.
 */
export function validateBrowserEvent(raw: unknown, siteOrigins: readonly string[]): Validation<BrowserEventBody> {
    const errors: Array<{ field: string; message: string }> = [];
    if (!raw || typeof raw !== 'object' || Array.isArray(raw)) return { ok: false, errors: [{ field: '', message: 'Body must be a JSON object.' }] };
    const r = raw as Record<string, unknown>;

    const allowed = new Set(['event_id', 'event_name', 'event_source_url', 'value', 'currency', 'items']);
    for (const key of Object.keys(r)) if (!allowed.has(key)) errors.push({ field: key, message: 'Unknown field.' });

    if (typeof r.event_id !== 'string' || !UUID.test(r.event_id)) errors.push({ field: 'event_id', message: 'Must be a UUID.' });
    if (!isBrowserEvent(r.event_name)) errors.push({ field: 'event_name', message: `Must be one of: ${BROWSER_EVENTS.join(', ')}.` });

    let sourceOk = false;
    if (typeof r.event_source_url === 'string' && r.event_source_url.length <= 2048) {
        try {
            sourceOk = siteOrigins.includes(new URL(r.event_source_url).origin);
        } catch {
            sourceOk = false;
        }
    }
    if (!sourceOk) errors.push({ field: 'event_source_url', message: 'Must be a URL on this site.' });

    if (r.value !== undefined && !(typeof r.value === 'string' && DECIMAL.test(r.value))) errors.push({ field: 'value', message: 'Must be a decimal string.' });
    if (r.currency !== undefined && !(typeof r.currency === 'string' && CURRENCY.test(r.currency))) errors.push({ field: 'currency', message: 'Must be BDT.' });
    if (r.value !== undefined && r.currency === undefined) errors.push({ field: 'currency', message: 'Required with value.' });

    let items: TrackingItem[] | undefined;
    if (r.items !== undefined) {
        if (!Array.isArray(r.items) || r.items.length < 1 || r.items.length > MAX_ITEMS) {
            errors.push({ field: 'items', message: `Must be an array of 1–${MAX_ITEMS} items.` });
        } else {
            items = [];
            r.items.forEach((entry, i) => {
                const item = validItem(entry);
                if (item) items!.push(item);
                else errors.push({ field: `items.${i}`, message: 'Invalid item.' });
            });
        }
    }

    if (errors.length > 0) return { ok: false, errors };
    return {
        ok: true,
        value: {
            event_id: (r.event_id as string).toLowerCase(),
            event_name: r.event_name as BrowserEventName,
            event_source_url: r.event_source_url as string,
            ...(r.value !== undefined ? { value: r.value as string } : {}),
            ...(r.currency !== undefined ? { currency: 'BDT' as const } : {}),
            ...(items ? { items } : {}),
        },
    };
}

/** Request facts the server-side twin needs, gathered by a Route Handler. */
export interface RequestFacts {
    headers: Pick<Headers, 'get'>;
    config: TrackingConfig;
    eventSourceUrl: string | null;
    nowMs: number;
}

/**
 * Consent from the `tm_consent` cookie (plus `Sec-GPC`), and the client block. Identifiers are
 * attached only under the consent that covers them: GA ids need analytics, _fbp/_fbc need ads.
 * IP and user agent always travel — the API decides, per destination, whether anything is sent.
 */
export function requestContext({ headers, config, eventSourceUrl, nowMs }: RequestFacts): { consent: EffectiveConsent; client: TrackingClient } {
    const cookies = headers.get('cookie');
    const consent = effectiveConsent(parseConsent(cookieValue(cookies, CONSENT_COOKIE)), config, headers.get('sec-gpc') === '1');
    const ua = headers.get('user-agent');
    const client: TrackingClient = { ip: clientIpFrom(headers), user_agent: ua ? ua.slice(0, 512) : null };
    if (consent.analytics) {
        const cid = gaClientId(cookieValue(cookies, '_ga'));
        if (cid) client.ga_client_id = cid;
        const sid = config.ga4MeasurementId ? gaSessionId(cookieValue(cookies, gaSessionCookieName(config.ga4MeasurementId))) : null;
        if (sid) client.ga_session_id = sid;
    }
    if (consent.ads) {
        const fbp = validFbp(cookieValue(cookies, FBP_COOKIE));
        if (fbp) client.fbp = fbp;
        const fbc = resolveFbc(cookieValue(cookies, FBC_COOKIE), eventSourceUrl, nowMs);
        if (fbc) client.fbc = fbc;
    }
    return { consent, client };
}

/** Checkout's optional `tracking` object (same `client` + `consent` shape), or null when tracking is off. */
export function checkoutTracking(
    headers: Pick<Headers, 'get'>,
    config: TrackingConfig = DISABLED_CONFIG,
    nowMs = Date.now(),
): { client: TrackingClient; consent: ConsentPayload; event_source_url?: string } | null {
    if (!config.enabled.ga4 && !config.enabled.meta) return null;
    const referer = headers.get('referer');
    const source = referer && referer.length <= 2048 && /^https?:\/\//.test(referer) ? referer : null;
    const { consent, client } = requestContext({ headers, config, eventSourceUrl: source, nowMs });
    return { client, consent: consentPayload(consent), ...(source ? { event_source_url: source } : {}) };
}
