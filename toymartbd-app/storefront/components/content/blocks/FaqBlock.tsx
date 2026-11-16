import { bnClass } from '@/components/content/blocks/shared';
import { Icon } from '@/components/icons/Icon';
import { JsonLd } from '@/components/seo/JsonLd';
import type { Locale } from '@/lib/i18n';
import { faqPageJsonLd } from '@/lib/seo/jsonld';

// FAQ (Phase 7): native <details> so the accordion works without JavaScript, plus FAQPage JSON-LD
// from the shared SEO builders (lib/seo/jsonld), in the page's language. Answers are plain text.
// `jsonLd={false}` when the page emits one FAQPage for all its FAQ blocks (HomeBlocks), so the
// structured data never appears twice.
// Look: Figma FAQ 171:10747 — an h2 group title over Accordion/Item Boxed rows (surface, hairline
// border, radius lg, 12 apart; the whole header row is the control, min 56, 16 inset; title in the
// accordion type, bold when open; 20px caret; the answer in body, text/primary). `fill` takes the
// column width (beside an "On this page" list); on its own the group keeps the reading measure.

export function FaqBlock({ id, title, items, locale, jsonLd = true, fill = false }: { id: string; title: string; items: Array<{ question: string; answer: string }>; locale: Locale; jsonLd?: boolean; fill?: boolean }) {
    const headingId = `cms-${id}-title`;
    const bn = locale === 'bn';
    return (
        <section aria-labelledby={headingId} className={`flex w-full scroll-mt-32 flex-col gap-3 ${fill ? '' : 'mx-auto max-w-prose'} ${bnClass(locale)}`}>
            {jsonLd ? <JsonLd data={[faqPageJsonLd(items)]} /> : null}
            <h2 id={headingId} className="t-h2 scroll-mt-32 text-fg-primary">
                {title}
            </h2>
            {items.map((item, i) => (
                <details key={i} className="group rounded-lg border border-line-default bg-surface">
                    <summary
                        className={`flex min-h-14 cursor-pointer list-none items-center justify-between gap-3 rounded-lg p-4 text-fg-primary [&::-webkit-details-marker]:hidden ${bn ? 't-body-strong' : 't-accordion-title group-open:t-accordion-title-expanded'}`}
                    >
                        {item.question}
                        <Icon name="caretDown" className="size-icon-md shrink-0 transition-transform group-open:rotate-180" />
                    </summary>
                    <p className="t-body whitespace-pre-line px-4 pb-4 text-fg-primary">{item.answer}</p>
                </details>
            ))}
        </section>
    );
}
