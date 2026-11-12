import { beforeEach, describe, expect, it, vi } from 'vitest';

import { isZero, itemsLabel, lineProblem, lowStockLabel, minusPrice, signedPrice } from '@/lib/cart/format';
import { cartPath, decodeNotice, encodeNotice, parseDestination, serializeDestination, validToken } from '@/lib/cart/server';
import type { CartLine } from '@/lib/cart/types';
import { getMessages } from '@/lib/i18n';

// P4-STO-08: the cart's server helpers, display helpers and Route Handlers. The storefront never
// prices anything: these tests also pin that the handlers forward the token and pass figures through.

const en = getMessages('en');
const bn = getMessages('bn');
const TOKEN = '2f9c8f8e-1b2c-4d5e-8f90-123456789abc';

const line = (over: Partial<CartLine> = {}): CartLine => ({
    id: 12,
    variant_id: 40,
    product: { id: 9, name: 'Remote Control Car', name_bn: 'রিমোট কন্ট্রোল গাড়ি', slug: 'remote-control-car' },
    variant: { sku: 'RCC-RED', name: 'Red', name_bn: 'লাল' },
    quantity: 2,
    available_quantity: 5,
    in_stock: true,
    sellable: true,
    unit_price: '1000.00',
    added_unit_price: '1000.00',
    price_changed: false,
    subtotal: '2000.00',
    discount: '200.00',
    total: '1800.00',
    ...over,
});

describe('cart token and destination cookies', () => {
    it('accepts only a UUID as a cart token', () => {
        expect(validToken(TOKEN)).toBe(TOKEN);
        expect(validToken('not-a-token')).toBeNull();
        expect(validToken('')).toBeNull();
        expect(validToken(undefined)).toBeNull();
    });

    it('round-trips a destination and builds the cart query', () => {
        expect(parseDestination(serializeDestination({ districtId: 13, upazilaId: 101 }))).toEqual({ districtId: 13, upazilaId: 101 });
        expect(parseDestination('13:')).toEqual({ districtId: 13, upazilaId: null });
        expect(parseDestination('x:1')).toBeNull();
        expect(cartPath(null)).toBe('storefront/cart');
        expect(cartPath({ districtId: 13, upazilaId: 101 })).toBe('storefront/cart?district_id=13&upazila_id=101');
    });

    it('keeps a Bangla notice intact through the flash cookie and rejects junk', () => {
        const notice = { kind: 'error' as const, target: '12', message: 'স্টকে মাত্র 3টি আছে।' };
        expect(decodeNotice(encodeNotice(notice))).toEqual(notice);
        expect(decodeNotice('%%%')).toBeNull();
        expect(decodeNotice(Buffer.from('{"kind":"evil"}').toString('base64url'))).toBeNull();
    });
});

describe('cart display helpers (no arithmetic)', () => {
    it('formats the API’s signed and unsigned amounts', () => {
        expect(signedPrice('-200.00')).toBe('−৳200');
        expect(signedPrice('130.00')).toBe('৳130');
        expect(minusPrice('425.00')).toBe('−৳425');
        expect(isZero('0.00')).toBe(true);
        expect(isZero('0.50')).toBe(false);
        expect(isZero(null)).toBe(false);
    });

    it('names a line’s problem from the API flags only', () => {
        expect(lineProblem(line(), en.cart)).toBeNull();
        expect(lineProblem(line({ sellable: false }), en.cart)).toBe(en.cart.unavailable);
        expect(lineProblem(line({ in_stock: false, available_quantity: 2, quantity: 3 }), en.cart)).toBe('Only 2 in stock — lower the quantity to continue');
        expect(lineProblem(line({ in_stock: false, available_quantity: 2, quantity: 3 }), bn.cart)).toContain('2');
    });

    it('shows "Only N left" at the stepper ceiling and never invents a threshold', () => {
        expect(lowStockLabel(line({ quantity: 2, available_quantity: 5 }), en.cart)).toBeNull();
        expect(lowStockLabel(line({ quantity: 2, available_quantity: 2 }), en.cart)).toBe('Only 2 left');
        expect(lowStockLabel(line({ available_quantity: 0, in_stock: false }), en.cart)).toBe(en.cart.outOfStock);
    });

    it('counts items in both locales with Western digits', () => {
        expect(itemsLabel(1, en.cart)).toBe('1 item');
        expect(itemsLabel(3, en.cart)).toBe('3 items');
        expect(itemsLabel(3, bn.cart)).toBe('3টি পণ্য');
    });
});

