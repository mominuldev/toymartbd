import type { Metadata } from 'next';

import { htmlLang, localizedPath, locales, type Locale } from '../i18n/config';
import { hrefFor, indexing, type ListingState } from './state';

/**
 * Canonical, hreflang and robots for a listing URL (storefront spec §A7.5, §A8). The bare listing
 * and single-value, single-facet views are indexable and self-canonical (hreflang carries the same
 * query in both locales). Everything else is `noindex, follow`, canonical to the bare listing.
 */
export function listingMetadata(locale: Locale, path: string, state: ListingState): Pick<Metadata, 'alternates' | 'robots'> {
    const { indexable, canonical } = indexing(state);
    const languages: Record<string, string> = {};
    for (const l of locales) languages[htmlLang[l]] = hrefFor(localizedPath(l, path), canonical);
    languages['x-default'] = hrefFor(localizedPath('en', path), canonical);
    return {
        alternates: { canonical: hrefFor(localizedPath(locale, path), canonical), languages },
        robots: indexable ? undefined : { index: false, follow: true },
    };
}
