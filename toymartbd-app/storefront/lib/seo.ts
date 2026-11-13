import type { Metadata } from 'next';

import { localizedPath, type Locale } from './i18n/config';
import { hreflangFor } from './seo/metadata';

export { siteUrl } from './seo/site';

/**
 * Canonical + reciprocal hreflang alternates for a locale-independent `path` (rule 35).
 * Kept for existing callers; new code uses the per-template builders in lib/seo/metadata.ts.
 */
export function alternatesFor(locale: Locale, path: string): Metadata['alternates'] {
    return { canonical: localizedPath(locale, path), languages: hreflangFor(path) };
}
