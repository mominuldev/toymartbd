'use client';

import { useParams } from 'next/navigation';
import { useEffect } from 'react';

import { ErrorShell } from '@/components/status/ErrorShell';
import { isLocale } from '@/lib/i18n/config';

// Error boundary for every page under [locale] (P4-STO-12 "500"). It renders inside the root
// layout, so <html lang> and the fonts are already right. The design (Figma 169:10286) keeps the
// header and footer around the 500 card; ErrorShell renders their static parts on the client
// (no mega-menu bar, which needs server data). Errors in the root layout itself fall through
// to app/global-error.tsx.
export default function ErrorPage({ error, reset }: { error: Error & { digest?: string }; reset: () => void }) {
    const params = useParams<{ locale?: string }>();
    const locale = params.locale && isLocale(params.locale) ? params.locale : 'en';

    useEffect(() => {
        // The digest links this report to the server log line; the message itself is redacted in production.
        console.error('[storefront] page error', error.digest ?? error.message);
    }, [error]);

    return <ErrorShell locale={locale} onRetry={reset} />;
}