// ---- Route Handlers against a stubbed API --------------------------------------------------------

const cartBody = (over: Record<string, unknown> = {}) => ({ token: TOKEN, item_count: 3, items: [], totals: { grand_total: '1930.00' }, ...over });

function stubApi(respond: (url: string, init: RequestInit) => Response) {
    const calls: Array<{ url: string; init: RequestInit }> = [];
    vi.stubGlobal('fetch', async (url: string, init: RequestInit) => {
        calls.push({ url, init });
        return respond(url, init);
    });
    return calls;
}

const json = (status: number, body: unknown, type = 'application/json') => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': type } });

function formRequest(path: string, fields: Record<string, string>, opts: { cookie?: string; json?: boolean } = {}) {
    const body = new URLSearchParams(fields);
    const headers: Record<string, string> = { 'Content-Type': 'application/x-www-form-urlencoded' };
    if (opts.cookie) headers.cookie = opts.cookie;
    if (opts.json) headers.Accept = 'application/json';
    return new Request(`http://storefront.test${path}`, { method: 'POST', headers, body });
}

describe('POST /api/cart/items', () => {
    beforeEach(() => {
        vi.stubEnv('API_URL', 'http://api.test');
        vi.stubEnv('STOREFRONT_API_TOKEN', 'server-token');
    });

    it('creates the cart without JS: 303 to the cart, token in an httpOnly cookie, count readable', async () => {
        const calls = stubApi(() => json(201, cartBody()));
        const { POST } = await import('@/app/api/cart/items/route');
        const res = await POST(formRequest('/api/cart/items', { variant_id: '40', quantity: '2', locale: 'bn', intent: 'add' }));
        expect(res.status).toBe(303);
        expect(res.headers.get('location')).toBe('/bn/cart');
        const cookies = res.headers.getSetCookie().join('\n');
        expect(cookies).toMatch(new RegExp(`tm_cart=${TOKEN};[^\\n]*HttpOnly`, 'i'));
        expect(cookies).toMatch(/tm_cart_count=3;(?![^\n]*HttpOnly)/i);
        expect(calls[0]?.url).toBe('http://api.test/api/v1/storefront/cart/items');
        expect(JSON.parse(String(calls[0]?.init.body))).toEqual({ variant_id: 40, quantity: 2 });
        expect((calls[0]?.init.headers as Record<string, string>)['X-Cart-Token']).toBeUndefined();
    });

    it('forwards an existing token and sends Buy now to checkout', async () => {
        const calls = stubApi(() => json(200, cartBody()));
        const { POST } = await import('@/app/api/cart/items/route');
        const res = await POST(formRequest('/api/cart/items', { variant_id: '40', quantity: '1', locale: 'en', intent: 'buy_now' }, { cookie: `tm_cart=${TOKEN}` }));
        expect(res.headers.get('location')).toBe('/checkout');
        expect((calls[0]?.init.headers as Record<string, string>)['X-Cart-Token']).toBe(TOKEN);
    });

    it('answers JSON with the API’s own stock message (never the token)', async () => {
        stubApi(() => json(422, { type: 'https://toymartbd.com/problems/validation-error', title: 'x', status: 422, errors: [{ field: 'quantity', message: 'Only 3 left in stock.' }] }, 'application/problem+json'));
        const { POST } = await import('@/app/api/cart/items/route');
        const res = await POST(formRequest('/api/cart/items', { variant_id: '40', quantity: '9', locale: 'en' }, { json: true }));
        expect(res.status).toBe(422);
        const body = await res.json();
        expect(body).toEqual({ ok: false, itemCount: null, notice: { kind: 'error', target: 'add', message: 'Only 3 left in stock.' } });
        expect(JSON.stringify(body)).not.toContain(TOKEN);
    });
});
