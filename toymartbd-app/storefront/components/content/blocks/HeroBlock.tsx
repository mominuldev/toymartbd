import Image from 'next/image';

import { blockLink, bnClass, MediaPicture } from '@/components/content/blocks/shared';
import type { HeroSlide } from '@/lib/content/home-blocks';
import { format, localizedPath, type Locale, type Messages } from '@/lib/i18n';
import { AGE_BANDS } from '@/lib/listing/state';
import hero from '@/public/images/home/hero.jpg';

// CMS hero / banner carousel (Phase 7) — the HomeHero design (Figma HeroCard 133:188 / 126:101,
// Gradient/Bloom-candy ground, radius/md) with the copy, link and image from the block. One slide
// renders exactly like the built-in hero; several become a swipeable scroll-snap row with anchor
// dots — no JavaScript, so it works with JS disabled. The first slide's image is the route's LCP
// element: preloaded, eager, high priority; every later slide is lazy. A slide without a library
// image uses the built-in hero art.

interface Props {
    id: string;
    slides: HeroSlide[];
    showAgePills: boolean;
    locale: Locale;
    t: Messages['home'];
    /** True for the page's first hero: its first image is the LCP element. */
    priority: boolean;
}

const SIZES = '(min-width: 64rem) 38rem, calc(100vw - 4.5rem)';

function Slide({ slide, index, total, blockId, showAgePills, locale, t, priority }: { slide: HeroSlide; index: number; total: number; blockId: string } & Omit<Props, 'slides' | 'id'>) {
    const pills = t.agePills as Record<string, string>;
    const lcp = priority && index === 0;
    const headingId = `hero-${blockId}-${index}-title`;
    const cta = slide.cta_href && slide.cta_label ? blockLink(locale, slide.cta_href) : null;
    const Heading = index === 0 ? 'h1' : 'h2';

    return (
        <section
            id={`hero-${blockId}-${index}`}
            aria-labelledby={headingId}
            aria-roledescription={total > 1 ? 'slide' : undefined}
            aria-label={total > 1 ? format(t.slideOf, { n: String(index + 1), total: String(total) }) : undefined}
            className="flex flex-col gap-2 overflow-hidden rounded-md bg-(image:--tm-gradient-bloom-candy) p-5 lg:h-120 lg:flex-row lg:items-center lg:gap-12 lg:px-12 lg:py-0"
        >
            <div className={`contents lg:flex lg:min-w-0 lg:flex-1 lg:flex-col lg:gap-4 ${bnClass(locale)}`}>
                {slide.overline ? <p className="t-overline text-fg-discount">{slide.overline}</p> : null}
                <Heading id={headingId} className="t-display-1 text-fg-primary">
                    {slide.headline}
                </Heading>
                {slide.sub ? <p className="t-body-sm text-fg-secondary lg:t-body-lg">{slide.sub}</p> : null}
                {showAgePills && index === 0 ? (
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
                ) : null}
                {cta ? (
                    <a
                        href={cta.href}
                        rel={cta.external ? 'noopener' : undefined}
                        className="t-button flex h-control-lg items-center justify-center rounded-full bg-(image:--tm-gradient-button-primary) px-6 text-fg-on-brand shadow-gloss-button max-lg:order-3 lg:min-w-32 lg:self-start"
                    >
                        {slide.cta_label}
                    </a>
                ) : null}
            </div>
            <div className="relative h-42.5 w-full shrink-0 overflow-hidden rounded-sm bg-image max-lg:order-1 lg:h-100 lg:w-152">
                {slide.image ? (
                    <MediaPicture image={slide.image} sizes={SIZES} priority={lcp} className="size-full object-cover" />
                ) : (
                    <Image src={hero} alt={t.heroAlt} preload={lcp} loading={lcp ? undefined : 'lazy'} sizes={SIZES} className="size-full object-cover" />
                )}
            </div>
        </section>
    );
}

export function HeroBlock({ id, slides, showAgePills, locale, t, priority }: Props) {
    if (slides.length === 1) {
        return <Slide slide={slides[0]!} index={0} total={1} blockId={id} showAgePills={showAgePills} locale={locale} t={t} priority={priority} />;
    }

    return (
        <div role="region" aria-roledescription="carousel" aria-label={t.slidesLabel} className="flex flex-col gap-3">
            <ul className="flex snap-x snap-mandatory gap-4 overflow-x-auto [scrollbar-width:none]">
                {slides.map((slide, i) => (
                    <li key={i} className="w-full shrink-0 snap-start">
                        <Slide slide={slide} index={i} total={slides.length} blockId={id} showAgePills={showAgePills} locale={locale} t={t} priority={priority} />
                    </li>
                ))}
            </ul>
            <ol className="flex justify-center gap-2">
                {slides.map((_, i) => (
                    <li key={i}>
                        <a
                            href={`#hero-${id}-${i}`}
                            aria-label={format(t.slideOf, { n: String(i + 1), total: String(slides.length) })}
                            className="block size-2.5 rounded-full bg-line-strong hover:bg-brand"
                        />
                    </li>
                ))}
            </ol>
        </div>
    );
}
