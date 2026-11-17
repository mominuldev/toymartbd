import { beforeEach, describe, expect, it, vi } from 'vitest';

import { getCartFor } from '@/lib/cart/server';

// Regression (2026-11-17): a saved delivery destination (tm_dest) the API no longer knows — a
// district re-imported under a new id — made GET /storefront/cart answer 422, so the cart page
// showed "That didn't work" with no items on every visit. The cart is now priced without the
// destination and the destination comes back null, so the page asks for the district again.

const TOKEN = '0b6f1a2c-3d4e-4f50-8a6b-7c8d9e0f1a2b';
const cart = { token: TOKEN, item_count: 1, lines: [] };
const problem = (status: number) =>
    new Response(JSON.stringify({ type: 'https://toymartbd.com/problems/validation-error', title: 'Validation failed', status, errors: [{ field: 'district_id', message: 'The selected district is invalid.' }] }), {
        status,
        headers: { 'Content-Type': 'application/problem+json' },
    });

describe('getCartFor', () => {
    beforeEach(() => {
        vi.stubEnv('API_URL', 'http://api.test');
        vi.stubEnv('STOREFRONT_API_TOKEN', 'server-token');
    });

    it('prices the cart without a destination the API refuses, and drops the destination', async () => {
        const urls: string[] = [];
        vi.stubGlobal('fetch', async (url: string) => {
            urls.push(url);
            return url.includes('district_id') ? problem(422) : new Response(JSON.stringify(cart), { status: 200, headers: { 'Content-Type': 'application/json' } });
        });
        const result = await getCartFor(TOKEN, { districtId: 999999, upazilaId: null }, 'en');
        expect(result.cart?.item_count).toBe(1);
        expect(result.dest).toBeNull();
        expect(urls).toEqual(['http://api.test/api/v1/storefront/cart?district_id=999999', 'http://api.test/api/v1/storefront/cart']);
    });

    it('keeps a destination the API accepts', async () => {
        vi.stubGlobal('fetch', async () => new Response(JSON.stringify(cart), { status: 200, headers: { 'Content-Type': 'application/json' } }));
        const result = await getCartFor(TOKEN, { districtId: 47, upazilaId: null }, 'en');
        expect(result.dest).toEqual({ districtId: 47, upazilaId: null });
    });

    it('still reports a missing cart as no cart, and other failures as failures', async () => {
        vi.stubGlobal('fetch', async () => new Response(JSON.stringify({ type: 'x', title: 'Not found', status: 404 }), { status: 404, headers: { 'Content-Type': 'application/problem+json' } }));
        expect((await getCartFor(TOKEN, null, 'en')).cart).toBeNull();
        vi.stubGlobal('fetch', async () => problem(422));
        await expect(getCartFor(TOKEN, null, 'en')).rejects.toThrow();
    });
});
