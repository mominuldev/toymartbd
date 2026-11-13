import { bnClass } from '@/components/content/blocks/shared';
import { formatNoBreak } from '@/components/FormatNoBreak';
import { Icon } from '@/components/icons/Icon';
import type { IconName } from '@/components/icons/paths';
import type { UspIconKey } from '@/lib/content/home-blocks';
import type { Locale } from '@/lib/i18n';
import { contact } from '@/lib/site-config';

// "Why parents shop with us" (Figma USP band 134:960 / 127:444) with the editor's items — the same
// markup as the built-in UspBand: Gradient/Bloom-sky ground, white cards with the Glow/Tile-* lift
// and a tilted pastel icon tile. Tiles cycle pink → butter → mint → sky by position. `{phone}` in
// an item's detail becomes the hotline from site config, never broken across lines.

const icons: Record<UspIconKey, IconName> = {
    cod: 'moneyFill',
    return: 'arrowCounterClockwiseFill',
    authentic: 'shieldCheckFill',
    support: 'phoneFill',
    delivery: 'truck',
    gift: 'gift',
    educational: 'graduationCap',
};

const tiles = [
    { tile: 'bg-tile-pink', ink: 'text-fg-brand', glow: 'shadow-glow-tile-pink' },
    { tile: 'bg-tile-butter', ink: 'text-fg-primary', glow: 'shadow-glow-tile-butter' },
    { tile: 'bg-tile-mint', ink: 'text-fg-ink-mint', glow: 'shadow-glow-tile-mint' },
    { tile: 'bg-tile-sky', ink: 'text-fg-ink-sky', glow: 'shadow-glow-tile-sky' },
] as const;

interface Props {
    id: string;
    eyebrow: string | null;
    title: string;
    items: Array<{ icon: UspIconKey; title: string; detail: string | null }>;
    locale: Locale;
}

export function UspBandBlock({ id, eyebrow, title, items, locale }: Props) {
    const headingId = `cms-${id}-title`;
    return (
        <section aria-labelledby={headingId} className={`bg-(image:--tm-gradient-bloom-sky) px-gutter-mobile py-8 md:px-gutter-tablet lg:px-gutter-desktop lg:py-14 ${bnClass(locale)}`}>
            <div className="mx-auto flex max-w-content flex-col gap-4 lg:items-center lg:gap-7">
                <div className="flex flex-col gap-1 lg:items-center">
                    {eyebrow ? <p className="t-overline text-fg-discount">{eyebrow}</p> : null}
                    <h2 id={headingId} className="t-h2 text-fg-primary">
                        {title}
                    </h2>
                </div>
                <ul className={`grid w-full grid-cols-2 gap-3 lg:gap-6 ${items.length >= 4 ? 'lg:grid-cols-4' : items.length === 3 ? 'lg:grid-cols-3' : 'lg:grid-cols-2'}`}>
                    {items.map((item, i) => {
                        const look = tiles[i % tiles.length]!;
                        return (
                            <li key={i} className={`flex flex-col items-start gap-2.5 rounded-md border border-line-brand-subtle bg-surface px-4 py-4.5 lg:gap-3.5 lg:p-6 ${look.glow}`}>
                                <span aria-hidden="true" className={`flex size-12 rotate-4 items-center justify-center rounded-sm lg:size-14 ${look.tile} ${look.ink}`}>
                                    <Icon name={icons[item.icon]} className="size-icon-md lg:size-7" />
                                </span>
                                <span className="t-h4 text-fg-primary">{item.title}</span>
                                {item.detail ? <span className="t-caption text-fg-secondary lg:t-body-sm">{formatNoBreak(item.detail, { phone: contact.hotline.display }, ['phone'])}</span> : null}
                            </li>
                        );
                    })}
                </ul>
            </div>
        </section>
    );
}
