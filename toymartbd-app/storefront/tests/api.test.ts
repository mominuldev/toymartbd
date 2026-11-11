import { beforeEach, describe, expect, it, vi } from 'vitest';

import { ApiConfigError, ApiError, cached, noStore } from '@/lib/api';
import { brandName, brandNameIsFallback, getBrand, listBrands } from '@/lib/brands';
import type { Brand } from '@/lib/api-types';

const lego: Brand = {
    name: 'LEGO',
    name_bn: 'লেগো',
    slug: 'lego',
    description: 'Building bricks.',
    description_bn: 'বিল্ডিং ব্রিক।',
    logo_url: null,
};
const noBangla: Brand = { ...lego, name: 'Barbie', name_bn: null, slug: 'barbie', description_bn: null };

const fetchMock = vi.fn<typeof fetch>();

function json(body: unknown, status = 200, contentType = 'application/json') {
    return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': contentType } });
}

describe('lib/api', () => {
    beforeEach(() => {
        fetchMock.mockReset();
        vi.stubGlobal('fetch', fetchMock);
        vi.stubEnv('API_URL', 'https://api.test/');
        vi.stubEnv('STOREFRONT_API_TOKEN', 'tok_123');
    });

    it('cached() sends the bearer token and Accept-Language, and tags the fetch for ISR', async () => {
        fetchMock.mockResolvedValue(json({ data: [lego] }));
        await cached('storefront/brands', ['brands'], { locale: 'bn' });

        const [url, init] = fetchMock.mock.calls[0]!;
        expect(url).toBe('https://api.test/api/v1/storefront/brands');
        const headers = init!.headers as Record<string, string>;
        expect(headers.Authorization).toBe('Bearer tok_123');
        expect(headers['Accept-Language']).toBe('bn');
        expect((init as RequestInit & { next?: unknown }).next).toEqual({ tags: ['brands'], revalidate: 3600 });
        expect(init!.cache).toBeUndefined();
    });

    it('noStore() opts out of the cache', async () => {
        fetchMock.mockResolvedValue(json({ ok: true }));
        await noStore('storefront/cart');
        const init = fetchMock.mock.calls[0]![1] as RequestInit & { next?: unknown };
        expect(init.cache).toBe('no-store');
        expect(init.next).toBeUndefined();
    });

    it('parses application/problem+json into a typed ApiError', async () => {
        fetchMock.mockResolvedValue(
            json(
                { type: 'https://toymartbd.com/problems/not-found', title: 'Not Found', status: 404, detail: 'No brand "x".' },
                404,
                'application/problem+json',
            ),
        );
        const error = await cached('storefront/brands/x', ['brands']).catch((e: unknown) => e);
        expect(error).toBeInstanceOf(ApiError);
        expect((error as ApiError).status).toBe(404);
        expect((error as ApiError).isNotFound).toBe(true);
        expect((error as ApiError).problem.type).toBe('https://toymartbd.com/problems/not-found');
        expect((error as ApiError).problem.detail).toBe('No brand "x".');
    });

    it('still produces an ApiError for a non-JSON error body', async () => {
        fetchMock.mockResolvedValue(new Response('<h1>Bad gateway</h1>', { status: 502, statusText: 'Bad Gateway' }));
        const error = (await cached('storefront/brands', ['brands']).catch((e: unknown) => e)) as ApiError;
        expect(error).toBeInstanceOf(ApiError);
        expect(error.problem).toMatchObject({ type: 'about:blank', status: 502, title: 'Bad Gateway' });
    });

    it('fails loudly when server env is missing', async () => {
        vi.stubEnv('STOREFRONT_API_TOKEN', '');
        await expect(cached('storefront/brands', ['brands'])).rejects.toBeInstanceOf(ApiConfigError);
        expect(fetchMock).not.toHaveBeenCalled();
    });
});

describe('lib/brands', () => {
    beforeEach(() => {
        fetchMock.mockReset();
        vi.stubGlobal('fetch', fetchMock);
        vi.stubEnv('API_URL', 'https://api.test');
        vi.stubEnv('STOREFRONT_API_TOKEN', 'tok_123');
    });

    it('lists brands tagged `brands`', async () => {
        fetchMock.mockResolvedValue(json({ data: [lego, noBangla] }));
        expect(await listBrands('en')).toEqual([lego, noBangla]);
        expect((fetchMock.mock.calls[0]![1] as { next: { tags: string[] } }).next.tags).toEqual(['brands']);
    });

    it('fetches one brand tagged `brands` + `brand:{slug}`, bare or wrapped in data', async () => {
        fetchMock.mockResolvedValueOnce(json(lego)).mockResolvedValueOnce(json({ data: lego }));
        expect(await getBrand('lego', 'en')).toEqual(lego);
        expect(await getBrand('lego', 'bn')).toEqual(lego);
        expect((fetchMock.mock.calls[0]![1] as { next: { tags: string[] } }).next.tags).toEqual(['brands', 'brand:lego']);
        expect(fetchMock.mock.calls[0]![0]).toBe('https://api.test/api/v1/storefront/brands/lego');
    });

    it('maps a 404 to null and rethrows anything else', async () => {
        fetchMock.mockResolvedValueOnce(json({ type: 't', title: 'Not Found', status: 404 }, 404, 'application/problem+json'));
        expect(await getBrand('nope', 'en')).toBeNull();
        fetchMock.mockResolvedValueOnce(json({ type: 't', title: 'Server Error', status: 500 }, 500, 'application/problem+json'));
        await expect(getBrand('lego', 'en')).rejects.toBeInstanceOf(ApiError);
    });

    it('shows the Bangla name in bn and falls back to English when name_bn is null', () => {
        expect(brandName(lego, 'en')).toBe('LEGO');
        expect(brandName(lego, 'bn')).toBe('লেগো');
        expect(brandName(noBangla, 'bn')).toBe('Barbie');
        expect(brandNameIsFallback(noBangla, 'bn')).toBe(true);
        expect(brandNameIsFallback(lego, 'bn')).toBe(false);
    });
});
