/*
 * Display helpers for the register. They group and trim the server's decimal strings; they
 * never parse money into a number or compute anything (rule 1).
 */

/** The taka sign — a currency symbol, not translatable copy. */
export const TAKA = '৳';

/** "1250.00" → "1,250"; "23.10" → "23.1". Western digits in both locales (rule 36). */
export function formatMoney(decimal: string): string {
    const negative = decimal.startsWith('-');
    const [whole = '', fraction = ''] = (negative ? decimal.slice(1) : decimal).split('.');
    const grouped = whole.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    const trimmed = fraction.replace(/0+$/, '');
    return `${negative ? '−' : ''}${grouped}${trimmed ? `.${trimmed}` : ''}`;
}

export function takaText(amount: string): string {
    const negative = amount.startsWith('-');
    return `${negative ? '−' : ''}${TAKA}${formatMoney(negative ? amount.slice(1) : amount)}`;
}

/** True for "0", "0.00" — a display decision on the server's string. */
export function isZeroAmount(decimal: string): boolean {
    return /^-?0*(\.0*)?$/.test(decimal);
}

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
