import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { normaliseConfig, type TrackingConfig } from '@/lib/tracking/config';
import { consentCookieString, effectiveConsent, googleConsent, parseConsent, serializeConsent, type EffectiveConsent } from '@/lib/tracking/consent';
import { buildFbc, fbclidFrom, gaClientId, gaSessionId, resolveFbc, validFbp } from '@/lib/tracking/identifiers';

// K-01/K-02 browser side: consent cookie + versioning, opt-in gating, one event_id across gtag /
// fbq / the server POST, fbc from fbclid, the /api/events Route Handler, and the purchase rules.
// (tests/tracking.test.ts is ORDER tracking — a different feature.)

let incoming = new Headers();
vi.mock('next/headers', () => ({ headers: async () => incoming }));
let serverConfig: TrackingConfig;
vi.mock('@/lib/tracking/fetch', () => ({ TRACKING_CONFIG_TAG: 'tracking-config', getTrackingConfig: async () => serverConfig }));

const browser = await import('@/lib/tracking/browser');
const { POST } = await import('@/app/api/events/route');
const { checkoutTracking } = await import('@/lib/tracking/events');
const { orderTracking } = await import('@/lib/tracking/items');
const { TrackingRoot } = await import('@/components/tracking/TrackingRoot');
const { getMessages } = await import('@/lib/i18n');

const CONFIG = normaliseConfig({
    ga4_measurement_id: 'G-TEST1234',
    meta_pixel_id: '1234567890',
    gtm_container_id: null,
    consent_mode: 'opt_in',
    consent_policy_version: '2026-11',
    purchase_trigger: 'order_placed',
    enabled: { ga4: true, meta: true },
});
const UUID_1 = '3f0e8c0a-1b2c-4d5e-8f90-123456789abc';
const item = { item_id: 'TMB-LEG-CL484', item_name: 'LEGO Classic', price: '4210.00', quantity: 1 };

const granted = (analytics = true, ads = true): EffectiveConsent => ({ analytics, ads, mode: 'opt_in', policyVersion: '2026-11', decided: true });
const undecided = (): EffectiveConsent => effectiveConsent(null, CONFIG);

// ---- A minimal browser: window, document.cookie, localStorage, fetch ---------------------------
let jar: Map<string, string>;
let fetchCalls: Array<{ url: string; init: RequestInit }>;
function fakeBrowser(href = 'https://shop.test/products/lego-classic') {
    jar = new Map();
    fetchCalls = [];
    const store = new Map<string, string>();
    vi.stubGlobal('window', {
        location: new URL(href),
        localStorage: { getItem: (k: string) => store.get(k) ?? null, setItem: (k: string, v: string) => void store.set(k, v) },
    });
    vi.stubGlobal('document', {
        get cookie() {
            return [...jar].map(([k, v]) => `${k}=${v}`).join('; ');
        },
        set cookie(value: string) {
            const [pair, ...attrs] = value.split(';');
            const [name, ...rest] = pair!.split('=');
            if (attrs.some((a) => a.trim() === 'Max-Age=0')) jar.delete(name!.trim());
            else jar.set(name!.trim(), rest.join('='));
        },
    });
    vi.stubGlobal('fetch', async (url: string, init: RequestInit) => {
        fetchCalls.push({ url, init });
        return new Response(null, { status: 202 });
    });
}
type Win = { dataLayer?: unknown[]; fbq?: { queue: unknown[][] } };
const win = () => (globalThis as unknown as { window: Win }).window;
const gtagCalls = () => (win().dataLayer ?? []).map((entry) => (typeof entry === 'object' && entry !== null && 'length' in entry ? Array.from(entry as ArrayLike<unknown>) : entry));

