/** The taka sign — a currency symbol, not translatable copy. */
export const TAKA = '৳';

/**
 * "24380.00" → "24,380", "1050.50" → "1,050.5". Groups and trims the server's decimal
 * string; never parses it into a float, never computes (rule 1).
 */
export function formatMoney(decimal: string): string {
    const negative = decimal.startsWith('-');
    const [whole = '', fraction = ''] = (negative ? decimal.slice(1) : decimal).split('.');
    const trimmed = fraction.replace(/0+$/, '');
    return `${negative ? '−' : ''}${whole.replace(/\B(?=(\d{3})+(?!\d))/g, ',')}${trimmed ? `.${trimmed}` : ''}`;
}

/** "৳24,380" as plain text, for sentences and labels. */
export const takaText = (decimal: string): string => `${TAKA}${formatMoney(decimal)}`;

/** A taka amount as Figma sets it: the sign in the currency face, figures in the surrounding style. */
export function Taka({ amount, signClass = 't-admin-currency-num' }: { amount: string; signClass?: string }) {
    return (
        <span className="tabular whitespace-nowrap">
            <span className={signClass}>{TAKA}</span>
            {formatMoney(amount)}
        </span>
    );
}

/** Western digits with grouping in both locales (rule 36). */
export const count = (n: number): string => new Intl.NumberFormat('en-US').format(n);

/** A server percentage string ("66.67") as "66.67%"; trailing zeros dropped ("100.00" → "100%"). */
export function percentText(value: string): string {
    const [whole = '0', fraction = ''] = value.split('.');
    const trimmed = fraction.replace(/0+$/, '');
    return `${whole}${trimmed ? `.${trimmed}` : ''}%`;
}

const tag = (locale: string) => (locale === 'bn' ? 'bn-BD' : 'en-GB');
const latn = { timeZone: 'Asia/Dhaka', numberingSystem: 'latn' } as const;

/** "02 Nov" — a Dhaka day, Western digits (rules 11, 36). */
export const dhakaDay = (iso: string, locale: string): string => new Intl.DateTimeFormat(tag(locale), { ...latn, day: '2-digit', month: 'short' }).format(new Date(iso));

/** "12 Feb 2026". */
export const dhakaDayYear = (iso: string, locale: string): string => new Intl.DateTimeFormat(tag(locale), { ...latn, day: '2-digit', month: 'short', year: 'numeric' }).format(new Date(iso));

/** "04 Nov, 9:27 am". */
export const dhakaDayTime = (iso: string, locale: string): string =>
    new Intl.DateTimeFormat(tag(locale), { ...latn, day: '2-digit', month: 'short', hour: 'numeric', minute: '2-digit', hour12: true }).format(new Date(iso));

/** "2026-10" → "Oct 2026" (a calendar month the server already bucketed in Dhaka time). */
export const monthLabel = (month: string, locale: string): string =>
    new Intl.DateTimeFormat(tag(locale), { timeZone: 'UTC', numberingSystem: 'latn', month: 'short', year: 'numeric' }).format(new Date(`${month}-01T00:00:00Z`));

/** "04 Nov 2026, 9:27 am" — a Dhaka moment with its year (consent provenance). */
export const dhakaMoment = (iso: string, locale: string): string =>
    new Intl.DateTimeFormat(tag(locale), { ...latn, day: '2-digit', month: 'short', year: 'numeric', hour: 'numeric', minute: '2-digit', hour12: true }).format(new Date(iso));

/** "1990-05-04" → "04 May 1990": a calendar date with no time or zone (a birth date), never shifted. */
export const calendarDay = (date: string, locale: string): string =>
    new Intl.DateTimeFormat(tag(locale), { timeZone: 'UTC', numberingSystem: 'latn', day: '2-digit', month: 'short', year: 'numeric' }).format(new Date(`${date}T00:00:00Z`));

/** A Dhaka calendar date (YYYY-MM-DD) `daysBack` days before today — filters are Dhaka dates (rule 11). */
export function dhakaDate(daysBack: number, now: Date = new Date()): string {
    const today = new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Dhaka', year: 'numeric', month: '2-digit', day: '2-digit' }).format(now);
    const day = new Date(`${today}T00:00:00Z`);
    day.setUTCDate(day.getUTCDate() - daysBack);
    return day.toISOString().slice(0, 10);
}

/** Two initials from a name; works for Latin and Bangla names. */
export function initials(name: string): string {
    const parts = name.trim().split(/\s+/).filter(Boolean);
    const letters = parts.length > 1 ? [parts[0], parts[parts.length - 1]] : parts;
    return letters.map((part) => Array.from(part ?? '')[0] ?? '').join('').toUpperCase();
}
