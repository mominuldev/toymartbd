import { describe, expect, it, vi } from 'vitest';

import config from '../next.config';
import { assertProductionEnv, productionEnvProblems } from '../lib/env';

const complete = {
    API_URL: 'https://api.toymartbd.test',
    STOREFRONT_API_TOKEN: '7|fake-storefront-token-0123456789abcdef',
    STOREFRONT_REVALIDATE_SECRET: 'fake-revalidate-secret-0123456789',
    SITE_URL: 'https://toymartbd.test',
    ADMIN_URL: 'https://admin.toymartbd.test',
};

describe('storefront production env validation', () => {
    it('accepts a complete environment', () => {
        expect(productionEnvProblems(complete)).toEqual([]);
    });

    it('names every missing variable', () => {
        expect(productionEnvProblems({})).toEqual([
            'API_URL is not set',
            'STOREFRONT_API_TOKEN is not set',
            'STOREFRONT_REVALIDATE_SECRET is not set',
            'SITE_URL is not set',
            'ADMIN_URL is not set',
        ]);
    });

    it('rejects malformed URLs and placeholder secrets', () => {
        const problems = productionEnvProblems({ ...complete, API_URL: 'localhost:8000', SITE_URL: 'ftp://x', STOREFRONT_API_TOKEN: 'changeme', STOREFRONT_REVALIDATE_SECRET: '   ' });
        expect(problems).toEqual([
            'API_URL is not an absolute http(s) URL',
            'STOREFRONT_API_TOKEN looks like a placeholder (shorter than 16 characters)',
            'STOREFRONT_REVALIDATE_SECRET is not set',
            'SITE_URL is not an absolute http(s) URL',
        ]);
    });

    it('refuses a NEXT_PUBLIC_ copy of a server secret, by name or by value', () => {
        expect(productionEnvProblems({ ...complete, NEXT_PUBLIC_STOREFRONT_API_TOKEN: 'x' })[0]).toContain('NEXT_PUBLIC_STOREFRONT_API_TOKEN must not exist');
        expect(productionEnvProblems({ ...complete, NEXT_PUBLIC_DEBUG: complete.STOREFRONT_REVALIDATE_SECRET })).toEqual(['NEXT_PUBLIC_DEBUG holds a server secret\'s value and would ship it to the browser']);
        expect(productionEnvProblems({ ...complete, NEXT_PUBLIC_SITE_NAME: 'Toy Mart BD' })).toEqual([]);
    });

    it('never puts a value in the error message', () => {
        const env = { ...complete, STOREFRONT_API_TOKEN: 'short-tok' };
        expect(() => assertProductionEnv(env)).toThrow(/STOREFRONT_API_TOKEN looks like a placeholder/);
        try {
            assertProductionEnv(env);
        } catch (e) {
            expect(String(e)).not.toContain('short-tok');
        }
    });

    it('runs for next build and next start, not for next dev', () => {
        for (const name of Object.keys(complete)) vi.stubEnv(name, '');
        expect(() => config('phase-production-build')).toThrow(/Storefront production environment is incomplete/);
        expect(() => config('phase-production-server')).toThrow(/API_URL is not set/);
        expect(() => config('phase-development-server')).not.toThrow();
        for (const [name, value] of Object.entries(complete)) vi.stubEnv(name, value);
        expect(() => config('phase-production-build')).not.toThrow();
    });
});