describe('consent cookie', () => {
    it('round-trips and is versioned by consent_policy_version', () => {
        const value = serializeConsent({ policyVersion: '2026-11', analytics: true, ads: false, decidedAt: 1_763_000_000 });
        expect(value).toBe('1|2026-11|1|0|1763000000');
        expect(parseConsent(value)).toEqual({ policyVersion: '2026-11', analytics: true, ads: false, decidedAt: 1_763_000_000 });
        expect(parseConsent(encodeURIComponent(value))?.analytics).toBe(true);

        const stored = parseConsent(value);
        expect(effectiveConsent(stored, CONFIG)).toMatchObject({ analytics: true, ads: false, decided: true });
        // The shop changed its policy: the old answer no longer counts — ask again, deny meanwhile.
        expect(effectiveConsent(stored, { ...CONFIG, policyVersion: '2027-01' })).toMatchObject({ analytics: false, ads: false, decided: false });
    });

    it('rejects malformed or foreign values instead of guessing', () => {
        for (const bad of ['', 'yes', '2|2026-11|1|1|1', '1|2026-11|2|0|1', '1|bad version|1|1|1', '1|2026-11|1|1', '1|2026-11|1|1|x']) {
            expect(parseConsent(bad)).toBeNull();
        }
    });

    it('opt_in denies until answered; notice grants unless the browser sends GPC', () => {
        expect(effectiveConsent(null, CONFIG)).toMatchObject({ analytics: false, ads: false, decided: false, mode: 'opt_in' });
        const notice = { ...CONFIG, consentMode: 'notice' as const };
        expect(effectiveConsent(null, notice)).toMatchObject({ analytics: true, ads: true, decided: false });
        expect(effectiveConsent(null, notice, true)).toMatchObject({ analytics: false, ads: false });
        // An explicit answer always wins over the mode's default.
        expect(effectiveConsent(parseConsent('1|2026-11|0|0|1'), notice)).toMatchObject({ analytics: false, ads: false, decided: true });
    });

    it('writes a Lax, 180-day, Secure-on-https cookie and maps to Consent Mode v2', () => {
        const cookie = consentCookieString({ policyVersion: '2026-11', analytics: true, ads: false, decidedAt: 1 }, true);
        expect(cookie).toBe('tm_consent=1%7C2026-11%7C1%7C0%7C1; Path=/; Max-Age=15552000; SameSite=Lax; Secure');
        expect(googleConsent({ analytics: true, ads: false })).toEqual({ ad_storage: 'denied', ad_user_data: 'denied', ad_personalization: 'denied', analytics_storage: 'granted' });
    });

    it('drops ids that do not have the shape a script URL expects', () => {
        const c = normaliseConfig({ data: { ga4_measurement_id: 'G-1"><script>', meta_pixel_id: 'abc', gtm_container_id: 'GTM-AB12CD', consent_mode: 'weird', consent_policy_version: '1', enabled: { ga4: true, meta: true } } });
        expect(c).toMatchObject({ ga4MeasurementId: null, metaPixelId: null, gtmContainerId: 'GTM-AB12CD', consentMode: 'opt_in', enabled: { ga4: false, meta: false } });
    });
});

describe('browser identifiers', () => {
    it('builds fbc from fbclid as fb.1.<ms>.<fbclid>, case preserved (Meta fbp-and-fbc doc)', () => {
        expect(fbclidFrom('https://shop.test/?fbclid=IwAR2xYz_AbC-9')).toBe('IwAR2xYz_AbC-9');
        expect(buildFbc('IwAR2xYz_AbC-9', 1_763_000_000_123)).toBe('fb.1.1763000000123.IwAR2xYz_AbC-9');
        // An existing _fbc cookie wins over a fresh fbclid.
        expect(resolveFbc('fb.1.1700000000000.OldClick', 'https://shop.test/?fbclid=New', 1)).toBe('fb.1.1700000000000.OldClick');
        expect(resolveFbc('garbage', 'https://shop.test/?fbclid=New', 1_763_000_000_000)).toBe('fb.1.1763000000000.New');
        expect(resolveFbc(null, 'https://shop.test/', 1)).toBeNull();
        expect(fbclidFrom('https://shop.test/?fbclid=bad%20value')).toBeNull();
    });

    it('reads the GA client id and session id from _ga / _ga_<id> (both cookie layouts)', () => {
        expect(gaClientId('GA1.1.1234567890.1763000000')).toBe('1234567890.1763000000');
        expect(gaClientId('nonsense')).toBeNull();
        expect(gaSessionId('GS1.1.1763000500.3.1.1763000600.0.0.0')).toBe('1763000500');
        expect(gaSessionId('GS2.1.s1763000500$o3$g1$t1763000600$j0$l0$h0')).toBe('1763000500');
        expect(validFbp('fb.1.1763000000123.987654321')).toBe('fb.1.1763000000123.987654321');
        expect(validFbp('fb.1.x.y')).toBeNull();
    });
});

