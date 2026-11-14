// Server + edge error reporting (P10). A no-op unless SENTRY_DSN / NEXT_PUBLIC_SENTRY_DSN is set:
// the SDK is imported only then. See lib/sentry.ts.
import { sentryOptions } from './lib/sentry';

export async function register(): Promise<void> {
    const runtime = process.env.NEXT_RUNTIME === 'edge' ? 'edge' : 'server';
    const options = sentryOptions(runtime, process.env);
    if (!options.enabled) return;

    const Sentry = await import('@sentry/nextjs');
    Sentry.init(options);
}

/** Errors from Server Components, Route Handlers and the proxy (Next's onRequestError hook). */
export async function onRequestError(...args: Parameters<typeof import('@sentry/nextjs').captureRequestError>): Promise<void> {
    if (!sentryOptions(process.env.NEXT_RUNTIME === 'edge' ? 'edge' : 'server', process.env).enabled) return;
    const Sentry = await import('@sentry/nextjs');
    Sentry.captureRequestError(...args);
}
