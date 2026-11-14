// Sentry options for the storefront (P10, SYSTEM-ARCHITECTURE §11) — server, edge and browser.
//
// Off unless a DSN is set: SENTRY_DSN (server/edge, falls back to the public one) and
// NEXT_PUBLIC_SENTRY_DSN (browser). A DSN is a public ingest key, not a credential — it ships
// to every browser by design — so NEXT_PUBLIC_ is acceptable for it (rule 28 covers API
// credentials; the Sentry AUTH TOKEN is build-only and never NEXT_PUBLIC_).
//
// Release = the git SHA: SENTRY_RELEASE, else Vercel's VERCEL_GIT_COMMIT_SHA — the same name the
// build uploads source maps under (lib/sentry-build.ts). PII scrubbing is @toymart/observability,
// the API's rules (rule 20): phones, e-mails, addresses, tokens, OTPs; sendDefaultPii is false.
import { baseSentryOptions, type BaseSentryOptions } from '@toymart/observability';

export type SentryRuntime = 'server' | 'edge' | 'client';

type Env = Record<string, string | undefined>;

/** The git SHA this build/deploy is, or undefined. */
export function sentryRelease(env: Env): string | undefined {
    const value = env.SENTRY_RELEASE?.trim() || env.VERCEL_GIT_COMMIT_SHA?.trim() || env.NEXT_PUBLIC_SENTRY_RELEASE?.trim();
    return value || undefined;
}

/** Options for Sentry.init() in one runtime. `enabled` is false — a no-op — without a DSN. */
export function sentryOptions(runtime: SentryRuntime, env: Env): BaseSentryOptions {
    const dsn = runtime === 'client' ? env.NEXT_PUBLIC_SENTRY_DSN : env.SENTRY_DSN || env.NEXT_PUBLIC_SENTRY_DSN;
    return baseSentryOptions({
        dsn,
        release: sentryRelease(env),
        environment: env.SENTRY_ENVIRONMENT || env.NEXT_PUBLIC_SENTRY_ENVIRONMENT || env.VERCEL_ENV,
        sampleRate: runtime === 'client' ? env.NEXT_PUBLIC_SENTRY_SAMPLE_RATE : env.SENTRY_SAMPLE_RATE,
        tracesSampleRate: runtime === 'client' ? env.NEXT_PUBLIC_SENTRY_TRACES_SAMPLE_RATE : env.SENTRY_TRACES_SAMPLE_RATE,
    });
}

/** True when any runtime would report — the build only wraps the config then. */
export function sentryConfigured(env: Env): boolean {
    return Boolean(env.SENTRY_DSN?.trim() || env.NEXT_PUBLIC_SENTRY_DSN?.trim());
}