describe('browser runtime', () => {
    beforeEach(() => {
        browser.resetTrackingForTests();
        fakeBrowser();
    });

    it('opt-in: before consent no tag stub, no event and no POST — and the root renders no <script>', async () => {
        expect(browser.scriptPlan(CONFIG, undecided())).toEqual({ gtm: null, gtag: null, pixel: null });
        browser.configureTracking(CONFIG, undecided());
        browser.trackEvent('view_item', { value: '4210.00', currency: 'BDT', items: [item] });
        expect(win().dataLayer).toBeUndefined();
        expect(win().fbq).toBeUndefined();
        expect(fetchCalls).toHaveLength(0);

        const html = renderToStaticMarkup(createElement(TrackingRoot, { config: CONFIG, labels: getMessages('en').consent, privacyHref: '/pages/privacy', bangla: false }));
        expect(html).not.toContain('<script');
        expect(html).not.toContain('googletagmanager');
        expect(html).not.toContain('fbevents');
    });

    it('queues an event fired before the root configured, and drops it when consent is missing', () => {
        browser.trackEvent('view_item', { items: [item] });
        browser.configureTracking(CONFIG, undecided());
        expect(fetchCalls).toHaveLength(0);
    });

    it('uses ONE event_id for gtag, fbq and the server POST', async () => {
        browser.configureTracking(CONFIG, granted());
        const id = browser.trackEvent('add_to_cart', { currency: 'BDT', items: [{ ...item, quantity: 2 }] });
        expect(id).toMatch(/^[0-9a-f-]{36}$/);

        // Consent Mode v2: default (all denied) BEFORE anything else, then the update.
        const calls = gtagCalls() as unknown[][];
        expect(calls[0]).toEqual(['consent', 'default', expect.objectContaining({ ad_storage: 'denied', analytics_storage: 'denied', ad_user_data: 'denied', ad_personalization: 'denied' })]);
        expect(calls.findIndex((c) => c[0] === 'consent' && c[1] === 'update')).toBeGreaterThan(0);
        const ga = calls.find((c) => c[0] === 'event');
        expect(ga).toEqual(['event', 'add_to_cart', expect.objectContaining({ event_id: id, currency: 'BDT' })]);

        const meta = win().fbq!.queue.find((args) => args[0] === 'track' && args[1] === 'AddToCart');
        expect(meta?.[3]).toEqual({ eventID: id });

        expect(fetchCalls).toHaveLength(1);
        expect(fetchCalls[0]!.url).toBe('/api/events');
        expect(fetchCalls[0]!.init.keepalive).toBe(true);
        const body = JSON.parse(String(fetchCalls[0]!.init.body));
        expect(body).toEqual({ event_id: id, event_name: 'add_to_cart', event_source_url: 'https://shop.test/products/lego-classic', items: [{ ...item, quantity: 2 }] });
    });

    it('analytics only: GA4 fires, the Pixel never loads; ads only: the reverse', () => {
        browser.configureTracking(CONFIG, granted(true, false));
        expect(browser.scriptPlan(CONFIG, granted(true, false))).toEqual({ gtm: null, gtag: 'G-TEST1234', pixel: null });
        browser.trackEvent('view_item', { items: [item] });
        expect(win().fbq).toBeUndefined();
        expect(browser.scriptPlan(CONFIG, granted(false, true))).toEqual({ gtm: null, gtag: null, pixel: '1234567890' });
    });

    it('with a GTM container, GA4 runs inside it: no gtag.js, events go to the dataLayer', () => {
        const gtm = { ...CONFIG, gtmContainerId: 'GTM-AB12CD' };
        expect(browser.scriptPlan(gtm, granted(true, false))).toEqual({ gtm: 'GTM-AB12CD', gtag: null, pixel: null });
        browser.configureTracking(gtm, granted(true, false));
        const id = browser.trackEvent('begin_checkout', { value: '9120.00', currency: 'BDT', items: [item] });
        expect(win().dataLayer).toContainEqual({ event: 'begin_checkout', event_id: id, ecommerce: expect.objectContaining({ value: 9120 }) });
    });

    it('keeps an fbclid as a first-party _fbc once ads are granted, and removes ids on withdrawal', () => {
        fakeBrowser('https://shop.test/?fbclid=IwAR_Click');
        browser.configureTracking(CONFIG, granted());
        expect(jar.get('_fbc')).toMatch(/^fb\.1\.\d{13}\.IwAR_Click$/);
        jar.set('_ga', 'GA1.1.1.2');
        jar.set('_ga_TEST1234', 'GS1.1.3.1.1.4.0.0.0');
        browser.configureTracking(CONFIG, granted(false, false));
        expect(jar.has('_ga')).toBe(false);
        expect(jar.has('_ga_TEST1234')).toBe(false);
        expect(jar.has('_fbc')).toBe(false);
        expect(win().fbq!.queue).toContainEqual(['consent', 'revoke']);
    });
});

