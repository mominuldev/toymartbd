import { locale as rootLocale } from 'next/root-params';

import { PageShell } from '@/components/PageShell';
import { NotFoundContent } from '@/components/status/NotFoundContent';
import { getMessages, isLocale } from '@/lib/i18n';

// Shown when a page under [locale] calls notFound() (an unknown brand or category slug).
// Unmatched URLs never get here: app/global-not-found.tsx renders those, server-side.
// Known limitation (README): Next 16.4.3 serves a page-level notFound() as HTTP 404 + noindex
// with an empty HTML shell, and this UI is painted by the client.

export async function generateMetadata() {
    const value = await rootLocale();
    const locale = value && isLocale(value) ? value : 'en';
    return { title: getMessages(locale).notFound.title };
}

export default async function NotFound() {
    const value = await rootLocale();
    const locale = value && isLocale(value) ? value : 'en';
    return (
        <PageShell locale={locale} path="/" top="tight">
            <NotFoundContent locale={locale} />
        </PageShell>
    );
}
