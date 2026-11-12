import type { MessageKey } from '@/i18n/messages';

/** The taka sign — a currency symbol, not translatable copy. */
export const TAKA = '৳';

/**
 * "1250.00" → "1,250", "1050.50" → "1,050.5". Groups and trims the server's decimal string;
 * never parses it into a float, never computes (rule 1).
 */
export function formatMoney(decimal: string): string {
    const negative = decimal.startsWith('-');
    const [whole = '', fraction = ''] = (negative ? decimal.slice(1) : decimal).split('.');
    const grouped = whole.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    const trimmed = fraction.replace(/0+$/, '');
    return `${negative ? '−' : ''}${grouped}${trimmed ? `.${trimmed}` : ''}`;
}

/** True for "0", "0.00", "-0.00" — a display decision on the server's string, not arithmetic. */
export function isZeroAmount(decimal: string): boolean {
    return /^-?0*(\.0*)?$/.test(decimal);
}

/** A taka amount as Figma sets it: the sign in the currency face, figures in the surrounding style. */
export function Taka({ amount, signClass = 't-admin-currency-num' }: { amount: string; signClass?: string }) {
    return (
        <>
            <span className={signClass}>{TAKA}</span>
            {formatMoney(amount)}
        </>
    );
}

/** "৳1,250" as plain text, for sentences and aria labels. */
export function takaText(amount: string): string {
    return `${TAKA}${formatMoney(amount)}`;
}

const tag = (locale: string) => (locale === 'bn' ? 'bn-BD' : 'en-GB');

/** Dhaka day and time, Western digits in both locales (rules 11, 36). */
export function dhakaParts(iso: string, locale: string): { day: string; dayYear: string; time: string; dayKey: string } {
    const date = new Date(iso);
    const base = { timeZone: 'Asia/Dhaka', numberingSystem: 'latn' } as const;
    return {
        day: new Intl.DateTimeFormat(tag(locale), { ...base, day: '2-digit', month: 'short' }).format(date),
        dayYear: new Intl.DateTimeFormat(tag(locale), { ...base, day: '2-digit', month: 'short', year: 'numeric' }).format(date),
        time: new Intl.DateTimeFormat(tag(locale), { ...base, hour: 'numeric', minute: '2-digit', hour12: true }).format(date).toLowerCase(),
        dayKey: new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Dhaka', year: 'numeric', month: '2-digit', day: '2-digit' }).format(date),
    };
}

/** "18h ago" / "4h 15m ago" / "12m ago" under a day, or null once it is a day old. */
export function ageLabel(iso: string, now: Date = new Date()): { key: MessageKey; vars: Record<string, number> } | null {
    const minutes = Math.max(0, Math.floor((now.getTime() - new Date(iso).getTime()) / 60000));
    if (minutes >= 24 * 60) return null;
    const hours = Math.floor(minutes / 60);
    const rest = minutes % 60;
    if (hours === 0) return { key: 'orders.age.minutes', vars: { m: rest } };
    if (hours >= 10 || rest === 0) return { key: 'orders.age.hours', vars: { h: hours } };
    return { key: 'orders.age.hoursMinutes', vars: { h: hours, m: rest } };
}

/** A Dhaka calendar date (YYYY-MM-DD) `daysBack` days before today — the API filters by Dhaka day (rule 11). */
export function dhakaDate(daysBack: number, now: Date = new Date()): string {
    const parts = new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Dhaka', year: 'numeric', month: '2-digit', day: '2-digit' }).format(now);
    const day = new Date(`${parts}T00:00:00Z`);
    day.setUTCDate(day.getUTCDate() - daysBack);
    return day.toISOString().slice(0, 10);
}

/** "02–08 Nov" for a date range label. */
export function rangeLabel(from: string, to: string): string {
    const fmt = (iso: string, opts: Intl.DateTimeFormatOptions) => new Intl.DateTimeFormat('en-GB', { timeZone: 'UTC', numberingSystem: 'latn', ...opts }).format(new Date(`${iso}T00:00:00Z`));
    return from === to ? fmt(to, { day: '2-digit', month: 'short' }) : `${fmt(from, { day: '2-digit' })}–${fmt(to, { day: '2-digit', month: 'short' })}`;
}

/** Two initials from a name; works for Latin and Bangla names. */
export function initials(name: string): string {
    const parts = name.trim().split(/\s+/).filter(Boolean);
    const letters = parts.length > 1 ? [parts[0], parts[parts.length - 1]] : parts;
    return letters.map((part) => Array.from(part ?? '')[0] ?? '').join('').toUpperCase();
}
