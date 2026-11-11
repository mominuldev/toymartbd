import type { Metadata } from 'next';

import { PageShell } from '@/components/PageShell';
import { getMessages, isLocale, localizedPath, type Locale } from '@/lib/i18n';
import { alternatesFor } from '@/lib/seo';

interface Props {
    params: Promise<{ locale: string }>;
}

async function localeOf(params: Props['params']): Promise<Locale> {
    const { locale } = await params;
    return isLocale(locale) ? locale : 'en';
}

export async function generateMetadata({ params }: Props): Promise<Metadata> {
    const locale = await localeOf(params);
    return { alternates: alternatesFor(locale, '/') };
}

export default async function HomePage({ params }: Props) {
    const locale = await localeOf(params);
    const t = getMessages(locale);
    return (
        <PageShell locale={locale} path="/" announcement mobileSearch>
            <h1 className="t-display-1 text-fg-primary">{t.home.title}</h1>
            <p className="t-body-lg mt-4 max-w-prose text-fg-secondary">{t.home.intro}</p>
            <a
                href={localizedPath(locale, '/brands')}
                className="t-button mt-8 inline-flex min-h-touch-min items-center rounded-full bg-brand px-6 text-fg-on-brand shadow-e1"
            >
                {t.home.browseBrands}
            </a>
        </PageShell>
    );
}
