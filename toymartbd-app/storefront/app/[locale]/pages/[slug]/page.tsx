import type { Metadata } from 'next';
import { notFound } from 'next/navigation';

import { CmsPageView } from '@/components/content/CmsPageView';
import { PageShell } from '@/components/PageShell';
import { JsonLd } from '@/components/seo/JsonLd';
import { getPage, listPages } from '@/lib/content/api';
import { localised, pageTemplate, type CmsPage } from '@/lib/content/types';
import { isLocale, type Locale } from '@/lib/i18n';
import { getSeo } from '@/lib/seo/fetch';
import { faqPageJsonLd } from '@/lib/seo/jsonld';
import { contentMetadata } from '@/lib/seo/metadata';

// A CMS static page (Phase 7): Delivery policy, Returns, Privacy, Terms, FAQ, Contact… — GET
// /storefront/pages/{slug}, ISR under tags `content:pages` + `content:page:{slug}`, purged by
// Laravel on every save and when a scheduled publish time passes. One ASCII slug for both
// locales (rule 37). Unknown, draft, scheduled or archived → 404 (README "Known limitations":
// notFound() from a matched page renders Next's bare 404 shell without JavaScript).
// The About page keeps its own route (../about), which prefers the CMS `about` page.

interface Props {
    params: Promise<{ locale: string; slug: string }>;
}

function seoDefaults(page: CmsPage, locale: Locale): { title: string; description: string } {
    return locale === 'bn' ? { title: page.seo.title_bn, description: page.seo.description_bn } : { title: page.seo.title, description: page.seo.description };
}

export async function generateMetadata({ params }: Props): Promise<Metadata> {
    const { locale, slug } = await params;
    if (!isLocale(locale)) return {};
    const page = await getPage(slug).catch(() => null);
    if (!page) return {};
    const defaults = seoDefaults(page, locale);
    // SEO: agent 4 — the SEO document (when the seo endpoint serves `page`) overlays these defaults.
    return contentMetadata({
        locale,
        path: `/pages/${page.slug}`,
        title: defaults.title || localised(locale, page.title, page.title_bn).text,
        description: defaults.description || null,
        image: page.seo.og_image ? { url: page.seo.og_image } : null,
        seo: await getSeo('page', page.slug),
        kind: 'page',
        modifiedTime: page.updated_at,
    });
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
