import { beforeEach, describe, expect, it, vi } from 'vitest';

// The checkout handler also reads the public tracking config (K-02, its own cached fetch); these
// tests script the API's checkout answers in order, so tracking is off here (see tracking-consent.test.ts).
vi.mock('@/lib/tracking/fetch', async () => ({ getTrackingConfig: async () => (await import('@/lib/tracking/config')).DISABLED_CONFIG }));

import type { StorefrontOrder } from '@/lib/cart/types';
import { checkoutBody, decodeDraft, decodeFlash, encodeDraft, encodeFlash, readDraft, splitErrors, validAmount, validKey } from '@/lib/checkout/form';
import { getMessages } from '@/lib/i18n';
import { orderDateTime, timelineSteps } from '@/lib/order/format';

// P4-STO-09 / P4-STO-10: the checkout form round trip, the POST /api/checkout handler against a
// stubbed API (idempotency key, cart token, expected total, error mapping) and the order display.

const TOKEN = '2f9c8f8e-1b2c-4d5e-8f90-123456789abc';
const KEY = '11111111-2222-4333-8444-555555555555';

function form(fields: Record<string, string>): FormData {
    const data = new FormData();
    for (const [k, v] of Object.entries(fields)) data.set(k, v);
    return data;
}

describe('checkout draft and body', () => {
    it('keeps Bangla text, trims and caps fields, drops unknown ones', () => {
        const draft = readDraft(form({ name: '  সাবরিনা আক্তার ', phone: '01712-345678', landmark: 'আগোরার পাশে', address_line: 'x'.repeat(600), evil: 'no' }));
        expect(draft.name).toBe('সাবরিনা আক্তার');
        expect(draft.address_line).toHaveLength(500);
        expect(draft).not.toHaveProperty('evil');
        expect(decodeDraft(encodeDraft(draft))).toEqual(draft);
        expect(decodeDraft('garbage')).toEqual({});
    });

    it('builds the contract body: the phone as typed, ids as numbers, COD, the shown total, landmark kept', () => {
        const body = checkoutBody({ name: 'Sabrina', phone: '01712-345678', district_id: '13', upazila_id: '101', area: 'Dhanmondi', address_line: 'House 12, Road 5', landmark: 'beside Agora' }, '4320.00');
        expect(body).toMatchObject({ phone: '01712-345678', district_id: 13, upazila_id: 101, landmark: 'beside Agora', payment_method: 'cod', expected_total: '4320.00', email: null });
        expect(checkoutBody({ district_id: '13' }, '1.00').upazila_id).toBeNull(); // Dhaka city: no upazila
    });

    it('splits the API’s 422 errors into field and cart-level messages', () => {
        const { fields, general } = splitErrors([
            { field: 'phone', message: 'Enter an 11-digit mobile number starting with 01 (013–019).' },
            { field: 'items.40', message: 'No longer sold.' },
            { field: 'upazila_id', message: 'We don’t deliver to this area yet.' },
        ]);
        expect(fields).toEqual({ phone: 'Enter an 11-digit mobile number starting with 01 (013–019).', upazila_id: 'We don’t deliver to this area yet.' });
        expect(general).toBe('No longer sold.');
    });

    it('flash cookie accepts only known fields', () => {
        const raw = Buffer.from(JSON.stringify({ errors: { name: 'x', role: 'admin' }, notice: 'price' })).toString('base64url');
        expect(decodeFlash(encodeFlash({ errors: { area: 'a' }, notice: null }))).toEqual({ errors: { area: 'a' }, notice: null });
        expect(decodeFlash(raw)).toEqual({ errors: { name: 'x' }, notice: 'price' });
        expect(validKey(KEY)).toBe(KEY);
        expect(validKey('abc')).toBeNull();
        expect(validAmount('4320.00')).toBe('4320.00');
        expect(validAmount('4,320')).toBeNull();
    });
});

