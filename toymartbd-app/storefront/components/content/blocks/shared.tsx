import type { ReactNode } from 'react';

import type { BlockImage } from '@/lib/content/home-blocks';
import { localizedPath, type Locale } from '@/lib/i18n';

// Pieces shared by the CMS block components (Phase 7).

/** One content section inside the gutters and the 1280 cap (the home page's Band). */
export function Band({ children, className = '' }: { children: ReactNode; className?: string }) {
    return (
        <div className={`px-gutter-mobile md:px-gutter-tablet lg:px-gutter-desktop ${className}`}>
            <div className="mx-auto max-w-content">{children}</div>
        </div>
    );
}

/** A block's link: a storefront path gets the locale prefix; an https URL leaves the site. */
export function blockLink(locale: Locale, href: string): { href: string; external: boolean } {
    return href.startsWith('/') ? { href: localizedPath(locale, href), external: false } : { href, external: true };
}

/** `lang-bn` (rule 38: Bangla line-height) on Bangla copy. */
export const bnClass = (locale: Locale): string => (locale === 'bn' ? 'lang-bn' : '');

/**
 * A media-library image: AVIF and WebP renditions with the intrinsic size set, so the box is
 * reserved before it loads (CLS budget, rule 29). Lazy unless it is the page's LCP element.
 */
export function MediaPicture({ image, sizes, className = '', priority = false }: { image: BlockImage; sizes: string; className?: string; priority?: boolean }) {
    return (
        <picture>
            {image.srcset_avif ? <source type="image/avif" srcSet={image.srcset_avif} sizes={sizes} /> : null}
            {image.srcset_webp ? <source type="image/webp" srcSet={image.srcset_webp} sizes={sizes} /> : null}
            <img
                src={image.url}
                alt={image.alt}
                width={image.width ?? undefined}
                height={image.height ?? undefined}
                loading={priority ? 'eager' : 'lazy'}
                fetchPriority={priority ? 'high' : undefined}
                decoding={priority ? 'sync' : 'async'}
                className={className}
            />
        </picture>
    );
}
