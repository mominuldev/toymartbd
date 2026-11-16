import Image from 'next/image';

import { buttonClass } from '@/components/layout/button-styles';
import type { Messages } from '@/lib/i18n';
import { localizedPath, type Locale } from '@/lib/i18n';
import { AGE_BANDS } from '@/lib/listing/state';
import hero from '@/public/images/home/hero.jpg';

// Home hero, concept "Tell us the age" — Figma HeroCard 133:188 (1440: 1280 × 480, two columns,
// image 608 × 400 right) and 126:101 (390: one column, image 318 × 170 between the sub-line and
// the age pills). Gradient/Bloom-candy ground, radius/md. The age pills and the CTA are plain
// links into the URL-addressable all-toys listing (`/toys?age=24-48`).
//
// The photograph is the route's LCP element (storefront spec §B1.0): the only image on Home that
// is preloaded (`preload`, Next 16's replacement for `priority`), never lazy, served AVIF/WebP at
// the rendered width. The art is the Figma mock `hero/home` (126:2) until studio photography.
// "See the flash sale" (Outline, Figma 133:308) shows only while a sale is running (`flashSale`) — a
// section with no sale is removed, not left pointing at nothing (§B1.3) — and only from 1024: the
// 390 hero (126:101) carries the one CTA.

export function HomeHero({ locale, t, flashSale = false }: { locale: Locale; t: Messages['home']; flashSale?: boolean }) {
    const pills = t.agePills as Record<string, string>;
    return (
        <section aria-labelledby="home-hero-title" className="flex flex-col gap-2 overflow-hidden rounded-md bg-(image:--tm-gradient-bloom-candy) p-5 lg:h-120 lg:flex-row lg:items-center lg:gap-12 lg:px-12 lg:py-0">
            <div className="contents lg:flex lg:min-w-0 lg:flex-1 lg:flex-col lg:gap-4">
                <p className="t-overline text-fg-discount">{t.overline}</p>
                <h1 id="home-hero-title" className="t-display-1 text-fg-primary">
                    {t.headline}
                </h1>
                <p className="t-body-sm text-fg-secondary lg:t-body-lg">{t.sub}</p>
                <nav aria-label={t.ageChooser} className="-mr-5 overflow-x-auto max-lg:order-2 lg:mr-0 lg:overflow-visible">
                    <ul className="flex gap-2 pr-5 lg:flex-wrap lg:pr-0">
                        {AGE_BANDS.map((band) => (
                            <li key={band} className="shrink-0">
                                <a
                                    href={localizedPath(locale, `/toys?age=${band}`)}
                                    className="t-chip flex h-control-sm min-w-14 items-center justify-center whitespace-nowrap rounded-full border border-line-default bg-surface px-3 text-fg-primary hover:border-line-strong"
                                >
                                    {pills[band] ?? band}
                                </a>
                            </li>
                        ))}
                    </ul>
                </nav>
                <div className="flex gap-3 max-lg:order-3">
                    <a
                        href={localizedPath(locale, '/toys')}
                        className="t-button flex h-control-lg grow items-center justify-center rounded-full bg-(image:--tm-gradient-button-primary) px-6 text-fg-on-brand shadow-gloss-button lg:min-w-32 lg:grow-0"
                    >
                        {t.browseAll}
                    </a>
                    {flashSale ? (
                        <a href={localizedPath(locale, '/flash-sale')} className={`${buttonClass('outline', 'lg')} max-lg:hidden`}>
                            {t.seeFlashSale}
                        </a>
                    ) : null}
                </div>
            </div>
            <div className="relative h-42.5 w-full shrink-0 overflow-hidden rounded-sm bg-image max-lg:order-1 lg:h-100 lg:w-152">
                <Image src={hero} alt={t.heroAlt} preload sizes="(min-width: 64rem) 38rem, calc(100vw - 4.5rem)" className="size-full object-cover" />
            </div>
        </section>
    );
}
