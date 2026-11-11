import { expect, test } from '@playwright/test';
import { createHmac } from 'node:crypto';

import { FAKE_REVALIDATE_SECRET, MOCK_API_URL } from './constants';

// P3-STO-03 end to end: pages are cached (ISR), and a signed POST /api/revalidate drops exactly
// the named tags.
test.describe.configure({ mode: 'serial' });

function signed(body: string, secret = FAKE_REVALIDATE_SECRET) {
    const ts = String(Math.floor(Date.now() / 1000));
    const sig = createHmac('sha256', secret).update(`${ts}.${body}`).digest('hex');
    return {
        data: body,
        headers: { 'Content-Type': 'application/json', 'X-Revalidate-Timestamp': ts, 'X-Revalidate-Signature': `sha256=${sig}` },
    };
}

const hits = async (request: import('@playwright/test').APIRequestContext) =>
    (await (await request.get(`${MOCK_API_URL}/__control/hits`)).json()) as Record<string, number>;

test.beforeAll(async ({ request }) => {
    await request.post(`${MOCK_API_URL}/__control/reset`);
});

test.afterAll(async ({ request }) => {
    await request.post(`${MOCK_API_URL}/__control/reset`);
    await request.post('/api/revalidate', signed('{"tags":["brands"]}'));
});

test('a cached page is served without calling the API, and revalidation refreshes it', async ({ request }) => {
    // Warm the cache.
    expect(await (await request.get('/brands')).text()).toContain('LEGO');
    const before = (await hits(request))['/api/v1/storefront/brands'] ?? 0;

    // The API changes; the cached page does not, and the API is not called again.
    await request.post(`${MOCK_API_URL}/__control/rename`, { data: { slug: 'lego', name: 'LEGO Renamed' } });
    const cachedHtml = await (await request.get('/brands')).text();
    expect(cachedHtml).not.toContain('LEGO Renamed');
    expect((await hits(request))['/api/v1/storefront/brands'] ?? 0).toBe(before);

    // An unsigned request changes nothing.
    const unsigned = await request.post('/api/revalidate', { data: '{"tags":["brands"]}', headers: { 'Content-Type': 'application/json' } });
    expect(unsigned.status()).toBe(401);
    expect(await (await request.get('/brands')).text()).not.toContain('LEGO Renamed');

    // A wrongly signed request changes nothing.
    const forged = await request.post('/api/revalidate', signed('{"tags":["brands"]}', 'not-the-secret'));
    expect(forged.status()).toBe(401);
    expect(await (await request.get('/brands')).text()).not.toContain('LEGO Renamed');

    // A signed request for the tag refreshes it.
    const ok = await request.post('/api/revalidate', signed('{"tags":["brands"]}'));
    expect(ok.status()).toBe(200);
    expect(await ok.json()).toEqual({ revalidated: ['brands'] });
    expect(await (await request.get('/brands')).text()).toContain('LEGO Renamed');
    expect(await (await request.get('/bn/brands')).text()).toContain('লেগো'); // Bangla name unchanged
});

test('revalidating brand:{slug} refreshes only that brand', async ({ request }) => {
    await request.post(`${MOCK_API_URL}/__control/reset`);
    await request.post('/api/revalidate', signed('{"tags":["brands"]}'));
    expect(await (await request.get('/brands/hot-wheels')).text()).toContain('Hot Wheels');
    expect(await (await request.get('/brands/barbie')).text()).toContain('Barbie');

    await request.post(`${MOCK_API_URL}/__control/rename`, { data: { slug: 'hot-wheels', name: 'Hot Wheels Updated' } });
    await request.post(`${MOCK_API_URL}/__control/rename`, { data: { slug: 'barbie', name: 'Barbie Updated' } });

    const ok = await request.post('/api/revalidate', signed('{"tags":["brand:hot-wheels"]}'));
    expect(ok.status()).toBe(200);
    expect(await (await request.get('/brands/hot-wheels')).text()).toContain('Hot Wheels Updated');
    expect(await (await request.get('/brands/barbie')).text()).not.toContain('Barbie Updated');
});
