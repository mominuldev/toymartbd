import { RichTextBlock } from '@/components/content/blocks/RichTextBlock';
import { Icon } from '@/components/icons/Icon';
import { postDate } from '@/lib/blog/format';
import type { TocEntry } from '@/lib/content/page-toc';
import { format, getMessages, localizedPath, type Locale } from '@/lib/i18n';
import { localised, pageBody, pageTemplate, withHeadingIds, type CmsPage, type CmsPageSummary } from '@/lib/content/types';
import { descriptionContext, sanitizeDescription } from '@/lib/product/description';

interface Props {
    locale: Locale;
    page: CmsPage;
    /** Other live pages, for the sidebar template's "more" list. */
    otherPages?: CmsPageSummary[];
}

/** Rich text in tokens — the same treatment as the product description (ProductInfoPanel). */
const bodyClass = [
    'flex max-w-prose flex-col gap-5 t-body-lg text-fg-primary',
    '[&_h2]:t-h2 [&_h2]:scroll-mt-32 [&_h3]:t-h4',
    '[&_ul]:list-disc [&_ul]:ps-6 [&_ol]:list-decimal [&_ol]:ps-6 [&_li]:mt-1',
    '[&_blockquote]:border-s-4 [&_blockquote]:border-line-default [&_blockquote]:ps-4 [&_blockquote]:text-fg-secondary',
    '[&_a]:text-fg-link [&_a]:underline',
    '[&_img]:h-auto [&_img]:max-w-full [&_img]:rounded-lg',
].join(' ');

/**
 * Defence in depth: the API sanitised the HTML on save and again on read; the storefront filters
 * it once more with the product-description allowlist (lib/product/description.ts) before it
 * reaches dangerouslySetInnerHTML.
 */
function clean(html: string | null): string | null {
    return sanitizeDescription(html, descriptionContext(process.env.API_URL, []));
}

// A CMS static page (storefront spec §B8): the Bloom-sky header of the static-page template
// (components/StaticPage.tsx), then the body in one of three templates — default, sidebar (an
// in-page contents list plus links to the other store pages) or FAQ (native <details>, so the
// accordion works without JavaScript). A Bangla page without a Bangla body shows the English one,
// marked lang="en" with a notice.
export function CmsPageView({ locale, page, otherPages = [] }: Props) {
    const t = getMessages(locale);
    const template = pageTemplate(page);
    const body = pageBody(page, locale);
    const sanitised = clean(body.html);
    const { html, headings } = sanitised ? withHeadingIds(sanitised) : { html: null, headings: [] };
    const bnClass = (lang?: 'en') => (locale === 'bn' && !lang ? 'lang-bn' : '');

    const content = html ? <div lang={body.lang} className={`${bodyClass} ${bnClass(body.lang)}`} dangerouslySetInnerHTML={{ __html: html }} /> : null;

    const faq =
        template === 'faq' ? (
            <section aria-labelledby="faq-title" className="flex max-w-prose flex-col gap-3">
                <h2 id="faq-title" className="t-h2 text-fg-primary">
                    {t.content.faqTitle}
                </h2>
                <div className="flex flex-col divide-y divide-line-default rounded-lg bg-surface shadow-e1">
                    {page.faq_items.map((item, i) => {
                        const question = localised(locale, item.question, item.question_bn);
                        const answerLang = locale === 'bn' && !item.answer_bn ? 'en' : undefined;
                        const answer = clean(locale === 'bn' ? (item.answer_bn ?? item.answer) : item.answer);
                        return (
                            <details key={i} className="group px-5 py-4">
                                <summary lang={question.lang} className={`t-body-strong flex cursor-pointer list-none items-center justify-between gap-3 text-fg-primary [&::-webkit-details-marker]:hidden ${bnClass(question.lang)}`}>
                                    {question.text}
                                    <span aria-hidden="true" className="t-h4 text-fg-brand transition-transform group-open:rotate-45">
                                        +
                                    </span>
                                </summary>
                                {answer ? (
                                    <div lang={answerLang} className={`mt-3 ${bodyClass} t-body ${bnClass(answerLang)}`} dangerouslySetInnerHTML={{ __html: answer }} />
                                ) : null}
                            </details>
                        );
                    })}
                </div>
            </section>
        ) : null;

    const more = otherPages.filter((p) => p.slug !== page.slug);
    const toc: TocEntry[] = headings.map((h, i) => ({ id: h.id, label: h.text, number: i + 1, count: null }));

    return (
        <article className="flex flex-col gap-8 lg:gap-10">
            <CmsPageHeader locale={locale} page={page} englishOnly={body.lang === 'en'} updated={template === 'sidebar'} />

            {template === 'sidebar' ? (
                <div className="flex flex-col gap-8 lg:flex-row lg:items-start lg:gap-12">
                    {/* Figma Privacy 166:9992: a sticky "On this page" card (300) beside the numbered sections. */}
                    <aside className="flex flex-col gap-4 lg:sticky lg:top-36 lg:w-75 lg:shrink-0">
                        <PageContents locale={locale} entries={toc} />
                        <MorePagesNav locale={locale} pages={more} />
                    </aside>
                    <div lang={body.lang} className="min-w-0 grow">
                        {sanitised ? <RichTextBlock html={sanitised} locale={body.lang ?? locale} numberFrom={1} /> : null}
                    </div>
                </div>
            ) : (
                <>
                    {content}
                    {faq}
                </>
            )}
        </article>
    );
}

