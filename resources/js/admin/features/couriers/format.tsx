/*
 * Display helpers for courier screens. They group and trim the server's decimal strings;
 * they never parse money into a float or compute anything (rule 1).
 */

import { TakaAmount } from '@/components/ui/TakaAmount';
import { TAKA, formatCount, formatMoney, isZeroAmount, takaText } from '@/lib/money';

/* Money: the admin's one formatter (lib/money — lakh grouping, owner decision 2026-11-16). */
export { TAKA, formatMoney, isZeroAmount, takaText };

/** The gram unit symbol (SI, not translatable copy). */
export const GRAM = 'g';

/** Figma sets the taka sign in the currency face, the figures in the surrounding style. */
export function Taka({ amount, signClass = 't-admin-currency-num' }: { amount: string; signClass?: string }) {
    return <TakaAmount amount={amount} signClass={signClass} />;
}

/** "1,200 g" — grams with Western digits (rule 36). */
export function grams(value: number): string {
    return `${formatCount(value)} ${GRAM}`;
}

/** Whole kilograms of a gram allowance, for "over 1 kg" copy: 1000 → "1", 1500 → "1.5". */
export function kilos(gramsValue: number): string {
    return (gramsValue / 1000).toLocaleString('en-US', { maximumFractionDigits: 1 });
}

const tag = (locale: string) => (locale === 'bn' ? 'bn-BD' : 'en-GB');

/** Dhaka day and time, Western digits in both locales (rules 11, 36). */
export function dhaka(iso: string, locale: string): { day: string; dayYear: string; time: string } {
    const date = new Date(iso);
    const base = { timeZone: 'Asia/Dhaka', numberingSystem: 'latn' } as const;
    return {
        day: new Intl.DateTimeFormat(tag(locale), { ...base, day: '2-digit', month: 'short' }).format(date),
        dayYear: new Intl.DateTimeFormat(tag(locale), { ...base, day: '2-digit', month: 'short', year: 'numeric' }).format(date),
        time: new Intl.DateTimeFormat(tag(locale), { ...base, hour: 'numeric', minute: '2-digit', hour12: true }).format(date).toLowerCase(),
    };
}

/** Elapsed whole hours and minutes since an instant — a display of the clock, not money. */
export function elapsed(iso: string, now: Date = new Date()): { h: number; m: number; minutes: number } {
    const minutes = Math.max(0, Math.floor((now.getTime() - new Date(iso).getTime()) / 60000));
    return { h: Math.floor(minutes / 60), m: minutes % 60, minutes };
}
