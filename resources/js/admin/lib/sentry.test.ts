import { afterEach, describe, expect, it } from 'vitest';
import { initSentry, readSentryMeta } from './sentry';

// P10: the admin's Sentry is configured by the server (a meta tag) and is a no-op without one.

function docWith(content: string | null): Document {
    const doc = document.implementation.createHTMLDocument('admin');
    if (content !== null) {
        const meta = doc.createElement('meta');
        meta.name = 'tm-sentry';
        meta.content = content;
        doc.head.append(meta);
    }
    return doc;
}

afterEach(() => {
    document.head.innerHTML = '';
});

describe('admin sentry', () => {
    it('does nothing when the server rendered no DSN', async () => {
        expect(readSentryMeta(docWith(null))).toBeNull();
        await expect(initSentry(docWith(null))).resolves.toBe(false);
    });

    it('ignores malformed or empty config', async () => {
        expect(readSentryMeta(docWith('not json'))).toBeNull();
        await expect(initSentry(docWith(JSON.stringify({ dsn: '' })))).resolves.toBe(false);
    });

    it('reads DSN, release and environment from the server', () => {
        const meta = readSentryMeta(docWith(JSON.stringify({ dsn: 'https://k@o1.ingest.example/7', release: 'abc1234', environment: 'staging', sampleRate: '1', tracesSampleRate: '0', extra: 5 })));
        expect(meta).toEqual({ dsn: 'https://k@o1.ingest.example/7', release: 'abc1234', environment: 'staging', sampleRate: '1', tracesSampleRate: '0' });
    });
});
