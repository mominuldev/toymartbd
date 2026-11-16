/*
 * Display helpers for the register. They group and trim the server's decimal strings; they
 * never parse money into a number or compute anything (rule 1).
 */

import { TAKA, formatMoney, isZeroAmount, takaText } from '@/lib/money';

/* Money: the admin's one formatter (lib/money — lakh grouping, owner decision 2026-11-16). */
export { TAKA, formatMoney, isZeroAmount, takaText };

/** A typed money amount the API accepts ("4000", "4000.5"); anything else is not sent. */
export function isMoneyInput(value: string): boolean {
    return /^\d{1,12}(\.\d{1,2})?$/.test(value.trim());
}

/** "10:02 am" in Dhaka time, Western digits (rules 11, 36). */
export function dhakaTime(iso: string, locale: string): string {
    return new Intl.DateTimeFormat(locale === 'bn' ? 'bn-BD' : 'en-GB', { timeZone: 'Asia/Dhaka', hour: 'numeric', minute: '2-digit', hour12: true, numberingSystem: 'latn' })
        .format(new Date(iso))
        .toLowerCase();
}

/** "15 Nov 2026" — the Dhaka calendar date (rule 11), Western digits (rule 36). */
export function dhakaDate(iso: string, locale: string): string {
    return new Intl.DateTimeFormat(locale === 'bn' ? 'bn-BD' : 'en-GB', { timeZone: 'Asia/Dhaka', day: 'numeric', month: 'short', year: 'numeric', numberingSystem: 'latn' }).format(new Date(iso));
}

/** "15 Nov 2026, 10:02 am" in Dhaka time — the receipt's sold-at line. */
export function dhakaDateTime(iso: string, locale: string): string {
    return new Intl.DateTimeFormat(locale === 'bn' ? 'bn-BD' : 'en-GB', {
        timeZone: 'Asia/Dhaka',
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
        hour12: true,
        numberingSystem: 'latn',
    }).format(new Date(iso));
}

/** "01819 552 310" — how a cashier reads a number back. Display only. */
export function phoneDisplay(national: string): string {
    return national.length === 11 ? `${national.slice(0, 5)} ${national.slice(5, 8)} ${national.slice(8)}` : national;
}
