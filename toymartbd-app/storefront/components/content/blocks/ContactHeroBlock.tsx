import { bnClass } from '@/components/content/blocks/shared';
import type { ContactHeroContent } from '@/lib/content/home-blocks';
import type { Locale } from '@/lib/i18n';

// Contact hero — Contact us 165:9891 (1440) / 167:7500 (390): a Bloom-sky band (radius xl-plus,
// 56/64/56/72 inset, 48 apart) with the overline pill, the headline, the lead and a status pill
// with a success dot; beside it a 420 column of speech bubbles, 14 apart — the customer's white
// on the left, the shop's brand-filled on the right, each with an overline label and a tail
// corner (radius xs). The bubbles are an illustration (aria-hidden) and are not drawn at 390.
// `heading` is the page's H1 when the hero opens a page built from sections.

interface Props {
    content: ContactHeroContent;
    locale: Locale;
    heading: 'h1' | 'h2';
}

export function ContactHeroBlock({ content, locale, heading: Heading }: Props) {
    return (
        <header className={`flex items-center gap-12 overflow-hidden rounded-lg-plus bg-(image:--tm-gradient-bloom-sky) px-5 py-6 lg:rounded-xl-plus lg:py-14 lg:pr-16 lg:pl-18 ${bnClass(locale)}`}>
            <div className="flex min-w-0 flex-1 flex-col items-start gap-3 lg:gap-4.5">
                {content.overline ? <p className="t-overline rounded-full bg-surface px-3.5 py-1.5 text-fg-brand">{content.overline}</p> : null}
                <Heading className="t-display-1 text-fg-primary">{content.title}</Heading>
                <p className="t-body text-fg-secondary lg:t-body-lg">{content.lead}</p>
                {content.status ? (
                    <p className="t-body-sm flex items-center gap-2 rounded-full bg-surface py-2 pr-4 pl-3 text-fg-primary">
                        <span aria-hidden="true" className="size-2.5 shrink-0 rounded-full bg-feedback-success" />
                        {content.status}
                    </p>
                ) : null}
            </div>
            {content.bubbles.length > 0 ? (
                <div aria-hidden="true" className="hidden w-105 shrink-0 flex-col gap-3.5 lg:flex">
                    {content.bubbles.map((bubble, i) => {
                        const store = bubble.from === 'store';
                        return (
                            <div key={i} className={`flex ${store ? 'justify-end' : ''}`}>
                                <div
                                    className={`flex max-w-79 flex-col gap-1 rounded-xl-minus px-4.5 py-3.5 ${
                                        store ? 'rounded-br-xs bg-brand text-fg-on-brand' : 'rounded-bl-xs bg-surface text-fg-primary'
                                    }`}
                                >
                                    {bubble.label ? <span className={`t-overline ${store ? '' : 'text-fg-secondary'}`}>{bubble.label}</span> : null}
                                    <span className="t-body w-70">{bubble.text}</span>
                                </div>
                            </div>
                        );
                    })}
                </div>
            ) : null}
        </header>
    );
}
