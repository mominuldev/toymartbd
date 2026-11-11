/** Dhaka-time display helpers. Western digits in both locales (rule 36); timezone from rule 11. */
export function formatDhakaDate(iso: string, locale: string): string {
    return new Intl.DateTimeFormat(locale === 'bn' ? 'bn-BD' : 'en-GB', {
        timeZone: 'Asia/Dhaka',
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        numberingSystem: 'latn',
    }).format(new Date(iso));
}
