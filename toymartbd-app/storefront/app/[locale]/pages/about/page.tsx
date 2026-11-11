import type { Metadata } from 'next';
import { notFound } from 'next/navigation';

import { PageShell } from '@/components/PageShell';
import { StaticPage } from '@/components/StaticPage';
import { isLocale } from '@/lib/i18n';
import { alternatesFor } from '@/lib/seo';
import { staticPage } from '@/lib/static-pages';

// The first static page on the template. A fixed route rather than /pages/[slug] on purpose:
// footer links to pages that do not exist yet (/pages/privacy …) stay UNMATCHED URLs, which
// app/global-not-found.tsx renders server-side as the styled 404. A dynamic [slug] route would
// have to call notFound() for them, and Next 16.4.3 serves that as an empty HTML shell without
// JavaScript (README "Known limitations"). Switch to [slug] when the CMS supplies the pages.

const PATH = '/pages/about';

interface Props {
    params: Promise<{ locale: string }>;
}

export async function generateMetadata({ params }: Props): Promise<Metadata> {
    const { locale } = await params;
    if (!isLocale(locale)) return {};
    const content = staticPage('about', locale);
    return { title: content.title, description: content.metaDescription, alternates: alternatesFor(locale, PATH) };
}

export default async function AboutPage({ params }: Props) {
    const { locale } = await params;
    if (!isLocale(locale)) notFound();
    return (
        <PageShell locale={locale} path={PATH}>
            <StaticPage locale={locale} content={staticPage('about', locale)} />
        </PageShell>
    );
}
