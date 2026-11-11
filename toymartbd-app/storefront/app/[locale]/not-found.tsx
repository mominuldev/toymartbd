import { locale as rootLocale } from 'next/root-params';

import { PageShell } from '@/components/PageShell';
import { getMessages, isLocale, localizedPath } from '@/lib/i18n';

export default async function NotFound() {
    const value = await rootLocale();
    const locale = value && isLocale(value) ? value : 'en';
    const t = getMessages(locale);
    return (
        <PageShell locale={locale} path="/">
            <h1 className="t-h1 text-fg-primary">{t.notFound.title}</h1>
            <p className="t-body mt-4 text-fg-secondary">{t.notFound.body}</p>
            <a
                href={localizedPath(locale, '/')}
                className="t-label mt-6 inline-flex min-h-touch-min items-center text-fg-link underline underline-offset-4"
            >
                {t.notFound.back}
            </a>
        </PageShell>
    );
}
