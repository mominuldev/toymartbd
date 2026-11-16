/*
 * Display helpers for the booking queue. They group the server's decimal strings and format
 * Dhaka times; they never parse money into a float or compute anything (rule 1). Western
 * digits in both locales (rule 36).
 */

import { TAKA, formatCount, takaText } from '@/lib/money';

/** The taka sign (lib/money) and the gram unit — symbols, not translatable copy. */
export { TAKA };
export const GRAM = 'g';

/** "1250.00" → "৳1,250"; "23.10" → "৳23.1" — lakh-grouped (lib/money). */
export const taka = (decimal: string): string => takaText(decimal);

/** "1,200 g". */
export function grams(value: number): string {
    return `${formatCount(value)} ${GRAM}`;
}

const tag = (locale: string) => (locale === 'bn' ? 'bn-BD' : 'en-GB');

/** Dhaka day ("06 Nov") and time ("1:40 pm"). */
export function dhakaTime(iso: string, locale: string): { day: string; time: string } {
    const date = new Date(iso);
    const base = { timeZone: 'Asia/Dhaka', numberingSystem: 'latn' } as const;
    return {
        day: new Intl.DateTimeFormat(tag(locale), { ...base, day: '2-digit', month: 'short' }).format(date),
        time: new Intl.DateTimeFormat(tag(locale), { ...base, hour: 'numeric', minute: '2-digit', hour12: true }).format(date).toLowerCase(),
    };
}

/** "16:00" (the stored Dhaka cut-off) → "4:00 pm". A wall-clock time, so no timezone shift. */
export function wallClock(hhmm: string, locale: string): string {
    const [h = 0, m = 0] = hhmm.split(':').map(Number);
    const date = new Date(Date.UTC(2000, 0, 1, h, m));
    return new Intl.DateTimeFormat(tag(locale), { timeZone: 'UTC', hour: 'numeric', minute: '2-digit', hour12: true, numberingSystem: 'latn' }).format(date).toLowerCase();
}
