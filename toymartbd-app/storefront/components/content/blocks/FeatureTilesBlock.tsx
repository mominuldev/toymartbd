import { bnClass, tileIcons } from '@/components/content/blocks/shared';
import { Icon } from '@/components/icons/Icon';
import type { FeatureTile } from '@/lib/content/home-blocks';
import type { Locale } from '@/lib/i18n';

// Feature tiles — the About us promise tiles (170:10502 / 174:8244): pastel grounds with a round
// icon badge, two across at 390 and four at 1440. Colours follow the position (mint, sky, butter,
// lavender), so an editor cannot make two neighbours clash.

const looks = [
    { ground: 'bg-tile-mint', badge: 'bg-feedback-success text-fg-inverse' },
    { ground: 'bg-tile-sky', badge: 'bg-brand text-fg-on-brand' },
    { ground: 'bg-tile-butter', badge: 'bg-accent text-fg-on-accent' },
    { ground: 'bg-tile-lavender', badge: 'bg-grape text-fg-on-grape' },
] as const;

interface Props {
    id: string;
    title: string | null;
    items: FeatureTile[];
    locale: Locale;
}

export function FeatureTilesBlock({ id, title, items, locale }: Props) {
    const headingId = `cms-${id}-title`;
    const columns = items.length >= 4 ? 'lg:grid-cols-4' : items.length === 3 ? 'lg:grid-cols-3' : 'lg:grid-cols-2';
    // Without a section title the tiles' own titles are the outline's h2s; with one they are h3s.
    const Item = title ? 'h3' : 'h2';
    return (
        <section aria-labelledby={title ? headingId : undefined} className={`flex flex-col gap-4 lg:gap-6 ${bnClass(locale)}`}>
            {title ? (
                <h2 id={headingId} className="t-display-2 text-fg-primary">
                    {title}
                </h2>
            ) : null}
            <ul className={`grid grid-cols-2 gap-3 lg:gap-5 ${columns}`}>
                {items.map((item, i) => {
                    const look = looks[i % looks.length]!;
                    return (
                        <li key={i} className={`flex flex-col gap-2 rounded-md-plus p-4 lg:rounded-lg lg:p-6 ${look.ground}`}>
                            <span aria-hidden="true" className={`mb-1.5 inline-flex size-10 items-center justify-center rounded-full lg:size-12 ${look.badge}`}>
                                <Icon name={tileIcons[item.icon]} className="size-icon-md lg:size-icon-lg" />
                            </span>
                            <Item className="t-feature-title text-fg-primary">{item.title}</Item>
                            {item.body ? <p className="t-body-sm text-fg-secondary lg:t-body">{item.body}</p> : null}
                        </li>
                    );
                })}
            </ul>
        </section>
    );
}
