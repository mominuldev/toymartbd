import { trackingActive, type TrackingConfig } from './config';
import { GOOGLE_CONSENT_DEFAULT, googleConsent, type EffectiveConsent } from './consent';
import { buildFbc, FBC_COOKIE, FBC_MAX_AGE, fbclidFrom } from './identifiers';
import { META_EVENT, UUID, type BrowserEventName, type TrackingItem } from './types';

// Browser side of K-01/K-02: the tag stubs, the consent signals and the event helpers.
//
// Client-only (window, document, fetch) but importable anywhere: nothing runs at import time.
// TrackingRoot (components/tracking) calls `configureTracking` once it has the public config and
// the visitor's consent; until then helpers queue (bounded) instead of firing, so a page's own
// effects may run before the root's.
//
// Every funnel event gets ONE `event_id` (crypto.randomUUID) used three times:
//   gtag('event', name, {..., event_id})           — GA4 in the browser (analytics consent)
//   fbq('track', MetaName, data, {eventID})         — Meta Pixel (ads consent)
//   POST /api/events {event_id, …}                  — the server twin (Meta CAPI dedups on it)
// https://developers.facebook.com/docs/marketing-api/conversions-api/deduplicate-pixel-and-server-events
//
// `purchase` is different: the browser never POSTs it and never sends it to GA4 (GA4's
// Measurement Protocol cannot dedup, so the server alone reports it there). The confirmation page
// fires only the Meta Pixel Purchase, with the API's deterministic `purchase_event_id`.

type Command = (...args: unknown[]) => void;
interface Fbq extends Command {
    callMethod?: Command;
    queue: unknown[];
    push: Fbq;
    loaded: boolean;
    version: string;
}

declare global {
    interface Window {
        dataLayer?: unknown[];
        gtag?: Command;
        fbq?: Fbq;
        _fbq?: Fbq;
    }
}

/** Which third-party scripts may load, for this config and consent. Pure. */
export interface ScriptPlan {
    /** GTM container id: loaded when analytics OR ads is granted; GA4 then runs inside it. */
    gtm: string | null;
    /** gtag.js with the GA4 measurement id: only without GTM, only with analytics consent. */
    gtag: string | null;
    /** Meta Pixel id: only with ads consent. */
    pixel: string | null;
}

export function scriptPlan(config: TrackingConfig, consent: Pick<EffectiveConsent, 'analytics' | 'ads'>): ScriptPlan {
    const gtm = config.gtmContainerId && (consent.analytics || consent.ads) ? config.gtmContainerId : null;
    return {
        gtm,
        gtag: !gtm && config.enabled.ga4 && consent.analytics ? config.ga4MeasurementId : null,
        pixel: config.enabled.meta && consent.ads ? config.metaPixelId : null,
    };
}

interface State {
    config: TrackingConfig | null;
    consent: EffectiveConsent | null;
    google: boolean;
    meta: boolean;
    pending: Array<() => void>;
}

const state: State = { config: null, consent: null, google: false, meta: false, pending: [] };
const firedPurchases = new Set<string>();
const MAX_PENDING = 20;

/** Tests only: forget everything (module state survives between test cases otherwise). */
export function resetTrackingForTests(): void {
    state.config = null;
    state.consent = null;
    state.google = false;
    state.meta = false;
    state.pending = [];
    firedPurchases.clear();
}

function whenConfigured(run: () => void): void {
    if (state.config && state.consent) run();
    else if (state.pending.length < MAX_PENDING) state.pending.push(run);
}

/** A v4 UUID; crypto.randomUUID needs a secure context, so fall back to getRandomValues. */
export function newEventId(): string {
    if (typeof crypto.randomUUID === 'function') return crypto.randomUUID();
    const b = crypto.getRandomValues(new Uint8Array(16));
    b[6] = (b[6]! & 0x0f) | 0x40;
    b[8] = (b[8]! & 0x3f) | 0x80;
    const h = Array.from(b, (x) => x.toString(16).padStart(2, '0')).join('');
    return `${h.slice(0, 8)}-${h.slice(8, 12)}-${h.slice(12, 16)}-${h.slice(16, 20)}-${h.slice(20)}`;
}

