// Locale routing contract (CLAUDE.md rules 35–37):
// English is served at the root (`/brands`), Bangla under `/bn` (`/bn/brands`).
// Internally every page lives under `app/[locale]`; proxy.ts rewrites `/x` → `/en/x`
// without a redirect. Slugs are ASCII and shared, so the same path works in both locales.

export const locales = ['en', 'bn'] as const;
export type Locale = (typeof locales)[number];
export const defaultLocale: Locale = 'en';

/** Cookie holding the visitor's explicit language choice. Read by the suggestion banner only. */
export const LOCALE_COOKIE = 'tm_locale';

export function isLocale(value: string): value is Locale {
    return (locales as readonly string[]).includes(value);
}

/** BCP 47 tag for `<html lang>` and `hreflang`. */
export const htmlLang: Record<Locale, string> = { en: 'en', bn: 'bn' };

/**
 * Public URL path of a page in a locale. `path` is locale-independent and starts with `/`
 * (`/`, `/brands`, `/brands/lego`).
 */
export function localizedPath(locale: Locale, path: string): string {
    const clean = path === '' ? '/' : path.startsWith('/') ? path : `/${path}`;
    if (locale === defaultLocale) return clean;
    return clean === '/' ? `/${locale}` : `/${locale}${clean}`;
}

export function otherLocale(locale: Locale): Locale {
    return locale === 'en' ? 'bn' : 'en';
}
