import { bnClass } from '@/components/content/blocks/shared';
import type { Locale } from '@/lib/i18n';

// Numbered cards — About us "What we believe" (170:10502 / 174:8244): outlined surface cards with a
// large 01, 02… numeral (Western digits in both locales, rule 36) whose ink cycles brand → success
// → info. Three across at 1440; at 390 the numeral sits beside the copy.

const inks = ['text-fg-brand', 'text-feedback-success', 'text-feedback-info'] as const;

interface Props {
    id: string;
    title: string;
    items: Array<{ title: string; body: string | null }>;
    locale: Locale;
}

export function NumberedCardsBlock({ id, title, items, locale }: Props) {
    const headingId = `cms-${id}-title`;
    return (
        <section aria-labelledby={headingId} className={`flex flex-col gap-4 lg:gap-6 ${bnClass(locale)}`}>
            <h2 id={headingId} className="t-display-2 text-fg-primary">
                {title}
            </h2>
            <ol className={`grid gap-3 lg:gap-6 ${items.length === 2 || items.length === 4 ? 'lg:grid-cols-2' : 'lg:grid-cols-3'}`}>
                {items.map((item, i) => (
                    <li key={i} className="flex gap-3 rounded-md-plus border border-line-default bg-surface p-5 lg:flex-col lg:gap-2.5 lg:rounded-lg lg:p-7">
                        <span aria-hidden="true" className={`t-display-1 shrink-0 ${inks[i % inks.length]}`}>
                            {String(i + 1).padStart(2, '0')}
                        </span>
                        <div className="flex flex-col gap-1 lg:gap-2.5">
                            <h3 className="t-feature-title text-fg-primary">{item.title}</h3>
                            {item.body ? <p className="t-body-sm text-fg-secondary lg:t-body">{item.body}</p> : null}
                        </div>
                    </li>
                ))}
            </ol>
        </section>
    );
}