/** dataLayer + gtag stub and the Consent Mode v2 DEFAULT (all denied) — before any Google script. */
function ensureGoogle(plan: ScriptPlan): void {
    if (state.google) return;
    state.google = true;
    window.dataLayer = window.dataLayer ?? [];
    // gtag.js reads Arguments objects off the dataLayer, exactly as Google's snippet pushes them.
    window.gtag = function gtag() {
        // eslint-disable-next-line prefer-rest-params
        window.dataLayer!.push(arguments);
    };
    window.gtag('consent', 'default', { ...GOOGLE_CONSENT_DEFAULT, wait_for_update: 500 });
    window.gtag('js', new Date());
    if (plan.gtag) window.gtag('config', plan.gtag);
    if (plan.gtm) window.dataLayer.push({ 'gtm.start': Date.now(), event: 'gtm.js' });
}

/** The Meta Pixel base-code queue (the same stub as Meta's snippet), then init + PageView. */
function ensureMeta(pixelId: string): void {
    if (state.meta) return;
    state.meta = true;
    if (!window.fbq) {
        const fbq = function (...args: unknown[]) {
            if (fbq.callMethod) fbq.callMethod(...args);
            else fbq.queue.push(args);
        } as Fbq;
        fbq.queue = [];
        fbq.push = fbq;
        fbq.loaded = true;
        fbq.version = '2.0';
        window.fbq = fbq;
        if (!window._fbq) window._fbq = fbq;
    }
    window.fbq('consent', 'grant');
    window.fbq('init', pixelId);
    window.fbq('track', 'PageView');
}

function setCookie(name: string, value: string, maxAge: number): void {
    const secure = window.location.protocol === 'https:' ? '; Secure' : '';
    document.cookie = `${name}=${value}; Path=/; Max-Age=${maxAge}; SameSite=Lax${secure}`;
}

/** Expire a cookie on this host and every parent domain (gtag writes _ga on the registrable domain). */
function dropCookie(name: string): void {
    const parts = window.location.hostname.split('.');
    document.cookie = `${name}=; Path=/; Max-Age=0`;
    for (let i = 0; i < parts.length - 1; i++) document.cookie = `${name}=; Path=/; Max-Age=0; Domain=.${parts.slice(i).join('.')}`;
}

function cookieNames(): string[] {
    return document.cookie.split(';').map((c) => c.split('=')[0]!.trim()).filter(Boolean);
}

/** Apply a (new) consent: stubs + defaults on first grant, `update` signals, cleanup on withdrawal. */
function applyConsent(previous: EffectiveConsent | null): void {
    const { config, consent } = state;
    if (!config || !consent) return;
    const plan = scriptPlan(config, consent);
    if (plan.gtm || plan.gtag) ensureGoogle(plan);
    if (state.google) window.gtag?.('consent', 'update', googleConsent(consent));
    if (plan.pixel) {
        if (state.meta) window.fbq?.('consent', 'grant');
        else ensureMeta(plan.pixel);
    } else if (state.meta) {
        window.fbq?.('consent', 'revoke');
    }

    if (consent.ads) {
        // No Pixel cookie yet but the visitor arrived from a Meta ad: keep the click id first-party
        // (90 days, Meta's recommendation) so the server twin can still send fbc.
        const fbclid = fbclidFrom(window.location.href);
        const fbc = fbclid && !cookieNames().includes(FBC_COOKIE) ? buildFbc(fbclid, Date.now()) : null;
        if (fbc) setCookie(FBC_COOKIE, fbc, FBC_MAX_AGE);
    }
    // Withdrawn: remove the identifiers the tags wrote.
    if (previous?.analytics && !consent.analytics) cookieNames().filter((n) => n === '_ga' || n.startsWith('_ga_')).forEach(dropCookie);
    if (previous?.ads && !consent.ads) [FBC_COOKIE, '_fbp'].forEach(dropCookie);
}

/** Called by TrackingRoot on mount and on every consent change. */
export function configureTracking(config: TrackingConfig, consent: EffectiveConsent): void {
    const previous = state.consent;
    state.config = config;
    state.consent = consent;
    applyConsent(previous);
    const pending = state.pending;
    state.pending = [];
    pending.forEach((run) => run());
}

export interface EventData {
    /** The API's decimal string. Omitted when the page has no API figure for it (the client never computes money). */
    value?: string;
    currency?: string;
    items: TrackingItem[];
}

