// Browser error reporting (P10). A no-op unless NEXT_PUBLIC_SENTRY_DSN was set at build: the
// variables below are inlined by Next, and the SDK chunk is fetched only when there is a DSN.
// Events go through the same-origin tunnel (lib/sentry-build.ts). See lib/sentry.ts.
import { sentryOptions } from './lib/sentry';

const options = sentryOptions('client', {
    NEXT_PUBLIC_SENTRY_DSN: process.env.NEXT_PUBLIC_SENTRY_DSN,
    NEXT_PUBLIC_SENTRY_RELEASE: process.env.NEXT_PUBLIC_SENTRY_RELEASE,
    NEXT_PUBLIC_SENTRY_ENVIRONMENT: process.env.NEXT_PUBLIC_SENTRY_ENVIRONMENT,
    NEXT_PUBLIC_SENTRY_SAMPLE_RATE: process.env.NEXT_PUBLIC_SENTRY_SAMPLE_RATE,
    NEXT_PUBLIC_SENTRY_TRACES_SAMPLE_RATE: process.env.NEXT_PUBLIC_SENTRY_TRACES_SAMPLE_RATE,
});

if (options.enabled) {
    void import('@sentry/nextjs').then((Sentry) => Sentry.init(options));
}