// Page-header tones (storefront spec §B8): Privacy = Bloom-mint, Returns = Bloom-candy, About =
// Bloom-sunrise; Contact, Terms, FAQ and every other page = Bloom-sky. Keyed by the seeded slugs
// (StaticPagesSeeder) — the page document carries no tone of its own yet.
const tones: Record<string, string> = {
    'privacy-policy': 'bg-(image:--tm-gradient-bloom-mint)',
    'returns-policy': 'bg-(image:--tm-gradient-bloom-candy)',
    about: 'bg-(image:--tm-gradient-bloom-sunrise)',
};
const defaultTone = 'bg-(image:--tm-gradient-bloom-sky)';

/**
 * The page header (Figma Privacy 166:10226, FAQ 171:10981): the page's Bloom gradient, radius
 * xl-plus (lg-plus at 390), 72 × 48 inset at 1440; an optional white pill (the policy pages' "last
 * updated" date, from the page's own `updated_at`), then the title as the page's only H1. Shared by
 * the legacy templates above and pages built from sections (CmsSectionsPage).
 */
export function CmsPageHeader({
    locale,
    page,
    englishOnly = false,
    updated = false,
}: {
    locale: Locale;
    page: Pick<CmsPage, 'title' | 'title_bn'> & Partial<Pick<CmsPage, 'slug' | 'updated_at'>>;
    englishOnly?: boolean;
    /** Show "Last updated {date}" (pages with an "On this page" list: the policy look). */
    updated?: boolean;
}) {
    const t = getMessages(locale);
    const title = localised(locale, page.title, page.title_bn);
    const date = updated ? postDate(page.updated_at, locale) : '';
    return (
        <header className={`flex flex-col items-start gap-3 rounded-lg-plus p-6 lg:gap-4 lg:rounded-xl-plus lg:px-18 lg:py-12 ${(page.slug && tones[page.slug]) || defaultTone}`}>
            {date ? <p className={`t-overline rounded-full bg-surface px-3.5 py-1.5 text-fg-brand ${locale === 'bn' ? 'lang-bn' : ''}`}>{format(t.content.updated, { date })}</p> : null}
            <h1 lang={title.lang} className={`t-display-2 text-fg-primary lg:t-display-1 ${locale === 'bn' && !title.lang ? 'lang-bn' : ''}`}>
                {title.text}
            </h1>
            {englishOnly ? <p className="t-body-sm text-fg-secondary">{t.content.englishOnly}</p> : null}
        </header>
    );
}

/**
 * "On this page" (Figma 166:10232 / FAQ topics 171:10994): a surface card, hairline border,
 * radius lg; an overline label, then one 46-tall row per entry — the number (body-sm, secondary)
 * or, for an FAQ group, its question count at the end. At 390 the same list folds into a native
 * <details> "Jump to a section" control (Figma 168:7799 draws a select; a disclosure works without
 * JavaScript). Nothing renders for fewer than two entries.
 */
export function PageContents({ locale, entries }: { locale: Locale; entries: TocEntry[] }) {
    if (entries.length < 2) return null;
    const t = getMessages(locale);
    const list = (
        <ol className="flex flex-col gap-1">
            {entries.map((entry) => (
                <li key={entry.id}>
                    <a href={`#${entry.id}`} className="t-body flex min-h-touch-min items-center gap-2.5 rounded-sm-plus px-3 py-2.5 text-fg-primary hover:bg-brand-subtle hover:text-fg-brand">
                        {entry.number !== null ? <span className="t-body-sm shrink-0 text-fg-secondary tabular-nums">{String(entry.number).padStart(2, '0')}</span> : null}
                        <span className="min-w-0 grow">{entry.label}</span>
                        {entry.count !== null ? <span className="t-body-sm shrink-0 text-fg-secondary tabular-nums">{entry.count}</span> : null}
                    </a>
                </li>
            ))}
        </ol>
    );
    return (
        <nav aria-label={t.content.onThisPage} className={locale === 'bn' ? 'lang-bn' : ''}>
            <details className="group rounded-md border border-line-default bg-surface lg:hidden">
                <summary className="t-body flex min-h-control-md cursor-pointer list-none items-center justify-between gap-3 px-4 text-fg-primary [&::-webkit-details-marker]:hidden">
                    {t.content.jumpTo}
                    <Icon name="caretDown" className="size-icon-sm shrink-0 transition-transform group-open:rotate-180" />
                </summary>
                <div className="border-t border-line-default p-2">{list}</div>
            </details>
            <div className="hidden flex-col gap-1 rounded-lg border border-line-default bg-surface px-4 py-6 lg:flex">
                <p className="t-overline px-3 pb-2 text-fg-secondary">{t.content.onThisPage}</p>
                {list}
            </div>
        </nav>
    );
}

/** The sidebar template's links to the other live store pages (nothing when there are none). */
export function MorePagesNav({ locale, pages, className = '' }: { locale: Locale; pages: CmsPageSummary[]; className?: string }) {
    if (pages.length === 0) return null;
    const t = getMessages(locale);
    return (
        <nav aria-label={t.content.morePages} className={`flex flex-col gap-1 rounded-lg border border-line-default bg-surface px-4 py-6 ${className}`}>
            <p className="t-overline px-3 pb-2 text-fg-secondary">{t.content.morePages}</p>
            <ul className="flex flex-col">
                {pages.map((p) => {
                    const label = localised(locale, p.title, p.title_bn);
                    return (
                        <li key={p.slug}>
                            <a href={localizedPath(locale, `/${p.slug}`)} lang={label.lang} className="t-body-sm flex min-h-touch-min items-center rounded-sm px-3 text-fg-link underline-offset-4 hover:underline">
                                {label.text}
                            </a>
                        </li>
                    );
                })}
            </ul>
        </nav>
    );
}