describe('browser purchase', () => {
    beforeEach(() => {
        browser.resetTrackingForTests();
        fakeBrowser('https://shop.test/orders/TMB-26-00042');
    });
    const data = { orderNumber: 'TMB-26-00042', value: '9120.00', currency: 'BDT', items: [item] };
    const purchases = () => (win().fbq?.queue ?? []).filter((args) => args[0] === 'track' && args[1] === 'Purchase');

    it('fires the Pixel Purchase once with the API event id — never to GA4, never POSTed', () => {
        browser.configureTracking(CONFIG, granted());
        browser.trackPurchase(UUID_1, true, data);
        browser.trackPurchase(UUID_1, true, data); // re-render / StrictMode
        expect(purchases()).toHaveLength(1);
        expect(purchases()[0]![3]).toEqual({ eventID: UUID_1 });
        expect(purchases()[0]![2]).toMatchObject({ value: 9120, currency: 'BDT', order_id: 'TMB-26-00042', content_ids: ['TMB-LEG-CL484'] });
        expect((gtagCalls() as unknown[][]).some((c) => c[0] === 'event' && c[1] === 'purchase')).toBe(false);
        expect(fetchCalls).toHaveLength(0);
    });

    it('a reload does not fire again (localStorage), even with a fresh module state', () => {
        browser.configureTracking(CONFIG, granted());
        browser.trackPurchase(UUID_1, true, data);
        const storage = win() as unknown as { localStorage: unknown; fbq?: unknown };
        const kept = storage.localStorage;
        browser.resetTrackingForTests();
        storage.fbq = undefined;
        storage.localStorage = kept;
        browser.configureTracking(CONFIG, granted());
        browser.trackPurchase(UUID_1, true, data);
        expect(purchases()).toHaveLength(0);
    });

    it('does nothing when the API says not to, without ads consent, or with a malformed id', () => {
        browser.configureTracking(CONFIG, granted(true, false));
        browser.trackPurchase(UUID_1, true, data);
        expect(win().fbq).toBeUndefined();
        browser.resetTrackingForTests();
        browser.configureTracking(CONFIG, granted());
        browser.trackPurchase(UUID_1, false, data);
        browser.trackPurchase('not-a-uuid', true, data);
        expect(purchases()).toHaveLength(0);
    });

    it('reads `tracking` off the confirmation only when it has the contract shape', () => {
        expect(orderTracking({ tracking: { purchase_event_id: UUID_1, fire_browser_purchase: true } })).toEqual({ purchase_event_id: UUID_1, fire_browser_purchase: true });
        expect(orderTracking({ tracking: { purchase_event_id: UUID_1, fire_browser_purchase: 'yes' } })).toBeNull();
        expect(orderTracking({})).toBeNull();
    });
});

