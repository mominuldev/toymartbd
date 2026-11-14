import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { getTitleSeparator } from '@/lib/seo/fetch';
import { DEFAULT_TITLE_SEPARATOR } from '@/lib/seo/metadata';

import {
    apiMaintenance,
    clearApiMaintenance,
    isMaintenanceDigest,
    isMaintenanceProblem,
    maintenanceDigest,
    maintenanceOn,
    MAINTENANCE_RETRY_AFTER_SECONDS,
    noteApiMaintenance,
    observeApiResponse,
    observingFetch,
    retryAfterSeconds,
    SIGNAL_MAX_SECONDS,
} from '../lib/maintenance';

vi.mock('next/headers', () => ({
    headers: async () => {
        throw new Error('no request scope');
    },
}));

const { ApiError, cached, noStore, send, toApiError } = await import('../lib/api');

const MAINTENANCE = 'https://toymartbd.com/problems/maintenance';

function problem(status: number, type: string, retryAfter?: string): Response {
    return new Response(JSON.stringify({ type, title: 'x', status }), {
        status,
        headers: { 'Content-Type': 'application/problem+json', ...(retryAfter ? { 'Retry-After': retryAfter } : {}) },
    });
}

describe('storefront maintenance switch', () => {
    it('is on only for the exact value "1"', () => {
        expect(maintenanceOn({ STOREFRONT_MAINTENANCE: '1' })).toBe(true);
        expect(maintenanceOn({ STOREFRONT_MAINTENANCE: ' 1 ' })).toBe(true);
        for (const value of [undefined, '', '0', 'false', 'true', 'yes']) {
            expect(maintenanceOn({ STOREFRONT_MAINTENANCE: value })).toBe(false);
        }
    });
});

describe('API maintenance detection', () => {
    it('is a 503 of type `maintenance` only — never a feature-level 503 or another status', () => {
        expect(isMaintenanceProblem(503, MAINTENANCE)).toBe(true);
        expect(isMaintenanceProblem(503, 'maintenance')).toBe(true);
        expect(isMaintenanceProblem(503, 'https://toymartbd.com/problems/service-unavailable')).toBe(false); // no courier rate card
        expect(isMaintenanceProblem(500, MAINTENANCE)).toBe(false);
        expect(isMaintenanceProblem(503, 'about:blank')).toBe(false);
        expect(isMaintenanceProblem(503, null)).toBe(false);
    });

    it('reads Retry-After as seconds or an HTTP-date, clamped, with a fallback', () => {
        const now = Date.parse('2026-11-14T10:00:00Z');
        expect(retryAfterSeconds('120', now)).toBe(120);
        expect(retryAfterSeconds('Sat, 14 Nov 2026 10:05:00 GMT', now)).toBe(300);
        expect(retryAfterSeconds('Sat, 14 Nov 2026 09:00:00 GMT', now)).toBe(1); // already past
        expect(retryAfterSeconds('99999999', now)).toBe(86_400);
        for (const bad of [null, undefined, '', 'soon', '-5']) expect(retryAfterSeconds(bad, now)).toBe(MAINTENANCE_RETRY_AFTER_SECONDS);
    });

    it('marks the digest an error boundary looks for', () => {
        expect(isMaintenanceDigest(maintenanceDigest(60))).toBe(true);
        expect(isMaintenanceDigest('123456789')).toBe(false);
        expect(isMaintenanceDigest(undefined)).toBe(false);
    });
});

