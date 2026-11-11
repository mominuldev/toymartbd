import { LOCALE_COOKIE, type Locale } from './i18n/config';

// Client-safe helpers for the visitor's explicit language choice. One year, whole site, Lax.
export function rememberLocale(locale: Locale): void {
    document.cookie = `${LOCALE_COOKIE}=${locale}; Path=/; Max-Age=31536000; SameSite=Lax`;
}

export function rememberedLocale(): string | null {
    const match = document.cookie.split('; ').find((part) => part.startsWith(`${LOCALE_COOKIE}=`));
    return match ? decodeURIComponent(match.slice(LOCALE_COOKIE.length + 1)) : null;
}