describe('POST /api/events', () => {
    let apiCalls: Array<{ url: string; init: RequestInit }>;
    let apiStatus: number;
    beforeEach(() => {
        serverConfig = CONFIG;
        apiCalls = [];
        apiStatus = 202;
        incoming = new Headers({ 'x-real-ip': '203.0.113.7' });
        vi.stubEnv('API_URL', 'http://api.test');
        vi.stubEnv('STOREFRONT_API_TOKEN', 'server-token');
        vi.stubGlobal('fetch', async (url: string, init: RequestInit) => {
            apiCalls.push({ url, init });
            return apiStatus === 202 ? Response.json({ event_id: UUID_1 }, { status: 202 }) : Response.json({ type: 'x', title: 'x', status: apiStatus }, { status: apiStatus, headers: { 'Content-Type': 'application/problem+json' } });
        });
    });
    afterEach(() => vi.unstubAllGlobals());

    const consentCookie = (a: 0 | 1, d: 0 | 1) => `tm_consent=${encodeURIComponent(`1|2026-11|${a}|${d}|1763000000`)}`;
    const valid = { event_id: UUID_1, event_name: 'view_item', event_source_url: 'https://shop.test/products/lego?fbclid=IwAR_Click', value: '4210.00', currency: 'BDT', items: [item] };
    function post(body: unknown, headers: Record<string, string> = {}) {
        return new Request('https://shop.test/api/events', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'User-Agent': 'Mozilla/5.0 Test', 'X-Real-IP': '203.0.113.7', ...headers },
            body: typeof body === 'string' ? body : JSON.stringify(body),
        });
    }

    it('forwards with the server token, the shopper IP, UA, GA ids, _fbp and an fbc built from fbclid', async () => {
        const cookie = [consentCookie(1, 1), '_ga=GA1.1.1234567890.1763000000', '_ga_TEST1234=GS1.1.1763000500.3.1.1763000600.0.0.0', '_fbp=fb.1.1763000000123.987654321'].join('; ');
        const res = await POST(post(valid, { Cookie: cookie, Origin: 'https://shop.test', 'Sec-Fetch-Site': 'same-origin' }));
        expect(res.status).toBe(202);
        expect(await res.json()).toEqual({ event_id: UUID_1 });

        expect(apiCalls).toHaveLength(1);
        expect(apiCalls[0]!.url).toBe('http://api.test/api/v1/storefront/tracking/events');
        const headers = apiCalls[0]!.init.headers as Record<string, string>;
        expect(headers.Authorization).toBe('Bearer server-token');
        expect(headers['X-Forwarded-For']).toBe('203.0.113.7');
        const body = JSON.parse(String(apiCalls[0]!.init.body));
        expect(body).toMatchObject({
            event_id: UUID_1,
            event_name: 'view_item',
            value: '4210.00',
            currency: 'BDT',
            items: [item],
            client: { ip: '203.0.113.7', user_agent: 'Mozilla/5.0 Test', ga_client_id: '1234567890.1763000000', ga_session_id: '1763000500', fbp: 'fb.1.1763000000123.987654321' },
            consent: { analytics: true, ads: true, mode: 'opt_in', policy_version: '2026-11' },
        });
        expect(body.client.fbc).toMatch(/^fb\.1\.\d{13}\.IwAR_Click$/);
        expect(body.occurred_at).toMatch(/^\d{4}-\d{2}-\d{2}T/);
    });

    it('sends only the ids the consent covers (analytics only → no _fbp / fbc)', async () => {
        await POST(post(valid, { Cookie: `${consentCookie(1, 0)}; _fbp=fb.1.1763000000123.987654321; _ga=GA1.1.1.2` }));
        const body = JSON.parse(String(apiCalls[0]!.init.body));
        expect(body.client.fbp).toBeUndefined();
        expect(body.client.fbc).toBeUndefined();
        expect(body.client.ga_client_id).toBe('1.2');
    });

    it('opt-in without a decision, or a stale policy version: 204 and nothing forwarded', async () => {
        expect((await POST(post(valid))).status).toBe(204);
        expect((await POST(post(valid, { Cookie: 'tm_consent=1%7C2025-01%7C1%7C1%7C1' }))).status).toBe(204);
        expect((await POST(post(valid, { Cookie: consentCookie(0, 0) }))).status).toBe(204);
        expect(apiCalls).toHaveLength(0);
    });

    it('tracking off: 204, nothing forwarded', async () => {
        serverConfig = normaliseConfig(null);
        expect((await POST(post(valid, { Cookie: consentCookie(1, 1) }))).status).toBe(204);
        expect(apiCalls).toHaveLength(0);
    });

    it('rejects unknown event names (purchase included), bad ids, unknown fields and floats with 422', async () => {
        for (const body of [
            { ...valid, event_name: 'purchase' },
            { ...valid, event_name: 'page_view' },
            { ...valid, event_id: 'abc' },
            { ...valid, value: 4210 },
            { ...valid, value: '4210.00', currency: undefined },
            { ...valid, items: [{ ...item, price: 4210 }] },
            { ...valid, items: [{ ...item, quantity: 0 }] },
            { ...valid, client: { ip: '1.1.1.1' } },
            { ...valid, event_source_url: 'https://evil.test/x' },
        ]) {
            const res = await POST(post(body, { Cookie: consentCookie(1, 1) }));
            expect(res.status).toBe(422);
            expect(res.headers.get('content-type')).toContain('application/problem+json');
        }
        expect(apiCalls).toHaveLength(0);
    });

    it('refuses cross-site posts, non-JSON, oversized and unparsable bodies', async () => {
        expect((await POST(post(valid, { Origin: 'https://evil.test' }))).status).toBe(403);
        expect((await POST(post(valid, { 'Sec-Fetch-Site': 'cross-site' }))).status).toBe(403);
        expect((await POST(post(valid, { 'Content-Type': 'text/plain' }))).status).toBe(415);
        expect((await POST(post('{', { Cookie: consentCookie(1, 1) }))).status).toBe(400);
        expect((await POST(post({ ...valid, pad: 'x'.repeat(20_000) }))).status).toBe(413);
        expect(apiCalls).toHaveLength(0);
    });

    it('passes a rate limit through as 429 and an API failure as 502', async () => {
        apiStatus = 429;
        expect((await POST(post(valid, { Cookie: consentCookie(1, 1) }))).status).toBe(429);
        apiStatus = 500;
        expect((await POST(post(valid, { Cookie: consentCookie(1, 1) }))).status).toBe(502);
    });
});

