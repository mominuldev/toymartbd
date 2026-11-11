'use client';

import { useParams } from 'next/navigation';

import { ErrorShell } from '@/components/status/ErrorShell';
import { isLocale } from '@/lib/i18n/config';
import './globals.css';

// Last-resort boundary for an error in the root layout (app/[locale]/layout.tsx) itself. It
// replaces the whole document, so it renders <html> itself.
export default function GlobalError({ reset }: { error: Error & { digest?: string }; reset: () => void }) {
    const params = useParams<{ locale?: string }>();
    const locale = params?.locale && isLocale(params.locale) ? params.locale : 'en';
    return (
        <html lang={locale} data-theme="light">
            <body className={`flex min-h-dvh flex-col antialiased${locale === 'bn' ? ' lang-bn' : ''}`}>
                <ErrorShell locale={locale} onRetry={reset} />
            </body>
        </html>
    );
}
