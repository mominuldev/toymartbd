import { TrendDownIcon, TrendUpIcon } from '@phosphor-icons/react';
import type { ReactNode } from 'react';

/** The taka sign — a currency symbol, not translatable copy. */
export const TAKA = '৳';
/** A true minus (U+2212), as the admin spec sets money (04-admin-spec Part 0). */
export const MINUS = '−';

/** "13910.50" → "13,910.5": groups the server's decimal string, never parses it into a float (rule 1). */
export function formatAmount(decimal: string): string {
    const negative = decimal.startsWith('-');
    const [whole = '', fraction = ''] = (negative ? decimal.slice(1) : decimal).split('.');
    const trimmed = fraction.replace(/0+$/, '');
    return `${negative ? MINUS : ''}${whole.replace(/\B(?=(\d{3})+(?!\d))/g, ',')}${trimmed ? `.${trimmed}` : ''}`;
}

/** Western digits with grouping in both locales (rule 36). */
export const formatCount = (n: number) => new Intl.NumberFormat('en-US').format(n);

export function isNegative(decimal: string): boolean {
    return decimal.startsWith('-') && !/^-0(\.0+)?$/.test(decimal);
}

/** ৳ in the currency face (Hanken has no ৳ — 06-admin-feature-checklist §0); a dash for null. */
export function Money({ amount, signClass = 't-admin-currency-num' }: { amount: string | null | undefined; signClass?: string }) {
    if (amount === null || amount === undefined) return <span className="text-fg-tertiary">—</span>;
    const negative = isNegative(amount);
    return (
        <span className="tabular whitespace-nowrap">
            {negative ? MINUS : ''}
            <span className={signClass}>{TAKA}</span>
            {formatAmount(negative ? amount.slice(1) : amount.replace(/^-/, ''))}
        </span>
    );
}

/** A server percentage string ("41.01") as "41.01%", or a dash when there was nothing to divide by. */
export function Percent({ value }: { value: string | null | undefined }) {
    if (value === null || value === undefined) return <span className="text-fg-tertiary">—</span>;
    return <span className="tabular">{`${isNegative(value) ? MINUS + value.slice(1) : value}%`}</span>;
}

/** "02 Nov 2026" in the UI language, Western digits (rule 36). */
export function formatDay(date: string, locale: string, withYear = true): string {
    return new Intl.DateTimeFormat(locale === 'bn' ? 'bn-BD' : 'en-GB', {
        day: '2-digit',
        month: 'short',
        ...(withYear ? { year: 'numeric' } : {}),
        timeZone: 'UTC',
        numberingSystem: 'latn',
    }).format(new Date(`${date}T00:00:00Z`));
}

/** Today's Dhaka calendar date (rule 11), for date-input bounds only. */
export function dhakaToday(now: Date = new Date()): string {
    return new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Dhaka', year: 'numeric', month: '2-digit', day: '2-digit' }).format(now);
}

/** Figma StatCard delta row (241:30608): trend icon, "+22.4%" (or "+0.03 pts" — the caller passes the translated suffix), caption. */
export function Delta({ value, suffix = '%', caption }: { value: string | null | undefined; suffix?: string; caption?: ReactNode }) {
    if (value === null || value === undefined) {
        return caption ? <p className="t-admin-caption text-fg-tertiary">{caption}</p> : null;
    }
    const down = isNegative(value);
    const Icon = down ? TrendDownIcon : TrendUpIcon;
    const text = `${down ? MINUS : '+'}${value.replace(/^-/, '')}${suffix}`;
    return (
        <p className="flex min-w-0 items-center gap-1">
            <Icon size={14} aria-hidden className={down ? 'shrink-0 text-feedback-danger' : 'shrink-0 text-feedback-success'} />
            <span className="t-admin-caption-strong tabular whitespace-nowrap text-fg-primary">{text}</span>
            {caption && <span className="min-w-0 truncate t-admin-caption text-fg-tertiary">{caption}</span>}
        </p>
    );
}
