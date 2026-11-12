import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react';
import { bn, en, type MessageKey, type Messages } from './messages';
import { setApiLocale } from '@/lib/api';
import { configureZodErrors } from './zodErrors';

export type Locale = 'en' | 'bn';

const STORAGE_KEY = 'tm-admin-locale';
const catalogs: Record<Locale, Messages> = { en, bn };

type Vars = Record<string, string | number>;

type I18nValue = {
    locale: Locale;
    setLocale: (locale: Locale) => void;
    t: (key: MessageKey, vars?: Vars) => string;
};

const I18nContext = createContext<I18nValue | null>(null);

function storedLocale(): Locale {
    try {
        const value = localStorage.getItem(STORAGE_KEY);
        return value === 'bn' ? 'bn' : 'en';
    } catch {
        return 'en';
    }
}

export function translate(locale: Locale, key: MessageKey, vars?: Vars): string {
    // A dynamic key (`orders.status.${s}` as MessageKey) escapes the type check. In dev and
    // tests a key missing from either catalog throws, so CI fails instead of a screen showing
    // a raw key or nothing (P4-I18N-01; scripts/i18n/check-keys.mjs covers the static side).
    // A production build falls back to English rather than breaking the screen.
    if (import.meta.env.DEV) {
        const missing = (['en', 'bn'] as const).filter((l) => typeof catalogs[l][key] !== 'string');
        if (missing.length > 0) throw new Error(`Missing i18n key "${key}" in ${missing.join(' and ')}`);
    }
    const template: string = catalogs[locale][key] ?? catalogs.en[key] ?? '';
    if (!vars) return template;
    return template.replace(/\{(\w+)\}/g, (match, name: string) =>
        name in vars ? String(vars[name]) : match,
    );
}

export function I18nProvider({ children, initialLocale }: { children: ReactNode; initialLocale?: Locale }) {
    const [locale, setLocaleState] = useState<Locale>(() => initialLocale ?? storedLocale());

    useEffect(() => {
        // <html lang> drives the :lang(bn) type twins in tokens.css (rule 38) and
        // screen-reader pronunciation; the API gets the same locale on every call.
        document.documentElement.lang = locale;
        setApiLocale(locale);
        configureZodErrors(locale);
    }, [locale]);

    const setLocale = useCallback((next: Locale) => {
        setLocaleState(next);
        try {
            localStorage.setItem(STORAGE_KEY, next);
        } catch {
            // Private mode: the choice lasts for this tab only.
        }
    }, []);

    const value = useMemo<I18nValue>(
        () => ({ locale, setLocale, t: (key, vars) => translate(locale, key, vars) }),
        [locale, setLocale],
    );

    return <I18nContext.Provider value={value}>{children}</I18nContext.Provider>;
}

export function useI18n(): I18nValue {
    const value = useContext(I18nContext);
    if (!value) throw new Error('useI18n must be used inside <I18nProvider>');
    return value;
}
