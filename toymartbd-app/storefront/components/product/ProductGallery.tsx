import { preload } from 'react-dom';

import { Icon } from '@/components/icons/Icon';
import { format, type Locale, type Messages } from '@/lib/i18n';
import type { StorefrontProduct } from '@/lib/product/variants';
import { GalleryEnhancer } from './GalleryEnhancer';

interface Props {
    product: StorefrontProduct;
    name: string;
    locale: Locale;
    t: Messages;
    /** The selected variant's discount (the badge follows the price block). */
    discountPercent: number | null;
}

const SIZES = '(min-width: 64rem) 38rem, calc(100vw - 2rem)';

// ProductGallery — Figma 117:2479: Layout=MobileSwipe (390, 128:711) and DesktopRail (1440,
// 117:2456). One square stage, reserved by `aspect-square` before any image loads (zero CLS):
// at 390 a scroll-snap swipe track with dots and a counter; at 1440 a 608 stage with a rail of
// 96 thumbs. Without JavaScript the track swipes and the thumbs are fragment links that scroll
// it; GalleryEnhancer adds the active states, arrow keys and the 2× hover magnifier.
// Slot 1 is the PDP LCP element: preloaded, fetchpriority high, never lazy.
export function ProductGallery({ product, name, locale, t, discountPercent }: Props) {
    const images = product.images;
    const total = images.length;
    const first = images[0];
    if (first) {
        preload(first.url, { as: 'image', fetchPriority: 'high', imageSrcSet: first.srcset_avif ?? first.srcset_webp ?? undefined, imageSizes: SIZES });
    }
    const alt = (image: (typeof images)[number], n: number): string =>
        (locale === 'bn' ? image.alt_bn : image.alt) ?? format(t.pdp.galleryImage, { product: name, n: String(n), total: String(total) });

    return (
        <div id="pdp-gallery" className="flex flex-col gap-4" role="group" aria-roledescription="carousel" aria-label={format(t.pdp.gallery, { product: name })}>
            <div className="relative aspect-square w-full overflow-hidden rounded-sm bg-tile-pink lg:border lg:border-line-brand-subtle">
                {total > 0 ? (
                    <ul data-gallery-track tabIndex={0} className="pdp-track flex size-full snap-x snap-mandatory overflow-x-auto overscroll-x-contain focus-visible:shadow-none lg:overflow-hidden">
                        {images.map((image, i) => (
                            <li
                                key={image.id}
                                id={`pdp-image-${i + 1}`}
                                aria-label={format(t.pdp.galleryImage, { product: name, n: String(i + 1), total: String(total) })}
                                className="relative size-full shrink-0 snap-center snap-always"
                            >
                                <picture>
                                    {image.srcset_avif ? <source type="image/avif" srcSet={image.srcset_avif} sizes={SIZES} /> : null}
                                    {image.srcset_webp ? <source type="image/webp" srcSet={image.srcset_webp} sizes={SIZES} /> : null}
                                    <img
                                        src={image.url}
                                        alt={alt(image, i + 1)}
                                        width={image.width ?? undefined}
                                        height={image.height ?? undefined}
                                        loading={i === 0 ? 'eager' : 'lazy'}
                                        fetchPriority={i === 0 ? 'high' : undefined}
                                        decoding={i === 0 ? 'sync' : 'async'}
                                        data-zoom={image.url}
                                        className="size-full object-cover"
                                    />
                                </picture>
                            </li>
                        ))}
                    </ul>
                ) : (
                    <span aria-hidden="true" className="flex size-full flex-col items-center justify-center gap-2 text-fg-tertiary">
                        <Icon name="puzzlePiece" className="size-icon-category" />
                        <span className="t-caption">{t.listing.noImage}</span>
                    </span>
                )}
                {/* Hover magnifier (desktop): GalleryEnhancer paints the current photo at 2× here. */}
                <div data-gallery-lens aria-hidden="true" className="pointer-events-none absolute inset-0 hidden bg-image bg-no-repeat [background-size:200%]" />
                {discountPercent ? (
                    <span className="t-badge pointer-events-none absolute top-3 left-3 inline-flex h-6 min-w-10 items-center justify-center rounded-full bg-brand px-2 text-fg-on-brand lg:top-7.25 lg:left-3.75">
                        {format(t.pdp.discount, { percent: String(discountPercent) })}
                    </span>
                ) : null}
                <button
                    type="button"
                    disabled
                    aria-label={format(t.pdp.wishlist, { name })}
                    className="absolute top-2.5 right-3 flex size-7 items-center justify-center rounded-full bg-surface text-fg-secondary shadow-e1 before:absolute before:size-touch-min before:content-[''] lg:top-3.75 lg:right-4.25 lg:size-control-lg lg:border lg:border-line-default lg:shadow-none"
                >
                    <Icon name="heart" className="size-icon-sm lg:size-icon-lg" />
                </button>
                {total > 1 ? (
                    <>
                        <span data-gallery-counter aria-hidden="true" className="t-caption-strong absolute right-3 bottom-3 rounded-full bg-surface px-2.5 py-0.75 text-fg-secondary tabular-nums lg:hidden">
                            {format(t.pdp.galleryCounter, { n: '1', total: String(total) })}
                        </span>
                        {total <= 6 ? (
                            <span aria-hidden="true" className="absolute bottom-3 left-1/2 flex -translate-x-1/2 items-center gap-1.5 rounded-full bg-surface px-2 py-1.5 lg:hidden">
                                {images.map((image, i) => (
                                    <span
                                        key={image.id}
                                        data-gallery-dot
                                        data-active={i === 0 ? '' : undefined}
                                        className="h-1.5 w-1.5 rounded-full bg-line-strong transition-[width] duration-(--tm-duration-fast) data-active:w-4 data-active:bg-brand"
                                    />
                                ))}
                            </span>
                        ) : null}
                    </>
                ) : null}
            </div>
            {total > 1 ? (
                <div role="tablist" aria-label={format(t.pdp.gallery, { product: name })} className="hidden gap-3 overflow-x-auto lg:flex">
                    {images.map((image, i) => (
                        <a
                            key={image.id}
                            href={`#pdp-image-${i + 1}`}
                            role="tab"
                            aria-selected={i === 0}
                            aria-label={format(t.pdp.galleryThumb, { n: String(i + 1) })}
                            data-gallery-thumb={i}
                            className="relative size-24 shrink-0 overflow-hidden rounded-xs border border-line-default bg-tile-pink hover:border-line-strong aria-selected:border-(length:--tm-border-width-strong) aria-selected:border-brand"
                        >
                            <img src={image.thumb_url} alt="" width={96} height={96} loading="lazy" decoding="async" className="size-full object-cover opacity-85 in-aria-selected:opacity-100" />
                        </a>
                    ))}
                </div>
            ) : null}
            {total > 0 ? <GalleryEnhancer rootId="pdp-gallery" /> : null}
        </div>
    );
}
