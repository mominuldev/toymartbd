/**
 * Schedules are entered and read in Dhaka time (UTC+6, no daylight saving) and sent to the API
 * as ISO 8601 with an explicit +06:00 offset; the API stores UTC.
 */
const DHAKA_OFFSET_MS = 6 * 60 * 60 * 1000;

/** UTC ISO → the `YYYY-MM-DDTHH:mm` a datetime-local input shows, in Dhaka time. */
export function toDhakaInput(iso: string | null | undefined): string {
    if (!iso) return '';
    const ms = Date.parse(iso);
    if (Number.isNaN(ms)) return '';
    return new Date(ms + DHAKA_OFFSET_MS).toISOString().slice(0, 16);
}

/** A datetime-local value (Dhaka) → `YYYY-MM-DDTHH:mm:00+06:00`, or null when empty. */
export function fromDhakaInput(value: string): string | null {
    const trimmed = value.trim();
    if (trimmed === '') return null;
    const base = trimmed.length === 16 ? `${trimmed}:00` : trimmed.slice(0, 19);
    return `${base}+06:00`;
}

/** "15 Dec 2026, 18:00" in Dhaka time, Western digits in both locales (rule 36). */
export function formatDhakaDateTime(iso: string, locale: string): string {
    return new Intl.DateTimeFormat(locale === 'bn' ? 'bn-BD' : 'en-GB', {
        timeZone: 'Asia/Dhaka',
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        hourCycle: 'h23',
        numberingSystem: 'latn',
    }).format(new Date(iso));
}

/** "08 Nov" in Dhaka time. */
export function formatDhakaDayMonth(iso: string, locale: string): string {
    return new Intl.DateTimeFormat(locale === 'bn' ? 'bn-BD' : 'en-GB', {
        timeZone: 'Asia/Dhaka',
        day: '2-digit',
        month: 'short',
        numberingSystem: 'latn',
    }).format(new Date(iso));
}

/** Compare two schedule inputs (both Dhaka): true when `end` is not after `start`. */
export function endsBeforeStart(start: string, end: string): boolean {
    return start.trim() !== '' && end.trim() !== '' && end <= start;
}
