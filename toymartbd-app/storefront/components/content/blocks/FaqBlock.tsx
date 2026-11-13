import { bnClass } from '@/components/content/blocks/shared';
import { Icon } from '@/components/icons/Icon';
import { JsonLd } from '@/components/seo/JsonLd';
import type { Locale } from '@/lib/i18n';
import { faqPageJsonLd } from '@/lib/seo/jsonld';

// FAQ (Phase 7): native <details> so the accordion works without JavaScript — the CMS page FAQ
// template's look — plus FAQPage JSON-LD from the shared SEO builders (lib/seo/jsonld), in the
// page's language. Answers are plain text.

export function FaqBlock({ id, title, items, locale }: { id: string; title: string; items: Array<{ question: string; answer: string }>; locale: Locale }) {
    const headingId = `cms-${id}-title`;
    return (
        <section aria-labelledby={headingId} className={`mx-auto flex w-full max-w-prose flex-col gap-3 ${bnClass(locale)}`}>
            <JsonLd data={[faqPageJsonLd(items)]} />
            <h2 id={headingId} className="t-h2 text-fg-primary">
                {title}
            </h2>
            <div className="flex flex-col divide-y divide-line-default rounded-lg bg-surface shadow-e1">
                {items.map((item, i) => (
                    <details key={i} className="group px-5 py-4">
                        <summary className="t-body-strong flex cursor-pointer list-none items-center justify-between gap-3 text-fg-primary [&::-webkit-details-marker]:hidden">
                            {item.question}
                            <Icon name="caretDown" className="size-icon-sm shrink-0 transition-transform group-open:rotate-180" />
                        </summary>
                        <p className="t-body mt-3 whitespace-pre-line text-fg-secondary">{item.answer}</p>
                    </details>
                ))}
            </div>
        </section>
    );
}
