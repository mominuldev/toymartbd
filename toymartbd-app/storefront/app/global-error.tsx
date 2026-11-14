'use client';

import { useParams } from 'next/navigation';

import { ErrorShell } from '@/components/status/ErrorShell';
import { isLocale } from '@/lib/i18n/config';
import { isMaintenanceDigest } from '@/lib/maintenance';
import { MaintenanceNotice } from './[locale]/maintenance/MaintenanceNotice';
import './globals.css';

// Last-resort boundary for an error in the root layout (app/[locale]/layout.tsx) itself. It
// replaces the whole document, so it renders <html> itself. An API `maintenance` 503 in the layout
// gets the maintenance look (Gate 5, see app/[locale]/error.tsx).
export default function GlobalError({ error, reset }: { error: Error & { digest?: string }; reset: () => void }) {
    const params = useParams<{ locale?: string }>();
    const locale = params?.locale && isLocale(params.locale) ? params.locale : 'en';
    return (
        <html lang={locale} data-theme="light">
            <body className={`flex min-h-dvh flex-col antialiased${locale === 'bn' ? ' lang-bn' : ''}`}>
                {isMaintenanceDigest(error.digest) ? <MaintenanceNotice locale={locale} onRetry={() => window.location.reload()} /> : <ErrorShell locale={locale} onRetry={reset} />}
            </body>
        </html>
    );
}
