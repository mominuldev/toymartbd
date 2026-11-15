import type { Metadata } from 'next';
import { notFound } from 'next/navigation';

import { ChatSection } from '@/components/ChatLinks';
import { CmsPageView } from '@/components/content/CmsPageView';
import { CmsSectionsPage } from '@/components/content/CmsSectionsPage';
import { PageShell } from '@/components/PageShell';
import { JsonLd } from '@/components/seo/JsonLd';
import { chatLinks } from '@/lib/chat-links';
import { getPage, listPages } from '@/lib/content/api';
import { cmsPageMetadata } from '@/lib/content/metadata';
import { usesSections } from '@/lib/content/page-blocks';
import { draftPageLayout, getPageLayout } from '@/lib/content/page-layout';
import { pageTemplate } from '@/lib/content/types';
import { getMessages, isLocale, type Locale } from '@/lib/i18n';
import { cmsPaths } from '@/lib/site-config';
import { getStoreInfo } from '@/lib/store-info';
import { getSeo } from '@/lib/seo/fetch';
import { faqPageJsonLd } from '@/lib/seo/jsonld';

// A CMS static page (Phase 7) at the site root — /delivery-policy, /privacy-policy, /faq… — GET
// /storefront/pages/{slug}, ISR under tags `content:pages` + `content:page:{slug}` (its SEO document: `seo` + `page:{slug}`), purged by
// Laravel on every save and when a scheduled publish time passes. One ASCII slug for both
// locales (rule 37). Unknown, draft, scheduled or archived → 404 (README "Known limitations":
// notFound() from a matched page renders Next's bare 404 shell without JavaScript).
// The About page keeps its own route (../about), which prefers the CMS `about` page. Every other
// first segment (cart, products, blog…) is a static route that wins over this one; Laravel refuses
// those as page slugs (CmsPage::RESERVED_SLUGS). The old /pages/{slug} URLs redirect here permanently (next.config.ts).
// Trade-off: an unknown one-segment URL (/no-such-page) now matches this route, so its 404 is the
// notFound()-from-a-page shell (README "Known limitations"), not the global-not-found page.
//
// Sections (owner decision 2026-11-16): the body comes from GET /storefront/content/pages/{slug}
// (lib/content/page-layout.ts; the page tags plus the data tags the sections read). A page published
// from the builder renders its sections with the homepage's block components (CmsSectionsPage); one
// never published from it (`version` null) keeps its legacy content / FAQ on CmsPageView. In draft
// mode (opened by /api/preview/page) the page's DRAFT sections render instead, uncached, even before
// the page is live. FAQPage JSON-LD: from the FAQ sections (HomeBlocks, once) or, on the legacy FAQ
// template, from the page's questions — never both.
//
// The contact page (`/contact`, cmsPaths.contact) also gets the WhatsApp and Messenger chat buttons
// (components/ChatLinks.tsx) under its content, each only when the owner has set it.

interface Props {
    params: Promise<{ locale: string; slug: string }>;
}

export async function generateMetadata({ params }: Props): Promise<Metadata> {
    const { locale, slug } = await params;
    if (!isLocale(locale)) return {};
    const draft = await draftPageLayout(slug, locale);
    if (draft) return { ...cmsPageMetadata(draft.page, locale, `/${draft.page.slug}`, null), robots: { index: false, follow: false } };
    const page = await getPage(slug).catch(() => null);
    if (!page) return {};
    return cmsPageMetadata(page, locale, `/${page.slug}`, await getSeo('page', page.slug));
}

export default async function CmsPageRoute({ params }: Props) {
    const { locale, slug } = await params;
    if (!isLocale(locale)) notFound();
    const layout = (await draftPageLayout(slug, locale)) ?? (await getPageLayout(slug, locale));
    if (!layout) notFound();
    const { page } = layout;
    const others = pageTemplate(page) === 'sidebar' ? await listPages() : [];
    const chat = `/${page.slug}` === cmsPaths.contact ? await contactChat(locale) : null;

    if (usesSections(layout)) {
        return (
            <PageShell locale={locale} path={`/${page.slug}`} top="tight" bleed>
                <CmsSectionsPage locale={locale} layout={layout} otherPages={others} />
                {chat ? <div className="mx-auto mt-8 w-full max-w-content px-gutter-mobile lg:px-gutter-desktop">{chat}</div> : null}
            </PageShell>
        );
    }

    return (
        <PageShell locale={locale} path={`/${page.slug}`} top="tight">
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
            {chat ? <div className="mt-8">{chat}</div> : null}
        </PageShell>
    );
}

async function contactChat(locale: Locale) {
    const { contact } = await getStoreInfo();
    if (chatLinks(contact).length === 0) return null;
    return <ChatSection id="contact-chat" locale={locale} title={getMessages(locale).chat.title} contact={contact} />;
}
