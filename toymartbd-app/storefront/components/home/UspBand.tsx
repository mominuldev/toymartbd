import { Icon } from '@/components/icons/Icon';
import type { IconName } from '@/components/icons/paths';
import { format, type Messages } from '@/lib/i18n';
import { contact } from '@/lib/site-config';

// "Why parents shop with us" — Figma USP band 134:960 (1440) / 127:444 (390), Gradient/Bloom-sky,
// with TrustStrip FeatureRow4 144:2062 (desktop) / Feature2x2 144:2033 (mobile): four white cards
// with a soft brand-subtle rim and the matching Glow/Tile-* lift, a 4°-tilted pastel icon tile
// (filled Phosphor glyphs in the tile's ink), a Fredoka title and one friendly line. Home only;
// the PDP keeps the quiet TrustStrip. The hotline is the site-config placeholder until confirmed.

const features = [
    { icon: 'moneyFill', tile: 'bg-tile-pink', ink: 'text-fg-brand', glow: 'shadow-glow-tile-pink', title: 'uspCod', detail: 'uspCodDetail' },
    { icon: 'arrowCounterClockwiseFill', tile: 'bg-tile-butter', ink: 'text-fg-primary', glow: 'shadow-glow-tile-butter', title: 'uspReturn', detail: 'uspReturnDetail' },
    { icon: 'shieldCheckFill', tile: 'bg-tile-mint', ink: 'text-fg-ink-mint', glow: 'shadow-glow-tile-mint', title: 'uspAuthentic', detail: 'uspAuthenticDetail' },
    { icon: 'phoneFill', tile: 'bg-tile-sky', ink: 'text-fg-ink-sky', glow: 'shadow-glow-tile-sky', title: 'uspPeople', detail: 'uspPeopleDetail' },
] as const satisfies ReadonlyArray<{ icon: IconName; tile: string; ink: string; glow: string; title: keyof Messages['home']; detail: keyof Messages['home'] }>;

export function UspBand({ t }: { t: Messages['home'] }) {
    return (
        <section aria-labelledby="home-usp" className="bg-(image:--tm-gradient-bloom-sky) px-gutter-mobile py-8 md:px-gutter-tablet lg:px-gutter-desktop lg:py-14">
            <div className="mx-auto flex max-w-content flex-col gap-4 lg:items-center lg:gap-7">
                <div className="flex flex-col gap-1 lg:items-center">
                    <p className="t-overline text-fg-discount">{t.uspEyebrow}</p>
                    <h2 id="home-usp" className="t-h2 text-fg-primary">
                        {t.uspTitle}
                    </h2>
                </div>
                <ul className="grid w-full grid-cols-2 gap-3 lg:grid-cols-4 lg:gap-6">
                    {features.map((f) => (
                        <li key={f.icon} className={`flex flex-col items-start gap-2.5 rounded-md border border-line-brand-subtle bg-surface px-4 py-4.5 lg:gap-3.5 lg:p-6 ${f.glow}`}>
                            <span aria-hidden="true" className={`flex size-12 rotate-4 items-center justify-center rounded-sm lg:size-14 ${f.tile} ${f.ink}`}>
                                <Icon name={f.icon} className="size-icon-md lg:size-7" />
                            </span>
                            <span className="t-h4 text-fg-primary">{t[f.title]}</span>
                            <span className="t-caption text-fg-secondary lg:t-body-sm">{format(t[f.detail], { phone: contact.hotline.display })}</span>
                        </li>
                    ))}
                </ul>
            </div>
        </section>
    );
}
