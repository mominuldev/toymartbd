import { format, type Messages } from '../i18n';
import { ageLabel } from '../listing/format';

// Display formatting for the product page. Nothing here decides a price, a charge or a stock
// state (rule 1): the API sends those; this turns them into words.

type Pdp = Messages['pdp'];

/** "For ages 4–12" (AgeRangeIndicator md, §A11) — the listing's derivation with PDP templates. */
export function ageForLabel(min: number | null, max: number | null, t: Messages): string | null {
    return ageLabel(min, max, {
        ...t.listing,
        ageYears: t.pdp.ageFor,
        ageYearsPlus: t.pdp.ageForPlus,
        ageUpTo: t.pdp.ageForUpTo,
        ageMonths: t.pdp.ageForMonths,
        ageMonthsPlus: t.pdp.ageForMonthsPlus,
    });
}

/** "4–12" — the bare range for "Best for ages {range}" and the accordion subtitle. */
export function ageRange(min: number | null, max: number | null, t: Messages): string | null {
    return ageLabel(min, max, { ...t.listing, ageYears: '{min}–{max}', ageYearsPlus: '{min}+', ageUpTo: '0–{max}' });
}

/** The six bands of the Age & safety scale (Figma 157:2248), in months: [from, to). */
export const AGE_SCALE = [
    { key: 'b0', from: 0, to: 12 },
    { key: 'b1', from: 12, to: 36 },
    { key: 'b2', from: 36, to: 60 },
    { key: 'b3', from: 60, to: 96 },
    { key: 'b4', from: 96, to: 144 },
    { key: 'b5', from: 144, to: null },
] as const satisfies ReadonlyArray<{ key: keyof Pdp['ageBands']; from: number; to: number | null }>;

/**
 * Whether a band overlaps the product's age range (null = open). Range ends are band edges, so
 * "4–12 years" (48–144 months) lights 3–5, 5–8 and 8–12 but not 12+ (Figma 157:2248).
 */
export function bandMatches(band: { from: number; to: number | null }, min: number | null, max: number | null): boolean {
    if (min === null && max === null) return false;
    const lo = min ?? 0;
    const hi = max ?? Number.POSITIVE_INFINITY;
    return lo < (band.to ?? Number.POSITIVE_INFINITY) && hi > band.from;
}

/** "1.45 kg" / "450 g" from grams (Western digits, rule 36). */
export function weightLabel(grams: number, t: Messages): string | null {
    if (grams <= 0) return null;
    if (grams < 1000) return format(t.pdp.weightG, { g: String(grams) });
    const kg = (grams / 1000).toFixed(2).replace(/\.?0+$/, '');
    return format(t.pdp.weightKg, { kg });
}

/** "5 pm" / "বিকেল 5টা" from the API's "HH:MM" cut-off. Null on a malformed value. */
export function cutoffLabel(time: string, t: Messages): string | null {
    const match = /^(\d{1,2}):(\d{2})/.exec(time);
    if (!match) return null;
    const hour = Number(match[1]);
    const minute = Number(match[2]);
    if (hour > 23 || minute > 59) return null;
    const h12 = hour % 12 === 0 ? 12 : hour % 12;
    const h = minute === 0 ? String(h12) : `${h12}:${match[2]}`;
    return format(hour < 12 ? t.pdp.timeAm : t.pdp.timePm, { h });
}

/** Hours and minutes now in Asia/Dhaka. */
export function dhakaClock(now: Date): { hour: number; minute: number } {
    const parts = new Intl.DateTimeFormat('en-GB', { timeZone: 'Asia/Dhaka', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' }).formatToParts(now);
    const pick = (type: string): number => Number(parts.find((p) => p.type === type)?.value ?? '0');
    return { hour: pick('hour'), minute: pick('minute') };
}

/** Whether `now` is before the "HH:MM" Dhaka cut-off (the StockIndicator md caption, §A13). */
export function beforeCutoff(time: string, now: Date): boolean {
    const match = /^(\d{1,2}):(\d{2})/.exec(time);
    if (!match) return false;
    const { hour, minute } = dhakaClock(now);
    return hour * 60 + minute < Number(match[1]) * 60 + Number(match[2]);
}

/** Rating stars quantised to the nearest half (Figma Rating 69:654): 4.3 → 4.5, 4.24 → 4.0. */
export function starFills(rating: number): Array<0 | 50 | 100> {
    const half = Math.round(rating * 2) / 2;
    return [1, 2, 3, 4, 5].map((n) => (half >= n ? 100 : half >= n - 0.5 ? 50 : 0));
}

/** "4.7" — always one decimal (Figma Rating: never "4"). */
export function ratingValue(avg: string): string {
    const n = Number(avg);
    return Number.isFinite(n) ? n.toFixed(1) : avg;
}
