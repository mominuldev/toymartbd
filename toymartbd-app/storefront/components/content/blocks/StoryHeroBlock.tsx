import { bloomTone, bnClass, MediaPicture } from '@/components/content/blocks/shared';
import type { StoryHeroContent } from '@/lib/content/home-blocks';
import type { Locale } from '@/lib/i18n';

// Story hero — About us 170:10502 (1440) / 174:8244 (390): a Bloom-gradient band, the overline pill,
// the headline, a lead and a second paragraph, beside a sticker-style collage of up to three
// library photos and a round butter badge. At 390 the collage sits above the copy. The collage is
// decorative (aria-hidden); with no photos the copy takes the full width. `heading` is the page's
// H1 when the hero opens a page built from sections, an h2 anywhere else.

// Sticker geometry in % of a 500 × 400 box (Figma 170:10743): centre, width, tilt — by photo count.
const layouts = {
    1: [{ x: 50, y: 50, w: 62, r: -4 }],
    2: [
        { x: 30, y: 45, w: 50, r: -6 },
        { x: 68, y: 55, w: 50, r: 5 },
    ],
    3: [
        { x: 26.84, y: 32.65, w: 45.2, r: -6 },
        { x: 72.72, y: 27.9, w: 41.2, r: 5 },
        { x: 54.8, y: 70.4, w: 47.2, r: -3 },
    ],
} as const;

interface Props {
    content: StoryHeroContent;
    locale: Locale;
    heading: 'h1' | 'h2';
    /** The page's LCP when it opens the page: the photos load eagerly. */
    priority?: boolean;
}

export function StoryHeroBlock({ content, locale, heading: Heading, priority = false }: Props) {
    const photos = content.images.slice(0, 3);
    const stickers = photos.length > 0 ? layouts[photos.length as 1 | 2 | 3] : [];
    return (
        <header className={`flex flex-col items-center gap-6 overflow-hidden rounded-lg-plus px-5 pt-6 pb-7 lg:flex-row lg:gap-14 lg:rounded-xl-plus lg:py-16 lg:pr-16 lg:pl-18 ${bloomTone[content.tone]} ${bnClass(locale)}`}>
            <div className="flex min-w-0 flex-col items-start gap-4.5 lg:flex-1">
                {content.overline ? <p className="t-overline rounded-full bg-surface px-3.5 py-1.5 text-fg-brand">{content.overline}</p> : null}
                <Heading className="t-display-1 text-fg-primary">{content.title}</Heading>
                <p className="t-body text-fg-primary lg:t-body-lg">{content.lead}</p>
                {content.body ? <p className="t-body text-fg-secondary lg:t-body-lg">{content.body}</p> : null}
            </div>
            {photos.length > 0 ? (
                <div aria-hidden="true" className="relative aspect-5/4 w-full max-w-125 shrink-0 max-lg:-order-1 lg:w-125">
                    {photos.map((photo, i) => {
                        const at = stickers[i]!;
                        return (
                            <span
                                key={photo.url}
                                className="absolute aspect-square -translate-1/2 overflow-hidden rounded-md-plus bg-surface p-2 shadow-e2 lg:rounded-lg-plus"
                                style={{ left: `${at.x}%`, top: `${at.y}%`, width: `${at.w}%`, rotate: `${at.r}deg` }}
                            >
                                <MediaPicture
                                    image={{ ...photo, alt: '' }}
                                    sizes="(min-width: 1024px) 236px, 45vw"
                                    priority={priority && i === 0}
                                    className="size-full rounded-md-minus object-cover lg:rounded-md-plus"
                                />
                            </span>
                        );
                    })}
                    {content.badge ? (
                        <span
                            className="t-badge-sticker absolute flex aspect-square w-[12.8%] -translate-1/2 rotate-10 flex-col items-center justify-center rounded-full bg-accent text-center text-fg-on-accent"
                            style={{ left: '89.2%', top: '59.3%' }}
                        >
                            <span>{content.badge}</span>
                            {content.badge_sub ? <span>{content.badge_sub}</span> : null}
                        </span>
                    ) : null}
                </div>
            ) : null}
        </header>
    );
}
