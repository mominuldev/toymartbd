// Build-time Sentry for the storefront (P10): wraps next.config ONLY when a DSN is configured,
// so a DSN-less build is byte-for-byte the build it was before. Source maps are uploaded (and
// then deleted from the output, never served) only when SENTRY_AUTH_TOKEN, SENTRY_ORG and
// SENTRY_STOREFRONT_PROJECT exist; otherwise no upload and no network call. Telemetry is off.
// The browser reaches Sentry through the same-origin tunnel `/api/monitoring` (outside
// proxy.ts's matcher, inside the CSP's connect-src 'self'), so no CSP change is needed.
import type { NextConfig } from 'next';
import { withSentryConfig } from '@sentry/nextjs/config';

import { sentryConfigured, sentryRelease } from './sentry';

export const SENTRY_TUNNEL_ROUTE = '/api/monitoring';

export function sentryUploadEnabled(env: Record<string, string | undefined>): boolean {
    return Boolean(env.SENTRY_AUTH_TOKEN && env.SENTRY_ORG && env.SENTRY_STOREFRONT_PROJECT);
}

export function withMonitoring(config: NextConfig, env: Record<string, string | undefined> = process.env): NextConfig {
    if (!sentryConfigured(env)) return config;

    const upload = sentryUploadEnabled(env);
    return withSentryConfig(config, {
        org: env.SENTRY_ORG,
        project: env.SENTRY_STOREFRONT_PROJECT,
        authToken: env.SENTRY_AUTH_TOKEN,
        sentryUrl: env.SENTRY_URL,
        telemetry: false,
        silent: true,
        tunnelRoute: SENTRY_TUNNEL_ROUTE,
        release: { name: sentryRelease(env), create: upload, finalize: upload },
        sourcemaps: { disable: !upload, deleteSourcemapsAfterUpload: true },
    });
}
