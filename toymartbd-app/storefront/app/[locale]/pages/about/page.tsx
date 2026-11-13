import type { Metadata } from 'next';
import { notFound } from 'next/navigation';

import { CmsPageView } from '@/components/content/CmsPageView';
import { PageShell } from '@/components/PageShell';
import { StaticPage } from '@/components/StaticPage';
import { findPage } from '@/lib/content/api';
import { localised } from '@/lib/content/types';
import { isLocale } from '@/lib/i18n';
import { getSeo } from '@/lib/seo/fetch';
import { contentMetadata, staticMetadata } from '@/lib/seo/metadata';
import { staticPage } from '@/lib/static-pages';

// The About page. A fixed route (it wins over ../[slug]) so the designed About layout (Figma About
// us 170:10502) keeps working: when the CMS has a live `about` page, that page renders on the CMS
// template; otherwise the built-in About copy (lib/static-pages.ts) does — so the link never 404s
// before the owner publishes the page. Tags: content:pages + content:page:about.

const PATH = '/pages/about';

interface Props {
    params: Promise<{ locale: string }>;
}

export async function generateMetadata({ params }: Props): Promise<Metadata> {
    const { locale } = await params;
    if (!isLocale(locale)) return {};
    const page = await findPage('about');
    if (page) {
        // SEO: agent 4
        return contentMetadata({
            locale,
            path: PATH,
            title: (locale === 'bn' ? page.seo.title_bn : page.seo.title) || localised(locale, page.title, page.title_bn).text,
            description: (locale === 'bn' ? page.seo.description_bn : page.seo.description) || null,
            seo: await getSeo('page', 'about'),
            kind: 'page',
        });
    }
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
