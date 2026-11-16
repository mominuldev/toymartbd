/*
 * The admin's one money and count formatter (owner decision 2026-11-16): South Asian (lakh)
 * grouping — ৳1,24,300.00, ৳12,34,56,789.00 — with Western digits 0–9 in both locales
 * (rule 36). Money arrives from the API as a decimal string; it is formatted on its digits
 * and never parsed into a Number (rules 1 and 6), so a figure of any size keeps every poisha.
 */

/** The taka sign — a currency symbol, not translatable copy. */
export const TAKA = '৳';
/** A true minus (U+2212), as the admin spec sets money (04-admin-spec Part 0). */
export const MINUS = '−';

/**
 * How many places a screen shows:
 * - `trim`  — the places as sent, trailing zeros dropped: "1050.50" → "1,050.5", "1250.00" → "1,250".
 * - `auto`  — whole taka drop ".00"; anything else shows at least two places: "602.5" → "602.50".
 * - `fixed` — at least two places, any further places exactly as sent: "1250" → "1,250.00",
 *             "2937.7371" → "2,937.7371".
 * - `round` — exactly two places, rounded half away from zero on the digits: "832.1450" → "832.15".
 */
export type Places = 'trim' | 'auto' | 'fixed' | 'round';

/** "1234567" → "12,34,567": the last three digits, then pairs. Digits only, no sign. */
export function groupLakh(digits: string): string {
    const whole = digits.replace(/^0+(?=\d)/, '') || '0';
    if (whole.length <= 3) return whole;
    const head = whole.slice(0, -3).replace(/\B(?=(\d{2})+(?!\d))/g, ',');
    return `${head},${whole.slice(-3)}`;
}

/** Add one to a string of digits ("0999" → "1000"), for rounding without a float. */
function increment(digits: string): string {
    const out = digits.split('');
    for (let i = out.length - 1; i >= 0; i--) {
        if (out[i] !== '9') {
            out[i] = String(Number(out[i]) + 1);
            return out.join('');
        }
        out[i] = '0';
    }
    return `1${out.join('')}`;
}

/** The sign and the digits of a server decimal ("-1250.5" → negative, "1250", "5"). */
function parts(decimal: string): { negative: boolean; whole: string; fraction: string } {
    const text = decimal.trim();
    const negative = text.startsWith('-');
    const [whole = '', fraction = ''] = text.replace(/^[-+]/, '').split('.');
    return { negative, whole: whole === '' ? '0' : whole, fraction };
}

/** True for "0", "0.00", "-0.00" — a display decision on the server's string, not arithmetic. */
export function isZeroAmount(decimal: string): boolean {
    return /^[-+]?0*(\.0*)?$/.test(decimal.trim());
}

/** True for a negative amount that is not zero ("-0.00" is not negative). */
export function isNegativeAmount(decimal: string): boolean {
    return decimal.trim().startsWith('-') && !isZeroAmount(decimal);
}

/** The figures of an amount without its sign: "-124300.5" → "1,24,300.5" (`trim`). */
export function formatDigits(decimal: string, places: Places = 'trim'): string {
    let { whole, fraction } = parts(decimal);
    let shown: string;
    if (places === 'round') {
        let cents = `${whole}${fraction.padEnd(2, '0').slice(0, 2)}`;
        if ((fraction[2] ?? '0') >= '5') cents = increment(cents);
        whole = cents.slice(0, -2) || '0';
        shown = cents.slice(-2);
    } else {
        const trimmed = fraction.replace(/0+$/, '');
        if (places === 'trim') shown = trimmed;
        else if (places === 'auto') shown = trimmed === '' ? '' : trimmed.padEnd(2, '0');
        else shown = trimmed.padEnd(2, '0');
    }
    return `${groupLakh(whole)}${shown === '' ? '' : `.${shown}`}`;
}

/**
 * An amount's figures with a true minus: "-124300.00" → "−1,24,300" (`trim`). Zero never
 * carries a sign, even when it rounds to zero.
 */
export function formatMoney(decimal: string, places: Places = 'trim'): string {
    const digits = formatDigits(decimal, places);
    return `${isNegativeAmount(decimal) && /[1-9]/.test(digits) ? MINUS : ''}${digits}`;
}

/**
 * "৳1,24,300" as plain text, for sentences and aria labels; a negative reads "−৳1,675.00".
 * `plus` prefixes "+" on a non-zero positive amount (money in).
 */
export function takaText(decimal: string, places: Places = 'trim', { plus = false }: { plus?: boolean } = {}): string {
    const { sign, digits } = takaParts(decimal, places, { plus });
    return `${sign}${TAKA}${digits}`;
}

/**
 * The sign ("−", "+" or "") and the figures of a taka amount, for a screen that sets the ৳ in its
 * own face between them (components/ui/TakaAmount).
 */
export function takaParts(decimal: string, places: Places = 'trim', { plus = false }: { plus?: boolean } = {}): { sign: string; digits: string } {
    const digits = formatDigits(decimal, places);
    const nonZero = /[1-9]/.test(digits);
    return { sign: isNegativeAmount(decimal) && nonZero ? MINUS : plus && nonZero ? '+' : '', digits };
}

const counts = new Intl.NumberFormat('en-IN', { numberingSystem: 'latn', maximumFractionDigits: 0 });

/**
 * A count (units, orders, customers, rows) with the same lakh grouping as money, Western digits in
 * both locales: 1234567 → "12,34,567". Counts are integers the server sent, not money.
 */
export function formatCount(value: number): string {
    return counts.format(value);
}
