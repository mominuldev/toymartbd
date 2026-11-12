import { beforeEach, describe, expect, it, vi } from 'vitest';

// Wishlist hearts (storefront spec §A22, P4-STO-11): the Route Handler behind every heart. Product
// pages stay cached for everyone; the filled state comes from GET, which only calls the API for a
// signed-in customer.

const TOKEN = '12|abcdefghijklmnopqrstuvwxyz0123';

function stubApi(respond: (url: string, init: RequestInit) => Response) {
    const calls: Array<{ url: string; init: RequestInit }> = [];
    vi.stubGlobal('fetch', async (url: string, init: RequestInit) => {
        calls.push({ url, init });
        return respond(url, init);
    });
    return calls;
}

const json = (status: number, body: unknown, type = 'application/json') => new Response(status === 204 ? null : JSON.stringify(body), { status, headers: { 'Content-Type': type } });

function post(fields: Record<string, string>, opts: { cookie?: string; json?: boolean } = {}) {
    const headers: Record<string, string> = { 'Content-Type': 'application/x-www-form-urlencoded' };
    if (opts.cookie) headers.cookie = opts.cookie;
    if (opts.json) headers.Accept = 'application/json';
    return new Request('http://storefront.test/api/account/wishlist', { method: 'POST', headers, body: new URLSearchParams(fields) });
}

describe('/api/account/wishlist', () => {
    beforeEach(() => {
        vi.stubEnv('API_URL', 'http://api.test');
        vi.stubEnv('STOREFRONT_API_TOKEN', 'server-token');
    });

    it('GET gives a guest an empty list without calling the API, privately', async () => {
        const calls = stubApi(() => json(500, {}));
        const { GET } = await import('@/app/api/account/wishlist/route');
        const res = await GET(new Request('http://storefront.test/api/account/wishlist?locale=bn'));
        expect(await res.json()).toEqual({ productIds: [] });
        expect(res.headers.get('cache-control')).toBe('private, no-store');
        expect(calls).toHaveLength(0);
    });

    it('GET gives the signed-in customer their saved product ids', async () => {
        const calls = stubApi(() => json(200, { data: [{ product_id: 101, saved_at: null, available: true, product: null }, { product_id: 7, saved_at: null, available: false, product: null }] }));
        const { GET } = await import('@/app/api/account/wishlist/route');
        const res = await GET(new Request('http://storefront.test/api/account/wishlist?locale=en', { headers: { cookie: `tm_account=${TOKEN}` } }));
        expect(await res.json()).toEqual({ productIds: [101, 7] });
        expect(calls[0]?.url).toBe('http://api.test/api/v1/storefront/account/wishlist');
        expect((calls[0]?.init.headers as Record<string, string>).Authorization).toBe(`Bearer ${TOKEN}`);
    });

    it('POST (JS) tells a guest to sign in; without JS it 303s to sign-in and back to the heart’s page', async () => {
        stubApi(() => json(500, {}));
        const { POST } = await import('@/app/api/account/wishlist/route');
        const js = await POST(post({ product_id: '101', intent: 'add', locale: 'en', back: '/products/lego-classic' }, { json: true }));
        expect(js.status).toBe(401);
        expect(await js.json()).toEqual({ ok: false, signIn: true });

        const plain = await POST(post({ product_id: '101', intent: 'add', locale: 'bn', back: '/bn/products/lego-classic' }));
        expect(plain.status).toBe(303);
        expect(plain.headers.get('location')).toBe(`/bn/account/login?next=${encodeURIComponent('/products/lego-classic')}`);
    });

    it('POST refuses a back path to another site', async () => {
        stubApi(() => json(500, {}));
        const { POST } = await import('@/app/api/account/wishlist/route');
        for (const back of ['//evil.example/x', 'https://evil.example', '/\\evil']) {
            const res = await POST(post({ product_id: '101', intent: 'add', locale: 'en', back }));
            expect(res.headers.get('location')).toBe(`/account/login?next=${encodeURIComponent('/account/wishlist')}`);
        }
    });

    it('POST adds and removes for a signed-in customer (JSON for hearts)', async () => {
        const calls = stubApi((_url, init) => (init.method === 'DELETE' ? json(204, null) : json(201, { product_id: 101, saved_at: null, available: true, product: null })));
        const { POST } = await import('@/app/api/account/wishlist/route');
        const added = await POST(post({ product_id: '101', intent: 'add', locale: 'en' }, { cookie: `tm_account=${TOKEN}`, json: true }));
        expect(await added.json()).toEqual({ ok: true, saved: true });
        const removed = await POST(post({ product_id: '101', intent: 'remove', locale: 'en' }, { cookie: `tm_account=${TOKEN}`, json: true }));
        expect(await removed.json()).toEqual({ ok: true, saved: false });
        expect(calls.map((c) => `${c.init.method} ${c.url}`)).toEqual([
            'POST http://api.test/api/v1/storefront/account/wishlist',
            'DELETE http://api.test/api/v1/storefront/account/wishlist/101',
        ]);
        expect(JSON.parse(String(calls[0]?.init.body))).toEqual({ product_id: 101 });
    });
});
