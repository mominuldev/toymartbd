import { Icon } from '@/components/icons/Icon';
import { getMessages, type Locale } from '@/lib/i18n';

interface Props {
    locale: Locale;
    /** `responsive`: sm below 1024 (mobile header), md from 1024. `sm` / `md`: fixed (maintenance uses sm). */
    size?: 'responsive' | 'sm' | 'md';
}

// PLACEHOLDER logotype until the real logo is designed (Figma Wordmark 123:1712): a tilted
// gradient tile with a puzzle glyph, "Toy" in coral + "Mart" in navy (Latin/Wordmark/* text
// styles) and a sunshine "BD" tag. Decorative: the link around it carries the accessible name.
// Each class set is spelled out in full (sm, md, responsive) so Tailwind's scanner sees the lg:
// variants — a class assembled at runtime (`lg:${md}`) is never generated.
export function Wordmark({ locale, size = 'responsive' }: Props) {
    const w = getMessages(locale).site.wordmark;
    const pick = (sm: string, md: string, responsive: string) => (size === 'sm' ? sm : size === 'md' ? md : responsive);
    return (
        <span aria-hidden="true" lang="en" className={`inline-flex items-center ${pick('gap-1.5', 'gap-2', 'gap-1.5 lg:gap-2')}`}>
            <span
                className={`inline-flex rotate-6 items-center justify-center rounded-xs bg-(image:--tm-gradient-button-primary) text-fg-on-brand ${pick('size-7', 'size-9', 'size-7 lg:size-9')}`}
            >
                <Icon name="puzzlePiece" className={pick('size-4.5', 'size-5.5', 'size-4.5 lg:size-5.5')} />
            </span>
            <span className={`${pick('t-wordmark-sm', 't-wordmark-md', 't-wordmark-sm lg:t-wordmark-md')} whitespace-nowrap text-fg-primary`}>
                <span className="text-fg-brand">{w.first}</span>
                {w.second}
            </span>
            {/* A space the flex layout drops: the text reads "ToyMart BD", which the link's accessible
                name (nav.logoAria) starts with — WCAG 2.5.3 label in name. */}
            {' '}
            <span className={`${pick('t-wordmark-tag-sm', 't-wordmark-tag-md', 't-wordmark-tag-sm lg:t-wordmark-tag-md')} rounded-full bg-accent px-1.5 text-fg-on-accent`}>{w.tag}</span>
        </span>
    );
}
