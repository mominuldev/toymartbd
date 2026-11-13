import { getMessages, localizedPath, type Locale } from '@/lib/i18n';
import { localised, pageBody, pageTemplate, splitSections, withHeadingIds, type CmsPage, type CmsPageSummary } from '@/lib/content/types';
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
    const title = localised(locale, page.title, page.title_bn);
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
    const sections = sanitised ? splitSections(sanitised) : { intro: '', sections: [] };

    return (
        <article className="flex flex-col gap-8">
            <header className="flex flex-col gap-3 rounded-lg-plus bg-(image:--tm-gradient-bloom-sky) p-6 lg:rounded-xl-plus lg:p-10">
                <h1 lang={title.lang} className={`t-display-2 text-fg-primary lg:t-display-1 ${bnClass(title.lang)}`}>
                    {title.text}
                </h1>
                {body.lang === 'en' ? <p className="t-body-sm text-fg-secondary">{t.content.englishOnly}</p> : null}
            </header>

            {template === 'sidebar' ? (
                <div className="flex flex-col gap-8 lg:flex-row lg:items-start lg:gap-8">
                    {/* Figma Privacy 166:9992: a sticky "On this page" card (300) beside numbered section cards. */}
                    <aside className="flex flex-col gap-4 lg:sticky lg:top-36 lg:w-75 lg:shrink-0">
                        {headings.length > 0 ? (
                            <nav aria-label={t.content.onThisPage} className="flex flex-col gap-1 rounded-lg bg-surface p-3 shadow-e1">
                                <p className="t-overline px-3 py-2 text-fg-tertiary">{t.content.onThisPage}</p>
                                <ol className="flex flex-col">
                                    {headings.map((h, i) => (
                                        <li key={h.id}>
                                            <a href={`#${h.id}`} className="t-body-sm flex min-h-touch-min items-center gap-3 rounded-sm px-3 text-fg-secondary hover:bg-brand-subtle hover:text-fg-primary">
                                                <span className="t-caption font-semibold text-fg-brand tabular-nums">{String(i + 1).padStart(2, '0')}</span>
                                                {h.text}
                                            </a>
                                        </li>
                                    ))}
                                </ol>
                            </nav>
                        ) : null}
                        {more.length > 0 ? (
                            <nav aria-label={t.content.morePages} className="flex flex-col gap-1 rounded-lg bg-surface p-3 shadow-e1">
                                <p className="t-overline px-3 py-2 text-fg-tertiary">{t.content.morePages}</p>
                                <ul className="flex flex-col">
                                    {more.map((p) => {
                                        const label = localised(locale, p.title, p.title_bn);
                                        return (
                                            <li key={p.slug}>
                                                <a href={localizedPath(locale, `/pages/${p.slug}`)} lang={label.lang} className="t-body-sm flex min-h-touch-min items-center rounded-sm px-3 text-fg-link underline-offset-4 hover:underline">
                                                    {label.text}
                                                </a>
                                            </li>
                                        );
                                    })}
                                </ul>
                            </nav>
                        ) : null}
                    </aside>
                    <div lang={body.lang} className={`flex min-w-0 grow flex-col gap-4 ${bnClass(body.lang)}`}>
                        {sections.intro ? <div className={bodyClass} dangerouslySetInnerHTML={{ __html: sections.intro }} /> : null}
                        {sections.sections.map((section, i) => (
                            <section key={section.id} id={section.id} aria-labelledby={`${section.id}-h`} className="flex scroll-mt-32 flex-col gap-3 rounded-lg bg-surface p-5 shadow-e1 lg:p-7">
                                <h2 id={`${section.id}-h`} className="t-h3 flex items-center gap-3 text-fg-primary">
                                    <span aria-hidden="true" className="t-caption inline-flex size-8 shrink-0 items-center justify-center rounded-sm bg-brand-subtle font-semibold text-fg-brand tabular-nums">
                                        {String(i + 1).padStart(2, '0')}
                                    </span>
                                    {section.heading}
                                </h2>
                                {section.html ? <div className={bodyClass} dangerouslySetInnerHTML={{ __html: section.html }} /> : null}
                            </section>
                        ))}
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