const order = (over: Partial<StorefrontOrder> = {}): StorefrontOrder => ({
    number: 'TMB-26-00042',
    stage: 'placed',
    placed_at: '2026-11-12T05:02:00Z',
    payment: { method: 'cod', status: 'unpaid', next_action: null, online: false, retry_allowed: false, expires_at: null },
    contact: { name: 'Sabrina', phone_masked: '017****5678', email: null },
    shipping: { name: 'Sabrina', phone_masked: '017****5678', division: null, district: null, upazila: null, area: 'Dhanmondi', address_line: null, landmark: null, postcode: null },
    items: [],
    coupon_code: null,
    totals: { subtotal: '1.00', promotion_discount: '0.00', coupon_discount: '0.00', discount_total: '0.00', shipping_charge: '0.00', grand_total: '1.00', paid_total: '0.00', due_total: '1.00' },
    timeline: [{ stage: 'placed', at: '2026-11-12T05:02:00Z' }],
    shipment: null,
    tracking: null,
    ...over,
});

describe('order display', () => {
    it('shows times in Dhaka with Western digits in both locales', () => {
        expect(orderDateTime('2026-11-12T05:02:00Z', getMessages('en'))).toBe('12 Nov, 11:02 am');
        expect(orderDateTime('2026-11-12T08:14:00Z', getMessages('bn'))).toMatch(/^12 .+, 2:14 /);
    });

    it('draws the happy path up to the current stage', () => {
        const steps = timelineSteps(order({ stage: 'shipped', timeline: [{ stage: 'placed', at: 'a' }, { stage: 'confirmed', at: 'b' }, { stage: 'processing', at: 'c' }, { stage: 'shipped', at: 'd' }] }));
        expect(steps.map((s) => s.state)).toEqual(['complete', 'complete', 'complete', 'current', 'upcoming', 'upcoming']);
        expect(steps[3]?.at).toBe('d');
        expect(timelineSteps(order({ stage: 'delivered' })).every((s) => s.state === 'complete')).toBe(true);
    });

    it('ends a cancelled order at the stop', () => {
        const steps = timelineSteps(order({ stage: 'cancelled', timeline: [{ stage: 'placed', at: 'a' }, { stage: 'cancelled', at: 'b' }] }));
        expect(steps.map((s) => [s.stage, s.state])).toEqual([
            ['placed', 'complete'],
            ['cancelled', 'stopped'],
        ]);
    });
});

// ---- POST /api/checkout ---------------------------------------------------------------------------

const json = (status: number, body: unknown, type = 'application/json') => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': type } });
const problem = (status: number, type: string, errors: unknown[] = []) => json(status, { type: `https://toymartbd.com/problems/${type}`, title: type, status, errors }, 'application/problem+json');

const fields = {
    locale: 'en',
    intent: 'place',
    idempotency_key: KEY,
    expected_total: '4320.00',
    name: 'Sabrina Akter',
    phone: '01712-345678',
    district_id: '13',
    upazila_id: '101',
    area: 'Dhanmondi',
    address_line: 'House 12, Road 5, Block C',
    landmark: 'beside Agora',
};

function post(over: Record<string, string> = {}, opts: { cookie?: string | null; json?: boolean } = {}) {
    const headers: Record<string, string> = { 'Content-Type': 'application/x-www-form-urlencoded' };
    if (opts.cookie !== null) headers.cookie = opts.cookie ?? `tm_cart=${TOKEN}`;
    if (opts.json) headers.Accept = 'application/json';
    return new Request('http://storefront.test/api/checkout', { method: 'POST', headers, body: new URLSearchParams({ ...fields, ...over }) });
}