describe('the downtime signal proxy.ts reads', () => {
    beforeEach(() => clearApiMaintenance());

    it('lasts the Retry-After, bounded to a short window, then lets requests through', () => {
        const now = 1_000_000;
        noteApiMaintenance(30, now);
        expect(apiMaintenance(now + 29_000)).toEqual({ retryAfter: 30 });
        expect(apiMaintenance(now + 30_000)).toBeNull();

        noteApiMaintenance(3600, now); // a long estimate still re-checks within the window
        expect(apiMaintenance(now + SIGNAL_MAX_SECONDS * 1000 - 1)).toEqual({ retryAfter: 3600 });
        expect(apiMaintenance(now + SIGNAL_MAX_SECONDS * 1000)).toBeNull();
    });

    it('is armed by a maintenance answer, cleared by a fresh 2xx, untouched by other errors', async () => {
        await observeApiResponse(problem(503, MAINTENANCE, '45'));
        expect(apiMaintenance()).toEqual({ retryAfter: 45 });

        await observeApiResponse(problem(503, 'https://toymartbd.com/problems/service-unavailable'));
        await observeApiResponse(problem(500, 'https://toymartbd.com/problems/server-error'));
        await observeApiResponse(new Response('Bad gateway', { status: 503 }));
        expect(apiMaintenance()).toEqual({ retryAfter: 45 });

        await observeApiResponse(new Response('{}', { status: 200 }), { fresh: false }); // Next's data cache, not the API
        expect(apiMaintenance()).toEqual({ retryAfter: 45 });

        await observeApiResponse(new Response('{}', { status: 200 }));
        expect(apiMaintenance()).toBeNull();
    });

    it('observingFetch() watches the answer and leaves its body for the caller', async () => {
        const inner = vi.fn<typeof fetch>().mockResolvedValue(problem(503, MAINTENANCE, '20'));
        const response = await observingFetch(inner)('https://api.test/api/v1/storefront/redirects');
        expect(apiMaintenance()).toEqual({ retryAfter: 20 });
        expect(((await response.json()) as { type: string }).type).toBe(MAINTENANCE);
    });
});

describe('lib/api in maintenance', () => {
    const fetchMock = vi.fn<typeof fetch>();

    beforeEach(() => {
        clearApiMaintenance();
        fetchMock.mockReset();
        vi.stubGlobal('fetch', fetchMock);
        vi.stubEnv('API_URL', 'https://api.test');
        vi.stubEnv('STOREFRONT_API_TOKEN', 'tok_123');
    });

    afterEach(() => clearApiMaintenance());

    it('throws an ApiError that knows it is maintenance, with the API Retry-After and the boundary digest', async () => {
        const error = await toApiError(problem(503, MAINTENANCE, '90'));
        expect(error.isMaintenance).toBe(true);
        expect(error.retryAfter).toBe(90);
        expect(isMaintenanceDigest(error.digest)).toBe(true);

        const other = await toApiError(problem(503, 'https://toymartbd.com/problems/service-unavailable', '90'));
        expect(other.isMaintenance).toBe(false);
        expect(other.retryAfter).toBeNull();
        expect(other.digest).toBeUndefined(); // Next hashes its own: the ordinary 500 page
    });

    it.each([
        ['cached', () => cached('storefront/brands', ['brands'])],
        ['noStore', () => noStore('storefront/cart')],
        ['send', () => send('POST', 'storefront/cart/items', { body: { variant_id: 1, quantity: 1 } })],
    ] as const)('%s() arms the signal on a maintenance answer', async (_name, call) => {
        fetchMock.mockResolvedValue(problem(503, MAINTENANCE, '40'));
        const error = await call().catch((e: unknown) => e);
        expect(error).toBeInstanceOf(ApiError);
        expect((error as InstanceType<typeof ApiError>).isMaintenance).toBe(true);
        expect(apiMaintenance()).toEqual({ retryAfter: 40 });
    });

    it('a fresh answer clears the signal; a cached() one cannot (it may come from Next’s data cache)', async () => {
        noteApiMaintenance(40);
        fetchMock.mockImplementation(async () => new Response('{"data":[]}', { status: 200, headers: { 'Content-Type': 'application/json' } }));
        await cached('storefront/brands', ['brands']);
        expect(apiMaintenance()).not.toBeNull();
        await noStore('storefront/cart');
        expect(apiMaintenance()).toBeNull();
    });

    it('the maintenance page renders without asking the API for the title separator while it is down', async () => {
        noteApiMaintenance(40);
        expect(await getTitleSeparator()).toBe(DEFAULT_TITLE_SEPARATOR);
        expect(fetchMock).not.toHaveBeenCalled();
    });
});
