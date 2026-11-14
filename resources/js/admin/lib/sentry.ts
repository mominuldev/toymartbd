import { baseSentryOptions } from '@toymart/observability';

/**
 * Sentry for the admin SPA (P10, SYSTEM-ARCHITECTURE §11). The server decides: when
 * SENTRY_ADMIN_DSN is set, resources/views/admin.blade.php renders
 * `<meta name="tm-sentry" content="{dsn, release, environment, …}">` and the admin CSP allows
 * the DSN's origin. Without that tag nothing loads — the SDK lives in its own chunk, fetched
 * only when a DSN exists, so a DSN-less build pays nothing.
 *
 * Release = the git SHA the deploy sets as SENTRY_RELEASE (the same release the API reports and
 * the source maps were uploaded under, see vite.config.ts). PII is scrubbed by
 * @toymart/observability with the API's rules (rule 20); sendDefaultPii is always false.
 */

interface SentryMeta {
    dsn?: string;
    release?: string;
    environment?: string;
    sampleRate?: string;
    tracesSampleRate?: string;
}

type SentryModule = typeof import('@sentry/react');

let sentry: SentryModule | null = null;

export function readSentryMeta(doc: Document = document): SentryMeta | null {
    const raw = doc.querySelector<HTMLMetaElement>('meta[name="tm-sentry"]')?.content;
    if (!raw) return null;
    try {
        const parsed: unknown = JSON.parse(raw);
        if (parsed === null || typeof parsed !== 'object') return null;
        const read = (key: keyof SentryMeta): string | undefined => {
            const value: unknown = (parsed as Record<string, unknown>)[key];
            return typeof value === 'string' ? value : undefined;
        };
        return {
            dsn: read('dsn'),
            release: read('release'),
            environment: read('environment'),
            sampleRate: read('sampleRate'),
            tracesSampleRate: read('tracesSampleRate'),
        };
    } catch {
        return null;
    }
}

/** Starts Sentry when the server configured a DSN; resolves to false (no-op) otherwise. */
export async function initSentry(doc: Document = document): Promise<boolean> {
    const meta = readSentryMeta(doc);
    const options = baseSentryOptions(meta ?? {});
    if (!options.enabled) return false;

    sentry = await import('@sentry/react');
    sentry.init({ ...options, integrations: [sentry.browserTracingIntegration()] });
    return true;
}

/**
 * React 19 root error hooks (createRoot options). Replacing them replaces React's own
 * console logging, so this still logs; it forwards to Sentry only once Sentry is running.
 */
export function reportReactError(error: unknown): void {
    console.error(error);
    sentry?.captureException(error);
}
