import type { Metadata } from 'next';

import { htmlLang, localizedPath, locales, type Locale } from './i18n/config';

export function siteUrl(): URL {
    return new URL(process.env.SITE_URL ?? 'http://localhost:3000');
}

/**
 * Canonical + reciprocal hreflang alternates for a locale-independent `path` (rule 35).
 * Every page's generateMetadata spreads this in, so en ↔ bn and x-default (→ en) are always emitted.
 */
export function alternatesFor(locale: Locale, path: string): Metadata['alternates'] {
    const languages: Record<string, string> = {};
    for (const l of locales) languages[htmlLang[l]] = localizedPath(l, path);
    languages['x-default'] = localizedPath('en', path);
    return { canonical: localizedPath(locale, path), languages };
}
