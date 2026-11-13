import type { Metadata } from 'next';
import { notFound } from 'next/navigation';

import { CmsPageView } from '@/components/content/CmsPageView';
import { PageShell } from '@/components/PageShell';
import { JsonLd } from '@/components/seo/JsonLd';
import { getPage, listPages } from '@/lib/content/api';
import { cmsPageMetadata } from '@/lib/content/metadata';
import { pageTemplate } from '@/lib/content/types';
import { isLocale } from '@/lib/i18n';
import { getSeo } from '@/lib/seo/fetch';
import { faqPageJsonLd } from '@/lib/seo/jsonld';

// A CMS static page (Phase 7): Delivery policy, Returns, Privacy, Terms, FAQ, Contact… — GET
// /storefront/pages/{slug}, ISR under tags `content:pages` + `content:page:{slug}` (its SEO document: `seo` + `page:{slug}`), purged by
// Laravel on every save and when a scheduled publish time passes. One ASCII slug for both
// locales (rule 37). Unknown, draft, scheduled or archived → 404 (README "Known limitations":
// notFound() from a matched page renders Next's bare 404 shell without JavaScript).
// The About page keeps its own route (../about), which prefers the CMS `about` page.

interface Props {
    params: Promise<{ locale: string; slug: string }>;
}

export async function generateMetadata({ params }: Props): Promise<Metadata> {
    const { locale, slug } = await params;
    if (!isLocale(locale)) return {};
    const page = await getPage(slug).catch(() => null);
    if (!page) return {};
    return cmsPageMetadata(page, locale, `/pages/${page.slug}`, await getSeo('page', page.slug));
}

export default async function CmsPageRoute({ params }: Props) {
    const { locale, slug } = await params;
    if (!isLocale(locale)) notFound();
    const page = await getPage(slug);
    if (!page) notFound();
    const others = pageTemplate(page) === 'sidebar' ? await listPages() : [];

    return (
        <PageShell locale={locale} path={`/pages/${page.slug}`} top="tight">
            {pageTemplate(page) === 'faq' ? (
                <JsonLd
                    data={faqPageJsonLd(
                        page.faq_items.map((item) => ({
                            question: locale === 'bn' ? (item.question_bn ?? item.question) : item.question,
                            answer: (locale === 'bn' ? (item.answer_bn ?? item.answer) : item.answer).replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim(),
                        })),
                    )}
                />
            ) : null}
            <CmsPageView locale={locale} page={page} otherPages={others} />
        </PageShell>
    );
}
