import { beforeEach, describe, expect, it, vi } from 'vitest';

import { checkoutBody, readPaymentMethod } from '@/lib/checkout/form';

// P4-PAY-02/03/04 — online payment in the storefront: the method choice (COD default), checkout
// handing the browser to the gateway, the gateway's return being forwarded untouched and never
// trusted, and the retry. The API is stubbed; nothing here decides money.

const TOKEN = '2f9c8f8e-1b2c-4d5e-8f90-123456789abc';
const KEY = '11111111-2222-4333-8444-555555555555';
const GATEWAY = 'https://sandbox.sslcommerz.com/gwprocess/v4/gw.php?Q=PAY&SESSIONKEY=A1B2';

const json = (status: number, body: unknown, type = 'application/json') => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': type } });
const problem = (status: number, extra: Record<string, unknown>) => json(status, { type: 'https://toymartbd.com/problems/conflict', title: 'Conflict', status, ...extra }, 'application/problem+json');

function form(fields: Record<string, string>): FormData {
    const data = new FormData();
    for (const [k, v] of Object.entries(fields)) data.set(k, v);
    return data;
}

const placed = {
    number: 'TMB-26-00042',
    stage: 'placed',
    payment: { method: 'sslcommerz', status: 'unpaid', next_action: null, online: true, retry_allowed: true, expires_at: null },
};

let calls: Array<{ url: string; init: RequestInit }>;
function api(answers: Array<() => Response>) {
    calls = [];
    vi.stubGlobal('fetch', async (url: string, init: RequestInit) => {
        calls.push({ url, init });
        const next = answers[Math.min(calls.length - 1, answers.length - 1)];
        return next ? next() : json(500, {});
    });
}

beforeEach(() => {
    vi.stubEnv('API_URL', 'http://api.test');
    vi.stubEnv('STOREFRONT_API_TOKEN', 'server-token');
});

describe('payment method choice', () => {
    it('is COD unless the form names an allowed online method', () => {
        expect(readPaymentMethod(form({}), ['sslcommerz'])).toBe('cod');
        expect(readPaymentMethod(form({ payment_method: 'sslcommerz' }), ['sslcommerz'])).toBe('sslcommerz');
        expect(readPaymentMethod(form({ payment_method: 'bkash' }), ['sslcommerz'])).toBe('cod');
        expect(readPaymentMethod(form({ payment_method: 'sslcommerz' }))).toBe('cod');
        expect(checkoutBody({}, '1.00').payment_method).toBe('cod');
        expect(checkoutBody({}, '1.00', 'sslcommerz').payment_method).toBe('sslcommerz');
    });
});

const checkoutFields = {
    locale: 'bn',
    intent: 'place',
    idempotency_key: KEY,
    expected_total: '1930.00',
    name: 'রাহেলা খাতুন',
    phone: '01712-345678',
    district_id: '8',
    upazila_id: '65',
    area: 'আগ্রাবাদ',
    address_line: 'বাড়ি ১২, রোড ৫',
    payment_method: 'sslcommerz',
};

function checkoutPost(opts: { json?: boolean } = {}) {
    const headers: Record<string, string> = { 'Content-Type': 'application/x-www-form-urlencoded', cookie: `tm_cart=${TOKEN}` };
    if (opts.json) headers.Accept = 'application/json';
    return new Request('http://storefront.test/api/checkout', { method: 'POST', headers, body: new URLSearchParams(checkoutFields) });
}

describe('POST /api/checkout with an online method', () => {
    it('places the order, opens the session with the cart token, and 303s to the gateway', async () => {
        api([() => json(201, placed), () => json(200, { data: { next_action: { type: 'redirect', url: GATEWAY } } })]);
        const { POST } = await import('@/app/api/checkout/route');
        const res = await POST(checkoutPost());

        expect(res.status).toBe(303);
        expect(res.headers.get('location')).toBe(GATEWAY);
        expect(JSON.parse(String(calls[0]?.init.body))).toMatchObject({ payment_method: 'sslcommerz' });
        expect(calls[1]?.url).toBe('http://api.test/api/v1/storefront/orders/TMB-26-00042/payment');
        const headers = calls[1]?.init.headers as Record<string, string>;
        expect(headers['X-Cart-Token']).toBe(TOKEN);
        expect(headers['Idempotency-Key']).toBe(KEY);
        expect(res.headers.getSetCookie().join('\n')).toMatch(new RegExp(`tm_order=${TOKEN}`));
    });

    it('JSON mode returns the gateway URL as the redirect', async () => {
        api([() => json(201, placed), () => json(200, { data: { next_action: { type: 'redirect', url: GATEWAY } } })]);
        const { POST } = await import('@/app/api/checkout/route');
        expect(await (await POST(checkoutPost({ json: true }))).json()).toEqual({ redirect: GATEWAY });
    });

    it('goes to the payment page when no session opens, and never follows a non-https URL', async () => {
        api([() => json(201, placed), () => problem(409, { reason: 'gateway_unavailable' })]);
        const { POST } = await import('@/app/api/checkout/route');
        expect((await POST(checkoutPost())).headers.get('location')).toBe('/bn/orders/TMB-26-00042/payment');

        api([() => json(201, placed), () => json(200, { data: { next_action: { type: 'redirect', url: 'http://evil.example/pay' } } })]);
        expect((await POST(checkoutPost())).headers.get('location')).toBe('/bn/orders/TMB-26-00042/payment');
    });
});