function ga4Params(data: EventData): Record<string, unknown> {
    return {
        ...(data.currency ? { currency: data.currency } : {}),
        ...(data.value !== undefined ? { value: Number(data.value) } : {}),
        items: data.items.map((item) => ({ ...item, price: Number(item.price) })),
    };
}

function metaParams(data: EventData, name: BrowserEventName | 'purchase'): Record<string, unknown> {
    return {
        ...(data.currency ? { currency: data.currency } : {}),
        ...(data.value !== undefined ? { value: Number(data.value) } : {}),
        content_type: 'product',
        content_ids: data.items.map((i) => i.item_id),
        contents: data.items.map((i) => ({ id: i.item_id, quantity: i.quantity, item_price: Number(i.price) })),
        // Meta: "Use only with InitiateCheckout".
        ...(name === 'begin_checkout' ? { num_items: data.items.reduce((n, i) => n + i.quantity, 0) } : {}),
    };
}

function fire(name: BrowserEventName, data: EventData, eventId: string): void {
    const { config, consent } = state;
    if (!config || !consent || !trackingActive(config)) return;
    // Opt-in and not granted: nothing fires and nothing is POSTed.
    if (!consent.analytics && !consent.ads) return;
    const plan = scriptPlan(config, consent);

    if (consent.analytics && plan.gtm) {
        window.dataLayer?.push({ ecommerce: null });
        window.dataLayer?.push({ event: name, event_id: eventId, ecommerce: ga4Params(data) });
    } else if (consent.analytics && plan.gtag) {
        window.gtag?.('event', name, { ...ga4Params(data), event_id: eventId });
    }
    if (plan.pixel) window.fbq?.('track', META_EVENT[name], metaParams(data, name), { eventID: eventId });

    if (!config.enabled.ga4 && !config.enabled.meta) return;
    const body = {
        event_id: eventId,
        event_name: name,
        event_source_url: window.location.href,
        ...(data.value !== undefined && data.currency ? { value: data.value, currency: data.currency } : {}),
        ...(data.items.length > 0 ? { items: data.items } : {}),
    };
    // keepalive: an add-to-cart followed by "Buy now" navigation must still arrive.
    void fetch('/api/events', { method: 'POST', keepalive: true, credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) }).catch(() => undefined);
}

/**
 * Fire a funnel event (view_item / add_to_cart / begin_checkout): browser tags + server twin with
 * one event_id. Returns that id (for tests and logging); nothing happens without consent.
 */
export function trackEvent(name: BrowserEventName, data: EventData): string {
    const eventId = newEventId();
    whenConfigured(() => fire(name, data, eventId));
    return eventId;
}

const purchaseKey = (eventId: string) => `tm_purchase:${eventId}`;

function purchaseSeen(eventId: string): boolean {
    if (firedPurchases.has(eventId)) return true;
    try {
        return window.localStorage.getItem(purchaseKey(eventId)) === '1';
    } catch {
        return false;
    }
}

function rememberPurchase(eventId: string): void {
    firedPurchases.add(eventId);
    try {
        window.localStorage.setItem(purchaseKey(eventId), '1');
    } catch {
        // Blocked storage: the in-memory set still stops a re-render; Meta dedups a reload on eventID.
    }
}

export interface PurchaseData extends EventData {
    orderNumber: string;
}

/**
 * The browser Purchase, for the order confirmation page. Fires the Meta Pixel only, only when the
 * API said `fire_browser_purchase`, only with ads consent, at most once per event id in this browser
 * (re-render, StrictMode, reload). The server twin is the API's own — nothing is POSTed.
 */
export function trackPurchase(eventId: string, fireBrowserPurchase: boolean, data: PurchaseData): void {
    if (!fireBrowserPurchase || !UUID.test(eventId)) return;
    whenConfigured(() => {
        const { config, consent } = state;
        if (!config || !consent?.ads) return;
        const plan = scriptPlan(config, consent);
        if (!plan.pixel || purchaseSeen(eventId)) return;
        rememberPurchase(eventId);
        window.fbq?.('track', META_EVENT.purchase, { ...metaParams(data, 'purchase'), order_id: data.orderNumber }, { eventID: eventId });
    });
}
