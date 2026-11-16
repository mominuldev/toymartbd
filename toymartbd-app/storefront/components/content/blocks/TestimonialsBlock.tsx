import { bnClass, MediaPicture } from "@/components/content/blocks/shared";
import { SwiperCarousel } from "@/components/carousel/SwiperCarousel";
import { swiperSlideClass } from "@/components/carousel/swiper-classes";
import { iconPaths } from "@/components/icons/paths";
import { SectionHeader } from "@/components/listing/ProductRail";
import type { BlockImage } from "@/lib/content/home-blocks";
import { format, type Locale, type Messages } from "@/lib/i18n";

// Testimonials slot (Phase 7): the published testimonials (Content testimonials), as quote cards in
// a Swiper slider (components/carousel/SwiperCarousel, 2026-11-16) — one card and a peek on phones, two from 768,
// three from 1024, with arrows and dots. Without JavaScript the same cards are a swipe row below
// 1024 and a 3-up grid from 1024. Stars are drawn, the rating is in the accessible label. No Figma
// frame of its own; it reuses the review-card language of the PDP.

interface Item {
    id: number;
    name: string;
    location: string | null;
    quote: string;
    rating: number | null;
    image: BlockImage | null;
}

export function TestimonialsBlock({
    id,
    title,
    testimonials,
    locale,
    t,
}: {
    id: string;
    title: string;
    testimonials: Item[];
    locale: Locale;
    t: Messages["home"];
}) {
    const headingId = `cms-${id}-title`;
    return (
        <section
            aria-labelledby={headingId}
            className={`flex flex-col gap-3 lg:gap-6 ${bnClass(locale)}`}
        >
            <SectionHeader id={headingId} title={title} />
            <SwiperCarousel
                count={testimonials.length}
                autoplay
                fallbackClass="relative -mx-gutter-mobile flex snap-x snap-mandatory gap-3 overflow-x-auto px-gutter-mobile pb-2 md:-mx-gutter-tablet md:px-gutter-tablet lg:mx-0 lg:grid lg:grid-cols-3 lg:gap-6 lg:overflow-visible lg:p-0 *:w-72 *:shrink-0 *:snap-start lg:*:w-auto"
                trackClass="flex *:h-auto *:w-[85%] *:shrink-0 *:px-1.5 md:*:w-1/2 lg:*:w-1/3 lg:*:px-3"
                viewportClass="-mx-1.5 lg:-mx-3"
                labels={{
                    region: title,
                    carouselRole: t.carouselRole,
                    slideRole: t.slideRole,
                    prev: t.testimonialsPrev,
                    next: t.testimonialsNext,
                    slideOf: format(t.slideOf, {
                        n: "{{index}}",
                        total: "{{slidesLength}}",
                    }),
                    goTo: format(t.goToSlide, { n: "{{index}}" }),
                }}
            >
                {testimonials.map((item) => (
                    <li key={item.id} className={swiperSlideClass}>
                        <figure className="flex h-full flex-col gap-3 rounded-md border border-line-brand-subtle bg-surface p-5 shadow-e1">
                            {item.rating ? (
                                <p
                                    role="img"
                                    aria-label={format(t.ratingLabel, {
                                        rating: String(item.rating),
                                    })}
                                    className="flex gap-0.5"
                                >
                                    {Array.from({ length: 5 }, (_, i) => (
                                        <svg
                                            key={i}
                                            viewBox="0 0 256 256"
                                            aria-hidden="true"
                                            className={`size-4 shrink-0 stroke-line-strong [stroke-width:14] ${
                                                i < item.rating!
                                                    ? "fill-accent"
                                                    : "fill-subtle"
                                            }`}
                                        >
                                            {iconPaths.starFill.map((d) => (
                                                <path key={d} d={d} />
                                            ))}
                                        </svg>
                                    ))}
                                </p>
                            ) : null}
                            <blockquote className="t-body grow text-fg-primary">
                                <p>“{item.quote}”</p>
                            </blockquote>
                            <figcaption className="flex items-center gap-3">
                                {item.image ? (
                                    <span className="size-10 shrink-0 overflow-hidden rounded-full bg-image">
                                        <MediaPicture
                                            image={{ ...item.image, alt: "" }}
                                            sizes="2.5rem"
                                            className="size-full object-cover"
                                        />
                                    </span>
                                ) : null}
                                <span className="flex flex-col">
                                    <span className="t-body-sm-strong text-fg-primary">
                                        {item.name}
                                    </span>
                                    {item.location ? (
                                        <span className="t-caption text-fg-tertiary">
                                            {item.location}
                                        </span>
                                    ) : null}
                                </span>
                            </figcaption>
                        </figure>
                    </li>
                ))}
            </SwiperCarousel>
        </section>
    );
}
