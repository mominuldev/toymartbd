import type { Metadata } from 'next';
import { notFound } from 'next/navigation';

import { CmsPageView } from '@/components/content/CmsPageView';
import { PageShell } from '@/components/PageShell';
import { StaticPage } from '@/components/StaticPage';
import { findPage } from '@/lib/content/api';
import { cmsPageMetadata } from '@/lib/content/metadata';
import { isLocale } from '@/lib/i18n';
import { getSeo } from '@/lib/seo/fetch';
import { staticMetadata } from '@/lib/seo/metadata';
import { staticPage } from '@/lib/static-pages';

// The About page. A fixed route (it wins over ../[slug]) so the designed About layout (Figma About
// us 170:10502) keeps working: when the CMS has a live `about` page, that page renders on the CMS
// template; otherwise the built-in About copy (lib/static-pages.ts) does — so the link never 404s
// before the owner publishes the page. Tags: content:pages + content:page:about (body) and
// seo + page:about (SEO document) — a page save or a page SEO save purges all four.

const PATH = '/pages/about';

interface Props {
    params: Promise<{ locale: string }>;
}

export async function generateMetadata({ params }: Props): Promise<Metadata> {
    const { locale } = await params;
    if (!isLocale(locale)) return {};
    const page = await findPage('about');
    // Both branches go through the lib/seo builder: canonical, hreflang en/bn/x-default, OG.
    if (page) return cmsPageMetadata(page, locale, PATH, await getSeo('page', 'about'));
    const content = staticPage('about', locale);
    return staticMetadata(locale, PATH, content.title, content.metaDescription);
}

export default async function AboutPage({ params }: Props) {
    const { locale } = await params;
    if (!isLocale(locale)) notFound();
    const page = await findPage('about');
    return (
        <PageShell locale={locale} path={PATH} top="tight">
            {page ? <CmsPageView locale={locale} page={page} /> : <StaticPage locale={locale} content={staticPage('about', locale)} />}
        </PageShell>
    );
}
