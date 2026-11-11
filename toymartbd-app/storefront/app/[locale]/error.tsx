'use client';

import { useParams } from 'next/navigation';
import { useEffect } from 'react';

import { ServerErrorContent } from '@/components/status/ServerErrorContent';
import { isLocale } from '@/lib/i18n/config';

// Error boundary for every page under [locale] (P4-STO-12 "500"). It renders inside the root
// layout, so <html lang> and the fonts are already right; the page — including its chrome — is
// replaced, because the chrome may be what failed. Errors in the root layout itself fall through
// to app/global-error.tsx.
export default function ErrorPage({ error, reset }: { error: Error & { digest?: string }; reset: () => void }) {
    const params = useParams<{ locale?: string }>();
    const locale = params.locale && isLocale(params.locale) ? params.locale : 'en';

    useEffect(() => {
        // The digest links this report to the server log line; the message itself is redacted in production.
        console.error('[storefront] page error', error.digest ?? error.message);
    }, [error]);

    return <ServerErrorContent locale={locale} onRetry={reset} />;
}
