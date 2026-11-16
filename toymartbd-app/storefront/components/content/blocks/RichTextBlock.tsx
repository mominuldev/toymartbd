import { cleanRichText, numberedSections } from '@/lib/content/page-toc';
import type { Locale } from '@/lib/i18n';

// Rich text (Phase 7). The API sanitised the HTML on save and again on read; the storefront filters
// it once more with the product-description allowlist before it reaches dangerouslySetInnerHTML —
// the same treatment and the same token classes as CMS pages.
//
// `numberFrom` (a static page with an "On this page" list, CmsSectionsPage): the policy look of
// Figma Privacy 166:9992 / 168:7799 — the text split at its h2s into numbered sections (a 40 pastel
// disc with the number beside an h2, 32 at 390), coral-dot bullets indented under the heading text
// at 1440, hairline rules between sections. The numbers continue across the page's sections.

const bodyClass = [
    'flex flex-col gap-5 t-body-lg text-fg-primary',
    '[&_h2]:t-h2 [&_h3]:t-h4',
    '[&_ul]:list-disc [&_ul]:ps-6 [&_ol]:list-decimal [&_ol]:ps-6 [&_li]:mt-1',
    '[&_blockquote]:border-s-4 [&_blockquote]:border-line-default [&_blockquote]:ps-4 [&_blockquote]:text-fg-secondary',
    '[&_a]:text-fg-link [&_a]:underline',
    '[&_img]:h-auto [&_img]:max-w-full [&_img]:rounded-lg',
].join(' ');

/** A numbered section's body: body-lg (body at 390), 14 between paragraphs and bullets, coral 7px dots. */
const sectionBodyClass = [
    'flex flex-col gap-3.5 t-body text-fg-primary lg:t-body-lg lg:ps-13.5',
    '[&_h3]:t-h4 [&_ul]:flex [&_ul]:flex-col [&_ul]:gap-3.5 [&_ol]:flex [&_ol]:flex-col [&_ol]:gap-3.5 [&_ol]:list-decimal [&_ol]:ps-6',
    '[&_ul>li]:relative [&_ul>li]:ps-4.5 [&_ul>li]:before:absolute [&_ul>li]:before:start-0 [&_ul>li]:before:top-2 [&_ul>li]:before:size-1.75 [&_ul>li]:before:rounded-full [&_ul>li]:before:bg-brand lg:[&_ul>li]:before:top-2.5',
    '[&_blockquote]:border-s-4 [&_blockquote]:border-line-default [&_blockquote]:ps-4 [&_blockquote]:text-fg-secondary',
    '[&_a]:text-fg-link [&_a]:underline',
    '[&_img]:h-auto [&_img]:max-w-full [&_img]:rounded-lg',
].join(' ');

/** Number discs cycle the pastel tiles as Figma draws them (01 pink, 02 sky, 03 mint, 04 lavender, 05 butter, 06 peach). */
const discs = ['bg-tile-pink', 'bg-tile-sky', 'bg-tile-mint', 'bg-tile-lavender', 'bg-tile-butter', 'bg-tile-peach'] as const;

export function RichTextBlock({ html, locale, numberFrom }: { html: string; locale: Locale; numberFrom?: number }) {
    const clean = cleanRichText(html);
    if (!clean) return null;
    const bn = locale === 'bn' ? 'lang-bn' : '';
    if (numberFrom === undefined) return <div className={`mx-auto max-w-prose ${bodyClass} ${bn}`} dangerouslySetInnerHTML={{ __html: clean }} />;

    const { intro, sections } = numberedSections(clean, numberFrom);
    return (
        <div className={`flex flex-col divide-y divide-line-default ${bn}`}>
            {intro ? <div className={`${bodyClass} pb-7 lg:pb-10`} dangerouslySetInnerHTML={{ __html: intro }} /> : null}
            {sections.map((section) => (
                <section key={section.id} id={section.id} aria-labelledby={`${section.id}-h`} className="flex scroll-mt-32 flex-col gap-3.5 py-7 first:pt-0 last:pb-0 lg:py-10">
                    <div className="flex items-center gap-3.5">
                        <span aria-hidden="true" className={`t-body-strong inline-flex size-8 shrink-0 items-center justify-center rounded-full text-fg-primary tabular-nums lg:size-10 ${discs[(section.number - 1) % discs.length]}`}>
                            {String(section.number).padStart(2, '0')}
                        </span>
                        <h2 id={`${section.id}-h`} className="t-h2 text-fg-primary">
                            {section.heading}
                        </h2>
                    </div>
                    {section.html ? <div className={sectionBodyClass} dangerouslySetInnerHTML={{ __html: section.html }} /> : null}
                </section>
            ))}
        </div>
    );
}
