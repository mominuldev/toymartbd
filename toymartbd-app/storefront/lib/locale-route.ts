import { defaultLocale, locales } from './i18n/config';

// Pure routing decision for proxy.ts, unit-tested in tests/locale-route.test.ts.
//
//   /bn, /bn/...  → served as-is by app/[locale] (locale = bn)
//   /en, /en/...  → 308 to the unprefixed URL. English has exactly one public URL; nothing links
//                   to /en, so this only canonicalises hand-typed URLs. It is NOT locale negotiation.
//   anything else → internal REWRITE to /en/... (no redirect: the URL stays `/brands`).
//
// Accept-Language never changes the route — Bangla is only suggested (components/LocaleSuggestion).

/** Set on the internal rewrite so proxy.ts can recognise its own second pass. */
export const LOCALE_REWRITE_HEADER = 'x-tm-locale-rewrite';

export type LocaleRoute =
    | { action: 'next' }
    | { action: 'rewrite'; pathname: string }
    | { action: 'redirect'; pathname: string };

function prefixOf(pathname: string, locale: string): boolean {
    return pathname === `/${locale}` || pathname.startsWith(`/${locale}/`);
}

export function resolveLocaleRoute(pathname: string): LocaleRoute {
    for (const locale of locales) {
        if (locale === defaultLocale) continue;
        if (prefixOf(pathname, locale)) return { action: 'next' };
    }
    if (prefixOf(pathname, defaultLocale)) {
        const rest = pathname.slice(defaultLocale.length + 1);
        return { action: 'redirect', pathname: rest === '' ? '/' : rest };
    }
    return { action: 'rewrite', pathname: pathname === '/' ? `/${defaultLocale}` : `/${defaultLocale}${pathname}` };
}