describe('checkout tracking object', () => {
    it('is null when tracking is off, and carries consent + only the covered ids otherwise', () => {
        const headers = new Headers({ cookie: `tm_consent=${encodeURIComponent('1|2026-11|0|1|1')}; _ga=GA1.1.1.2; _fbp=fb.1.1763000000123.9`, 'user-agent': 'UA', 'x-real-ip': '198.51.100.4' });
        expect(checkoutTracking(headers, normaliseConfig(null))).toBeNull();
        expect(checkoutTracking(headers, CONFIG)).toEqual({
            client: { ip: '198.51.100.4', user_agent: 'UA', fbp: 'fb.1.1763000000123.9' },
            consent: { analytics: false, ads: true, mode: 'opt_in', policy_version: '2026-11' },
        });
    });
});

describe('consent banner markup', () => {
    it('renders an accessible, non-modal dialog in both locales with the privacy link and three choices', async () => {
        const { ConsentBanner } = await import('@/components/tracking/ConsentBanner');
        for (const locale of ['en', 'bn'] as const) {
            const labels = getMessages(locale).consent;
            const html = renderToStaticMarkup(
                createElement(ConsentBanner, {
                    labels,
                    privacyHref: locale === 'bn' ? '/bn/pages/privacy' : '/pages/privacy',
                    bangla: locale === 'bn',
                    mode: 'opt_in',
                    initial: { analytics: false, ads: false },
                    dismissible: false,
                    reopened: false,
                    onDecide: () => undefined,
                    onClose: () => undefined,
                }),
            );
            expect(html).toContain('role="dialog"');
            expect(html).toContain('aria-modal="false"');
            expect(html).toMatch(/aria-labelledby="[^"]+-title"/);
            expect(html).toContain(`href="${locale === 'bn' ? '/bn/pages/privacy' : '/pages/privacy'}"`);
            for (const text of [labels.title, labels.acceptAll, labels.reject, labels.choose, labels.bodyOptIn]) expect(html).toContain(text);
            // First visit: no close button — closing is not a choice.
            expect(html).not.toContain(labels.close);
            expect(html).not.toMatch(/[০-৯]/); // rule 36: Western numerals only
            expect(html).toContain(locale === 'bn' ? 't-h3' : 't-modal-title');
        }
    });
});

