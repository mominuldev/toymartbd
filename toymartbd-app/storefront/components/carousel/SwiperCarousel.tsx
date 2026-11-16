"use client";

import {
    useEffect,
    useRef,
    useState,
    useSyncExternalStore,
    type ReactNode,
} from "react";
import type { Swiper as SwiperInstance } from "swiper";

import {
    swiperSlideClass,
    swiperTrackClass as TRACK,
} from "@/components/carousel/swiper-classes";
import { Icon } from "@/components/icons/Icon";

// A Swiper 14 slider as progressive enhancement, shared by the testimonials block and the product
// rails (Figma RailSliderControls 434:12311 under Home Best sellers 127:333 / New arrivals 127:394).
//
// The server renders the slides as a plain list styled by `fallbackClass` (a native scroll-snap
// row, or a grid) — what a visitor without JavaScript, and the storefront E2E suite (JavaScript
// off), gets. Once hydrated, and while `media` matches, the same <li>s become a Swiper track
// styled by `trackClass`, with the controls: clickable pagination dots (active = a 20×8 brand pill)
// and 44 px prev/next buttons. When `media` stops matching (e.g. the rail's 1024 grid) Swiper is
// destroyed and the fallback layout returns. Controls hide themselves when every slide fits.
//
// Swiper is loaded with a dynamic import once the slider nears the viewport, so it never weighs on
// first-load JavaScript (rule 29).
// No Swiper stylesheet: custom wrapper/slide class names keep swiper/css out, and every colour and
// spacing value is a token utility (rule 26). Slide widths are CSS (`slidesPerView: 'auto'`) and
// gaps are slide padding, so the JavaScript carries no pixel values. Optional autoplay (6 s)
// pauses on hover and keyboard focus, stops for good on interaction, and is off under
// prefers-reduced-motion (WCAG 2.2.2).

const control =
    "flex size-control-md items-center justify-center rounded-full border border-line-brand-subtle bg-surface text-fg-brand shadow-e1 transition-colors duration-(--tm-duration-fast) ease-standard hover:bg-brand-subtle [&.is-disabled]:opacity-40 [&.is-locked]:hidden";

// Each dot is a 24 px touch target (WCAG 2.5.8) drawing an 8 px dot — a 20 × 8 pill when active —
// with ::before (Figma RailSliderControls: 24 × 24 targets, 28 × 24 for the active pill).
const dotsClass =
    'flex flex-wrap items-center [&.is-locked]:hidden [&_.tm-dot]:flex [&_.tm-dot]:h-6 [&_.tm-dot]:min-w-6 [&_.tm-dot]:cursor-pointer [&_.tm-dot]:items-center [&_.tm-dot]:justify-center [&_.tm-dot]:before:block [&_.tm-dot]:before:h-2 [&_.tm-dot]:before:w-2 [&_.tm-dot]:before:rounded-full [&_.tm-dot]:before:bg-line-strong [&_.tm-dot]:before:transition-all [&_.tm-dot]:before:duration-(--tm-duration-fast) [&_.tm-dot]:before:content-[""] [&_.tm-dot-active]:min-w-7 [&_.tm-dot-active]:before:w-5 [&_.tm-dot-active]:before:bg-brand';

type Modules = [typeof import("swiper"), typeof import("swiper/modules")];

export interface CarouselLabels {
    /** Accessible name of the region. */
    region: string;
    /** aria-roledescription of the region ("carousel"). */
    carouselRole: string;
    /** Kept for the callers' copy; slides are announced as list items with `slideOf` as their name. */
    slideRole: string;
    prev: string;
    next: string;
    /** "Slide {{index}} of {{slidesLength}}" — Swiper's own placeholders. */
    slideOf: string;
    /** "Go to slide {{index}}". */
    goTo: string;
}

interface Props {
    children: ReactNode;
    count: number;
    labels: CarouselLabels;
    /** The list's classes without JavaScript (and wherever `media` does not match). */
    fallbackClass: string;
    /** The list's classes as a Swiper track: `flex` plus slide widths and padding via `*:`. */
    trackClass: string;
    /** The clipping viewport around the track (negative margins that cancel the slide padding). */
    viewportClass: string;
    /** Extra classes on the controls row (e.g. the page gutters when the track bleeds). */
    controlsClass?: string;
    /** Only a slider while this media query matches; always when omitted. */
    media?: string;
    autoplay?: boolean;
    /** Wrap in a labelled carousel region (false when the caller's <section> already names it). */
    region?: boolean;
}

const subscribe = () => () => {};

function useMedia(query: string | undefined): boolean {
    return useSyncExternalStore(
        (notify) => {
            if (!query) return () => {};
            const mq = window.matchMedia(query);
            mq.addEventListener("change", notify);
            return () => mq.removeEventListener("change", notify);
        },
        () => (query ? window.matchMedia(query).matches : true),
        () => false
    );
}

