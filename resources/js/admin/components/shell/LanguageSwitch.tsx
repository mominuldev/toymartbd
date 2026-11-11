import { useI18n, type Locale } from '@/i18n/I18nProvider';

const options: { value: Locale; label: string; lang: string }[] = [
    { value: 'en', label: 'EN', lang: 'en' },
    { value: 'bn', label: 'বাং', lang: 'bn' },
];

/**
 * EN | বাং switch (Figma Topbar 187:474, "Language"): bg/subtle track, 2 padding and gap,
 * radius/sm; items 8×4 padding, radius/xs, button-sm; the current language sits on
 * bg/surface in text/primary, the other in text/secondary. Each label is in its own language.
 */
export function LanguageSwitch() {
    const { locale, setLocale, t } = useI18n();

    return (
        <div role="group" aria-label={t('shell.language')} className="flex items-center gap-0.5 rounded-sm bg-subtle p-0.5">
            {options.map((option) => (
                <button
                    key={option.value}
                    type="button"
                    lang={option.lang}
                    aria-pressed={locale === option.value}
                    onClick={() => setLocale(option.value)}
                    className="rounded-xs px-2 py-1 t-admin-button-sm text-fg-secondary hover:text-fg-primary aria-pressed:bg-surface aria-pressed:text-fg-primary"
                >
                    {option.label}
                </button>
            ))}
        </div>
    );
}