describe('POST /api/checkout carries the tracking object', () => {
    const TOKEN = '2f9c8f8e-1b2c-4d5e-8f90-123456789abc';
    const fields = { locale: 'en', intent: 'place', idempotency_key: '11111111-2222-4333-8444-555555555555', expected_total: '1930.00', name: 'Rahela', phone: '01712-345678', district_id: '8', upazila_id: '65', area: 'Agrabad', address_line: 'House 12', payment_method: 'cod' };
    let bodies: unknown[];
    beforeEach(() => {
        bodies = [];
        incoming = new Headers();
        vi.stubEnv('API_URL', 'http://api.test');
        vi.stubEnv('STOREFRONT_API_TOKEN', 'server-token');
        vi.stubGlobal('fetch', async (_url: string, init: RequestInit) => {
            bodies.push(JSON.parse(String(init.body)));
            return Response.json({ number: 'TMB-26-00042', stage: 'placed', payment: { online: false } }, { status: 201 });
        });
    });
    const post = (cookie: string) =>
        new Request('https://shop.test/api/checkout', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', cookie: `tm_cart=${TOKEN}; ${cookie}`, referer: 'https://shop.test/checkout?fbclid=IwAR_Click', 'user-agent': 'UA', 'x-real-ip': '198.51.100.4' },
            body: new URLSearchParams(fields),
        });

    it('adds client + consent read from cookies (works without JavaScript)', async () => {
        serverConfig = CONFIG;
        const { POST: checkout } = await import('@/app/api/checkout/route');
        expect((await checkout(post(`tm_consent=${encodeURIComponent('1|2026-11|1|1|1')}; _fbp=fb.1.1763000000123.9`))).status).toBe(303);
        expect(bodies[0]).toMatchObject({
            expected_total: '1930.00',
            tracking: {
                event_source_url: 'https://shop.test/checkout?fbclid=IwAR_Click',
                client: { ip: '198.51.100.4', user_agent: 'UA', fbp: 'fb.1.1763000000123.9', fbc: expect.stringMatching(/^fb\.1\.\d{13}\.IwAR_Click$/) },
                consent: { analytics: true, ads: true, mode: 'opt_in', policy_version: '2026-11' },
            },
        });
    });

    it('sends no tracking object when tracking is off', async () => {
        serverConfig = normaliseConfig(null);
        const { POST: checkout } = await import('@/app/api/checkout/route');
        await checkout(post(''));
        expect(bodies[0]).not.toHaveProperty('tracking');
    });
});