describe('the gateway return', () => {
    const returnPost = (outcome: string, body: Record<string, string>) =>
        new Request(`http://storefront.test/api/payments/sslcommerz/TMB-26-00042/${outcome}?locale=bn`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams(body),
        });
    const params = (outcome: string) => ({ params: Promise.resolve({ number: 'TMB-26-00042', outcome }) });

    it('forwards the signed fields untouched to the API, then 303s to the payment page whatever the path says', async () => {
        api([() => json(200, { data: { order_number: 'TMB-26-00042', payment_status: 'paid' } })]);
        const { POST } = await import('@/app/api/payments/sslcommerz/[number]/[outcome]/route');
        const signed = { tran_id: 'TMB-26-00042-P7', val_id: 'V1', status: 'VALID', amount: '1930.00', verify_key: 'amount,status,tran_id,val_id', verify_sign: 'abc123' };

        const res = await POST(returnPost('success', signed), params('success'));

        expect(res.status).toBe(303);
        expect(res.headers.get('location')).toBe('/bn/orders/TMB-26-00042/payment');
        expect(calls[0]?.url).toBe('http://api.test/api/v1/storefront/payments/sslcommerz/return');
        expect(JSON.parse(String(calls[0]?.init.body))).toEqual(signed);
    });

    it('a forged return (401 from the API) or an unreachable API still lands on the page that re-reads the order', async () => {
        const { POST, GET } = await import('@/app/api/payments/sslcommerz/[number]/[outcome]/route');
        api([() => json(401, { type: 'https://toymartbd.com/problems/unauthenticated', title: 'x', status: 401 }, 'application/problem+json')]);
        expect((await POST(returnPost('success', { status: 'VALID' }), params('success'))).headers.get('location')).toBe('/bn/orders/TMB-26-00042/payment');

        vi.stubGlobal('fetch', async () => {
            throw new Error('down');
        });
        expect((await POST(returnPost('fail', {}), params('fail'))).headers.get('location')).toBe('/bn/orders/TMB-26-00042/payment');
        expect((await GET(new Request('http://storefront.test/api/payments/sslcommerz/x/success'), { params: Promise.resolve({ number: '<script>', outcome: 'success' }) })).headers.get('location')).toBe('/track');
    });
});

describe('POST /api/payments/retry', () => {
    const retryPost = (cookie: string | null) =>
        new Request('http://storefront.test/api/payments/retry', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', ...(cookie ? { cookie } : {}) },
            body: new URLSearchParams({ number: 'TMB-26-00042', locale: 'en', idempotency_key: KEY }),
        });

    it('opens a new session with the order cookie and goes to the gateway', async () => {
        api([() => json(200, { data: { next_action: { type: 'redirect', url: GATEWAY } } })]);
        const { POST } = await import('@/app/api/payments/retry/route');
        const res = await POST(retryPost(`tm_order=${TOKEN}`));
        expect(res.headers.get('location')).toBe(GATEWAY);
        expect((calls[0]?.init.headers as Record<string, string>)['X-Cart-Token']).toBe(TOKEN);
    });

    it('comes back with the API’s reason when it refuses, and asks nothing without the cookie', async () => {
        api([() => problem(409, { reason: 'too_many_attempts' })]);
        const { POST } = await import('@/app/api/payments/retry/route');
        const res = await POST(retryPost(`tm_order=${TOKEN}`));
        expect(res.headers.get('location')).toBe('/orders/TMB-26-00042/payment');
        expect(res.headers.getSetCookie().join('\n')).toMatch(/tm_payment_flash=too_many_attempts/);

        api([() => json(500, {})]);
        await POST(retryPost(null));
        expect(calls).toHaveLength(0);
    });
});
