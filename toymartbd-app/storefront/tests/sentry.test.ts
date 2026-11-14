import { describe, expect, it } from 'vitest';

import { scrubBreadcrumb, scrubEvent } from '@toymart/observability';

import { sentryConfigured, sentryOptions, sentryRelease } from '@/lib/sentry';
import { sentryUploadEnabled, withMonitoring } from '@/lib/sentry-build';

// P10: storefront Sentry is DSN-gated, tagged with the git SHA, and scrubs PII with the API's rules.

describe('sentry options', () => {
    it('is a no-op without a DSN, in every runtime', () => {
        for (const runtime of ['server', 'edge', 'client'] as const) {
            expect(sentryOptions(runtime, {}).enabled).toBe(false);
            expect(sentryOptions(runtime, {}).dsn).toBeUndefined();
        }
        expect(sentryConfigured({})).toBe(false);
    });

    it('uses the server DSN on the server and only the public DSN in the browser', () => {
        const env = { SENTRY_DSN: 'https://k@o1.ingest.example/1', NEXT_PUBLIC_SENTRY_DSN: 'https://p@o1.ingest.example/2' };
        expect(sentryOptions('server', env).dsn).toBe('https://k@o1.ingest.example/1');
        expect(sentryOptions('client', env).dsn).toBe('https://p@o1.ingest.example/2');
        expect(sentryOptions('client', { SENTRY_DSN: 'https://k@o1.ingest.example/1' }).enabled).toBe(false);
    });

    it('tags the release with the git SHA and never sends default PII', () => {
        expect(sentryRelease({ SENTRY_RELEASE: 'abc1234' })).toBe('abc1234');
        expect(sentryRelease({ VERCEL_GIT_COMMIT_SHA: 'def5678' })).toBe('def5678');
        const options = sentryOptions('server', { SENTRY_DSN: 'https://k@o1.ingest.example/1', VERCEL_GIT_COMMIT_SHA: 'def5678', VERCEL_ENV: 'preview' });
        expect(options).toMatchObject({ enabled: true, release: 'def5678', environment: 'preview', sendDefaultPii: false, sampleRate: 1, tracesSampleRate: 0 });
        expect(options.beforeSend).toBe(scrubEvent);
        expect(options.beforeBreadcrumb).toBe(scrubBreadcrumb);
    });

    it('rejects out-of-range sample rates', () => {
        const options = sentryOptions('server', { SENTRY_DSN: 'https://k@o1.ingest.example/1', SENTRY_SAMPLE_RATE: '7', SENTRY_TRACES_SAMPLE_RATE: '0.2' });
        expect(options.sampleRate).toBe(1);
        expect(options.tracesSampleRate).toBe(0.2);
    });
});

describe('sentry build wrapping', () => {
    it('leaves the Next config untouched without a DSN', () => {
        const config = { reactStrictMode: true };
        expect(withMonitoring(config, {})).toBe(config);
    });

    it('uploads source maps only with a token, org and project', () => {
        expect(sentryUploadEnabled({ SENTRY_AUTH_TOKEN: 't', SENTRY_ORG: 'o' })).toBe(false);
        expect(sentryUploadEnabled({ SENTRY_AUTH_TOKEN: 't', SENTRY_ORG: 'o', SENTRY_STOREFRONT_PROJECT: 'p' })).toBe(true);
    });
});

describe('PII scrubbing (rule 20)', () => {
    const event = {
        message: 'Checkout failed for 01712345678 / rahela@example.com',
        exception: { values: [{ type: 'Error', value: 'POST /cart token=abc123secret failed for +8801812345678', stacktrace: { frames: [{ vars: { otp: '483920', phone: '01912345678' } }] } }] },
        request: {
            url: 'https://toymartbd.test/checkout?email=a@b.co',
            headers: { Authorization: 'Bearer sk_live_123', 'X-Cart-Token': 'c0ffee', Accept: 'text/html' },
            cookies: { tm_cart: 'c0ffee' },
            data: { shipping_address: 'House 12, Road 5', address_line: 'Dhanmondi', name: 'Rahela', phone: '01712345678' },
        },
        extra: { body: '{"password":"hunter2","order":"TMB-1"}' },
        breadcrumbs: [{ message: 'fetch 01712345678', data: { url: '/api?otp=111222' } }],
        user: { id: 42, email: 'rahela@example.com', ip_address: '203.0.113.9', username: 'rahela' },
        tags: { order: 'TMB-1' },
    };

    it('masks phones and e-mails, redacts secrets, addresses and OTPs, drops cookies and user PII', () => {
        const out = scrubEvent(event);
        const json = JSON.stringify(out);

        for (const leaked of ['1712345678', '1812345678', '1912345678', 'rahela@', 'a@b.co', 'hunter2', 'sk_live_123', 'abc123secret', 'c0ffee', 'House 12', 'Dhanmondi', '483920', '111222', '203.0.113.9']) {
            expect(json).not.toContain(leaked);
        }
        expect(out.message).toBe('Checkout failed for 017****5678 / r***@example.com');
        expect(out.request).not.toHaveProperty('cookies');
        expect(out.request.headers).toMatchObject({ Authorization: '[redacted]', 'X-Cart-Token': '[redacted]', Accept: 'text/html' });
        expect(out.user).toEqual({ id: 42 });
        expect(out.tags).toEqual({ order: 'TMB-1' });
        expect(out.extra.body).toBe('{"password":"[redacted]","order":"TMB-1"}');
    });

    it('does not mutate the original event', () => {
        scrubEvent(event);
        expect(event.message).toContain('01712345678');
    });
});
