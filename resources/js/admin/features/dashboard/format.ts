/**
 * Display-only formatting for the dashboard. Every figure arrives from the server (rule 1):
 * these functions group digits and pick a sign, they never compute money.
 */

/** The taka sign — a currency symbol, not translatable copy. */
export const TAKA = '৳';
/** A true minus (U+2212), as the admin spec sets money (04-admin-spec Part 0). */
export const MINUS = '−';

/** "13910.00" → "13,910"; "-602.50" → "-602.5". Groups the server's decimal string without parsing it into a float. */
export function groupDecimal(decimal: string): string {
    const negative = decimal.startsWith('-');
    const [whole = '', fraction = ''] = (negative ? decimal.slice(1) : decimal).split('.');
    const grouped = whole.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    const trimmed = fraction.replace(/0+$/, '');
    return `${negative ? '-' : ''}${grouped}${trimmed ? `.${trimmed}` : ''}`;
}

export function isNegative(decimal: string): boolean {
    return decimal.startsWith('-') && !/^-0(\.0+)?$/.test(decimal);
}

/** "-602.50" → "−৳602.5" as plain text (labels, aria). */
export function moneyText(decimal: string): string {
    const negative = isNegative(decimal);
    return `${negative ? MINUS : ''}${TAKA}${groupDecimal(decimal.replace(/^-/, ''))}`;
}

/** Western digits with grouping in both locales (rule 36). */
export function formatCount(value: number): string {
    return new Intl.NumberFormat('en-US').format(value);
}

/** A server percent string ("37.78") as Figma sets a delta, to one place: "+37.8%", "−4%". */
export function deltaText(percent: string): string {
    const rounded = onePlace(percent);
    const negative = rounded.startsWith(MINUS);
    const digits = rounded.replace(MINUS, '').replace(/\.0$/, '');
    return `${negative ? MINUS : digits === '0' ? '' : '+'}${digits}%`;
}

/** A server percent ("5.15") to one place, half away from zero, as the spec's margins ("5.2"). String arithmetic, no float. */
export function onePlace(percent: string): string {
    const negative = isNegative(percent);
    const [whole = '0', fraction = ''] = percent.replace(/^-/, '').split('.');
    const digits = (fraction + '00').slice(0, 2);
    const tenths = Number(whole) * 10 + Number(digits[0]) + (Number(digits[1]) >= 5 ? 1 : 0);
    return `${negative && tenths !== 0 ? MINUS : ''}${Math.floor(tenths / 10)}.${tenths % 10}`;
}

/** A chart axis label: 9000 → "9k". Presentation of an axis, not a figure. */
export function compact(value: number): string {
    const abs = Math.abs(value);
    const sign = value < 0 ? MINUS : '';
    if (abs >= 1_000_000) return `${sign}${trim(abs / 1_000_000)}m`;
    if (abs >= 1_000) return `${sign}${trim(abs / 1_000)}k`;
    return `${sign}${trim(abs)}`;
}

function trim(value: number): string {
    return Number.isInteger(value) ? String(value) : value.toFixed(1).replace(/\.0$/, '');
}

const dayFormat = (locale: string, withMonth: boolean, withYear = false) =>
    new Intl.DateTimeFormat(locale === 'bn' ? 'bn-BD' : 'en-GB', { day: '2-digit', ...(withMonth ? { month: 'short' } : {}), ...(withYear ? { year: 'numeric' } : {}), timeZone: 'UTC', numberingSystem: 'latn' });

const date = (ymd: string) => new Date(`${ymd}T00:00:00Z`);

/** "02 Nov" in the UI language, Western digits. */
export function shortDay(ymd: string, locale: string): string {
    return dayFormat(locale, true).format(date(ymd));
}

/** "02–08 Nov", "28 Oct – 03 Nov", or "05 Nov" for one day. */
export function rangeText(from: string, to: string, locale: string, withYear = false): string {
    if (from === to) return dayFormat(locale, true, withYear).format(date(from));
    if (from.slice(0, 7) === to.slice(0, 7)) return `${dayFormat(locale, false).format(date(from))}–${dayFormat(locale, true, withYear).format(date(to))}`;
    return `${dayFormat(locale, true).format(date(from))} – ${dayFormat(locale, true, withYear).format(date(to))}`;
}

/** The current hour in Dhaka, for the greeting only. */
export function dhakaHour(now: Date = new Date()): number {
    return Number(new Intl.DateTimeFormat('en-GB', { timeZone: 'Asia/Dhaka', hour: '2-digit', hourCycle: 'h23' }).format(now));
}

/** "10:04 am"-style Dhaka time for the activity feed. */
export function dhakaTime(iso: string, locale: string): string {
    return new Intl.DateTimeFormat(locale === 'bn' ? 'bn-BD' : 'en-GB', { timeZone: 'Asia/Dhaka', day: '2-digit', month: 'short', hour: 'numeric', minute: '2-digit', hour12: true, numberingSystem: 'latn' }).format(new Date(iso));
}

/** Whole minutes between an ISO instant and now — the age of a waiting queue item. */
export function minutesSince(iso: string, now: number = Date.now()): number {
    return Math.max(0, Math.floor((now - new Date(iso).getTime()) / 60_000));
}
