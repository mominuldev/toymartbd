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

/** "12 sec ago", "3 days ago" — Western digits in both locales (rule 36). */
export function timeAgo(iso: string, locale: string, now: number = Date.now()): string {
    const seconds = Math.round((new Date(iso).getTime() - now) / 1000);
    const format = new Intl.RelativeTimeFormat(`${locale === 'bn' ? 'bn-BD' : 'en-GB'}-u-nu-latn`, { numeric: 'auto', style: 'short' });
    const abs = Math.abs(seconds);
    if (abs < 60) return format.format(seconds, 'second');
    if (abs < 3600) return format.format(Math.round(seconds / 60), 'minute');
    if (abs < 86400) return format.format(Math.round(seconds / 3600), 'hour');
    return format.format(Math.round(seconds / 86400), 'day');
}
