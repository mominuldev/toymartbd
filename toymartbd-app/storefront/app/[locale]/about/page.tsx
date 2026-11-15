import type { Metadata } from 'next';
import { notFound } from 'next/navigation';

import { CmsPageView } from '@/components/content/CmsPageView';
import { CmsSectionsPage } from '@/components/content/CmsSectionsPage';
import { PageShell } from '@/components/PageShell';
import { StaticPage } from '@/components/StaticPage';
import { findPage } from '@/lib/content/api';
import { cmsPageMetadata } from '@/lib/content/metadata';
import { usesSections } from '@/lib/content/page-blocks';
import { draftPageLayout, findPageLayout } from '@/lib/content/page-layout';
import { isLocale } from '@/lib/i18n';
import { getSeo } from '@/lib/seo/fetch';
import { staticMetadata } from '@/lib/seo/metadata';
import { staticPage } from '@/lib/static-pages';

// The About page. A fixed route (it wins over ../[slug]) so the designed About layout (Figma About
// us 170:10502) keeps working: when the CMS has a live `about` page, that page renders on the CMS
// template; otherwise the built-in About copy (lib/static-pages.ts) does — so the link never 404s
// before the owner publishes the page. Tags: content:pages + content:page:about (body) and
// seo + page:about (SEO document) — a page save or a page SEO save purges all four.
// Sections (owner decision 2026-11-16): like every CMS page (../[slug]), a live `about` page
// published from the builder renders its sections (CmsSectionsPage, tags: the page's plus the data
// the sections read); one never published from it keeps the CMS template; a draft preview of the
// `about` page renders its draft sections.

const PATH = '/about';

interface Props {
    params: Promise<{ locale: string }>;
}

export async function generateMetadata({ params }: Props): Promise<Metadata> {
    const { locale } = await params;
    if (!isLocale(locale)) return {};
    const draft = await draftPageLayout('about', locale);
    if (draft) return { ...cmsPageMetadata(draft.page, locale, PATH, null), robots: { index: false, follow: false } };
    const page = await findPage('about');
    // Both branches go through the lib/seo builder: canonical, hreflang en/bn/x-default, OG.
    if (page) return cmsPageMetadata(page, locale, PATH, await getSeo('page', 'about'));
    const content = staticPage('about', locale);
    return staticMetadata(locale, PATH, content.title, content.metaDescription);
}

export default async function AboutPage({ params }: Props) {
    const { locale } = await params;
    if (!isLocale(locale)) notFound();
    const layout = (await draftPageLayout('about', locale)) ?? (await findPageLayout('about', locale));
    if (layout && usesSections(layout)) {
        return (
            <PageShell locale={locale} path={PATH} top="tight" bleed>
                <CmsSectionsPage locale={locale} layout={layout} />
            </PageShell>
        );
    }
    return (
        <PageShell locale={locale} path={PATH} top="tight">
            {layout ? <CmsPageView locale={locale} page={layout.page} /> : <StaticPage locale={locale} content={staticPage('about', locale)} />}
        </PageShell>
    );
}