describe('POST /api/checkout', () => {
    let calls: Array<{ url: string; init: RequestInit }>;
    const respondWith = (response: () => Response) => {
        calls = [];
        vi.stubGlobal('fetch', async (url: string, init: RequestInit) => {
            calls.push({ url, init });
            return response();
        });
    };

    beforeEach(() => {
        vi.stubEnv('API_URL', 'http://api.test');
        vi.stubEnv('STOREFRONT_API_TOKEN', 'server-token');
    });

    it('places the order with the cart token, the page’s key and the shown total; 303 to the confirmation', async () => {
        respondWith(() => json(201, order()));
        const { POST } = await import('@/app/api/checkout/route');
        const res = await POST(post());
        expect(res.status).toBe(303);
        expect(res.headers.get('location')).toBe('/orders/TMB-26-00042');
        const headers = calls[0]?.init.headers as Record<string, string>;
        expect(calls[0]?.url).toBe('http://api.test/api/v1/storefront/checkout');
        expect(headers['X-Cart-Token']).toBe(TOKEN);
        expect(headers['Idempotency-Key']).toBe(KEY);
        expect(JSON.parse(String(calls[0]?.init.body))).toMatchObject({ expected_total: '4320.00', landmark: 'beside Agora', payment_method: 'cod' });
        const cookies = res.headers.getSetCookie().join('\n');
        expect(cookies).toMatch(new RegExp(`tm_order=${TOKEN};[^\\n]*HttpOnly`, 'i'));
        expect(cookies).toMatch(/tm_cart=;/); // the cart cookie is cleared
    });

    it('a double submit sends the same key both times (the API replays the same order)', async () => {
        respondWith(() => json(201, order()));
        const { POST } = await import('@/app/api/checkout/route');
        await POST(post());
        await POST(post());
        expect(calls.map((c) => (c.init.headers as Record<string, string>)['Idempotency-Key'])).toEqual([KEY, KEY]);
    });

    it('price changed: back to checkout with the API’s message, nothing else lost', async () => {
        respondWith(() => problem(409, 'price-changed', [{ field: 'expected_total', message: 'The total has changed to ৳4,390.00. Please review your order and place it again.' }]));
        const { POST } = await import('@/app/api/checkout/route');
        const res = await POST(post());
        expect(res.headers.get('location')).toBe('/checkout');
        const cookies = res.headers.getSetCookie();
        const flash = cookies.find((c) => c.startsWith('tm_checkout_flash='))?.split(';')[0]?.split('=')[1];
        expect(decodeFlash(flash)?.notice).toContain('৳4,390.00');
        const draft = cookies.find((c) => c.startsWith('tm_checkout='))?.split(';')[0]?.split('=')[1];
        expect(decodeDraft(draft).landmark).toBe('beside Agora');
    });

    it('field errors come back at their fields; JSON mode asks for an in-place refresh and stores no draft', async () => {
        respondWith(() => problem(422, 'validation-error', [{ field: 'phone', message: 'Enter an 11-digit mobile number starting with 01 (013–019).' }]));
        const { POST } = await import('@/app/api/checkout/route');
        const res = await POST(post({ phone: '01112345678' }, { json: true }));
        expect(await res.json()).toEqual({ refresh: true });
        const cookies = res.headers.getSetCookie();
        expect(cookies.some((c) => c.startsWith('tm_checkout='))).toBe(false);
        const flash = cookies.find((c) => c.startsWith('tm_checkout_flash='))?.split(';')[0]?.split('=')[1];
        expect(decodeFlash(flash)?.errors.phone).toContain('013–019');
    });

    it('no cart cookie: back to the cart without calling the API', async () => {
        respondWith(() => json(500, {}));
        const { POST } = await import('@/app/api/checkout/route');
        const res = await POST(post({}, { cookie: null }));
        expect(res.headers.get('location')).toBe('/cart');
        expect(calls).toHaveLength(0);
    });

    it('a Bangla checkout lands on the Bangla confirmation', async () => {
        respondWith(() => json(201, order()));
        const { POST } = await import('@/app/api/checkout/route');
        const res = await POST(post({ locale: 'bn' }, { json: true }));
        expect(await res.json()).toEqual({ redirect: '/bn/orders/TMB-26-00042' });
        expect((calls[0]?.init.headers as Record<string, string>)['Accept-Language']).toBe('bn');
    });
});
