import { SwiperCarousel } from "@/components/carousel/SwiperCarousel";
import { swiperSlideClass } from "@/components/carousel/swiper-classes";
import { format, type Locale, type Messages } from "@/lib/i18n";
import type { ProductCard as Card } from "@/lib/listing/response";
import { ProductCard } from "./ProductCard";

// A product row: a swipe rail of 148 cards bleeding to the screen edge below 1024 (Figma Home
// 127:333 / PDP 128:1556) and a 4-up grid of 302 cards from 1024 (Home 134:448, PDP 135:1997,
// Search "Popular right now" 161:16310). Reuses the listing ProductCard. An empty row renders
// nothing — a section is removed, never shown empty.
// The rail is `relative`: the cards' sr-only text is absolutely positioned, and without a
// containing block inside the scroller it escapes the clip and widens the whole page on mobile.
// `slider` (Home Best sellers / New arrivals and CMS product rails, 2026-11-16): below 1024 the row
// becomes a Swiper slider with dots and prev/next (Figma RailSliderControls 434:12311 under Home
// 127:333 / 127:394; components/carousel/SwiperCarousel). The track still bleeds to the screen
// edge: the viewport cancels the gutter with negative margins and puts it back as padding, which
// Swiper subtracts from its width. Without JavaScript, and from 1024, it is the row / grid above.

export function SectionHeader({
    id,
    title,
    action,
}: {
    id: string;
    title: string;
    action?: { href: string; label: string; ariaLabel?: string };
}) {
    if (!action) {
        return (
            <h2 id={id} className="t-h2 text-fg-primary">
                {title}
            </h2>
        );
    }
    return (
        <div className="flex items-center justify-between gap-4">
            <h2 id={id} className="t-h2 text-fg-primary">
                {title}
            </h2>
            {action ? (
                <a
                    href={action.href}
                    aria-label={action.ariaLabel}
                    className="t-body-sm-strong shrink-0 whitespace-nowrap text-fg-brand underline-offset-4 hover:underline"
                >
                    {action.label}
                </a>
            ) : null}
        </div>
    );
}

interface Props {
    id: string;
    title: string;
    products: Card[];
    locale: Locale;
    t: Messages["listing"];
    action?: { href: string; label: string; ariaLabel?: string };
    /** Desktop columns shown (the rest are mobile-rail only). */
    desktop?: number;
    /** A Swiper slider below 1024 (progressive enhancement). */
    slider?: boolean;
}

const ROW_BASE =
    "relative -mx-gutter-mobile flex snap-x snap-mandatory scroll-px-gutter-mobile gap-3 overflow-x-auto px-gutter-mobile pt-2 pb-6 md:-mx-gutter-tablet md:scroll-px-gutter-tablet md:px-gutter-tablet lg:mx-0 lg:grid lg:gap-6 lg:overflow-visible lg:p-0 *:w-37 *:shrink-0 *:snap-start lg:*:w-auto";
const ROW = `${ROW_BASE} lg:grid-cols-4`;

/** The rail's row (swipe row below 1024, a grid from 1024): 4-up, or 5-up for the Home flash-sale band (Figma 133:452). */
export const railRowClass = (columns: 4 | 5 = 4): string => `${ROW_BASE} ${columns === 5 ? "lg:grid-cols-5" : "lg:grid-cols-4"}`;

export function ProductRail({
    id,
    title,
    products,
    locale,
    t,
    action,
    desktop = 4,
    slider = false,
}: Props) {
    if (products.length === 0) return null;
    const items = products.map((product, i) => (
        <li
            key={product.id}
            className={`${slider ? swiperSlideClass : ""} ${
                i >= desktop ? "lg:hidden" : ""
            }`}
        >
            <ProductCard product={product} locale={locale} t={t} />
        </li>
    ));
    return (
        <section aria-labelledby={id} className="flex flex-col gap-3 lg:gap-6">
            <SectionHeader id={id} title={title} action={action} />
            {slider ? (
                <SwiperCarousel
                    count={products.length}
                    region={false}
                    media="(width < 64rem)"
                    fallbackClass={ROW}
                    trackClass="flex pt-2 pb-2 *:h-auto *:w-40 *:shrink-0 *:pr-3 *:last:w-37 *:last:pr-0"
                    viewportClass="-mx-gutter-mobile px-gutter-mobile md:-mx-gutter-tablet md:px-gutter-tablet"
                    labels={{
                        region: title,
                        carouselRole: t.sliderRole,
                        slideRole: t.sliderSlideRole,
                        prev: t.sliderPrev,
                        next: t.sliderNext,
                        slideOf: format(t.sliderSlideOf, {
                            n: "{{index}}",
                            total: "{{slidesLength}}",
                        }),
                        goTo: format(t.sliderGoTo, { n: "{{index}}" }),
                    }}
                >
                    {items}
                </SwiperCarousel>
            ) : (
                <ul className={ROW}>{items}</ul>
            )}
        </section>
    );
}
