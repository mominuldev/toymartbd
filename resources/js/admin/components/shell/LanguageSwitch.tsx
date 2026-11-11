import { useI18n, type Locale } from '@/i18n/I18nProvider';

const options: { value: Locale; label: string; lang: string }[] = [
    { value: 'en', label: 'EN', lang: 'en' },
    { value: 'bn', label: 'বাং', lang: 'bn' },
];

/** EN | বাং segmented switch (Figma Topbar 187:474). Each label is in its own language. */
export function LanguageSwitch() {
    const { locale, setLocale, t } = useI18n();

    return (
        <div role="group" aria-label={t('shell.language')} className="flex h-control-sm items-center rounded-sm bg-subtle p-0.5">
            {options.map((option) => (
                <button
                    key={option.value}
                    type="button"
                    lang={option.lang}
                    aria-pressed={locale === option.value}
                    onClick={() => setLocale(option.value)}
                    className="h-full rounded-xs px-2.5 t-admin-button-sm text-fg-secondary aria-pressed:bg-surface aria-pressed:text-fg-primary aria-pressed:shadow-e1"
                >
                    {option.label}
                </button>
            ))}
        </div>
    );
}