export function SwiperCarousel({
    children,
    count,
    labels,
    fallbackClass,
    trackClass,
    viewportClass,
    controlsClass = "",
    media,
    autoplay = false,
    region = true,
}: Props) {
    // false on the server and during hydration: the no-JS markup hydrates as-is.
    const hydrated = useSyncExternalStore(
        subscribe,
        () => true,
        () => false
    );
    const matches = useMedia(media);
    const wanted = hydrated && matches && count > 1;
    // Swiper is fetched only when the slider comes within 300 px of the viewport, so a rail below the
    // fold costs no first-load JavaScript (rule 29). Until it has loaded, the native row stays.
    const [modules, setModules] = useState<Modules | null>(null);
    const enhanced = wanted && modules !== null;
    const root = useRef<HTMLDivElement>(null);
    const prev = useRef<HTMLButtonElement>(null);
    const next = useRef<HTMLButtonElement>(null);
    const dots = useRef<HTMLDivElement>(null);

    useEffect(() => {
        const el = root.current;
        if (!wanted || modules || !el) return;
        let cancelled = false;
        const load = () =>
            void Promise.all([import("swiper"), import("swiper/modules")]).then(
                (m) => !cancelled && setModules(m)
            );
        const io = new IntersectionObserver(
            (entries) => {
                if (entries.some((e) => e.isIntersecting)) {
                    io.disconnect();
                    load();
                }
            },
            { rootMargin: "300px 0px" }
        );
        io.observe(el);
        return () => {
            cancelled = true;
            io.disconnect();
        };
    }, [wanted, modules]);

    useEffect(() => {
        const el = root.current;
        if (!enhanced || !modules || !el) return;
        let swiper: SwiperInstance | null = null;
        const pause = () => swiper?.autoplay?.stop();
        const [
            { default: Swiper },
            { A11y, Autoplay, Keyboard, Navigation, Pagination },
        ] = modules;
        {
            const reduce = window.matchMedia(
                "(prefers-reduced-motion: reduce)"
            ).matches;
            swiper = new Swiper(el, {
                modules: [A11y, Autoplay, Keyboard, Navigation, Pagination],
                wrapperClass: TRACK,
                slideClass: swiperSlideClass,
                slidesPerView: "auto",
                spaceBetween: 0,
                watchOverflow: true,
                rewind: autoplay,
                grabCursor: true,
                speed: reduce ? 0 : 450,
                keyboard: { enabled: true, onlyInViewport: true },
                navigation: {
                    prevEl: prev.current,
                    nextEl: next.current,
                    disabledClass: "is-disabled",
                    lockClass: "is-locked",
                },
                pagination: {
                    el: dots.current,
                    clickable: true,
                    bulletClass: "tm-dot",
                    bulletActiveClass: "tm-dot-active",
                    lockClass: "is-locked",
                },
                autoplay:
                    autoplay && !reduce
                        ? {
                              delay: 6000,
                              pauseOnMouseEnter: true,
                              disableOnInteraction: true,
                          }
                        : false,
                a11y: {
                    prevSlideMessage: labels.prev,
                    nextSlideMessage: labels.next,
                    paginationBulletMessage: labels.goTo,
                    slideLabelMessage: labels.slideOf,
                    containerRoleDescriptionMessage: labels.carouselRole,
                    // Slides stay list items: role="group" inside a <ul> breaks the list (axe `list`).
                    slideRole: "listitem",
                },
            });
            el.addEventListener("focusin", pause);
        }

        return () => {
            el.removeEventListener("focusin", pause);
            swiper?.destroy(true, true);
        };
    }, [enhanced, modules, autoplay, labels]);

    const body = (
        <>
            {/* `relative`: the cards' sr-only text is absolutely positioned and would otherwise escape the clip and widen the page (see ProductRail). */}
            <div
                ref={root}
                className={
                    enhanced ? `relative overflow-hidden ${viewportClass}` : ""
                }
            >
                <ul
                    className={`${TRACK} ${
                        enhanced ? trackClass : fallbackClass
                    }`}
                >
                    {children}
                </ul>
            </div>
            {enhanced ? (
                <div
                    className={`flex items-center justify-between gap-4 ${controlsClass}`}
                >
                    <div ref={dots} className={dotsClass} />
                    <div className="ml-auto flex gap-2">
                        <button
                            ref={prev}
                            type="button"
                            aria-label={labels.prev}
                            className={control}
                        >
                            <Icon name="caretLeft" className="size-icon-md" />
                        </button>
                        <button
                            ref={next}
                            type="button"
                            aria-label={labels.next}
                            className={control}
                        >
                            <Icon name="caretRight" className="size-icon-md" />
                        </button>
                    </div>
                </div>
            ) : null}
        </>
    );

    return region ? (
        <div
            role="region"
            aria-roledescription={labels.carouselRole}
            aria-label={labels.region}
            className="flex flex-col gap-4 lg:gap-6"
        >
            {body}
        </div>
    ) : (
        <div className="flex flex-col gap-4">{body}</div>
    );
}
