import type { Messages } from '../i18n/en';
import { format } from '../i18n';

// Display formatting only — no arithmetic on money (CLAUDE.md rule 1). The API sends decimal
// strings ("4250.00"); we group the digits and drop a zero fraction. Western numerals in both
// locales (rule 36). The ৳ sits on the left with no space (storefront spec §0.8).

const DECIMAL = /^(\d+)(?:\.(\d{1,2}))?$/;

/** "4250.00" → "4,250"; "990.50" → "990.50". A malformed value is returned untouched. */
export function formatAmount(decimal: string): string {
    const match = DECIMAL.exec(decimal.trim());
    if (!match) return decimal;
    const whole = match[1] ?? '';
    const fraction = match[2] ?? '';
    const grouped = whole.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    return /^0*$/.test(fraction) ? grouped : `${grouped}.${fraction.padEnd(2, '0')}`;
}

/** "4250.00" → "৳4,250". */
export function formatPrice(decimal: string): string {
    return `৳${formatAmount(decimal)}`;
}

/** Display string equality of two API amounts ("1250.00" vs "1250.0"): formatting, not maths. */
export function sameAmount(a: string, b: string): boolean {
    return formatAmount(a) === formatAmount(b);
}

/** A whole or half year ("2", "1½", "½") for a month count, or null when it is neither. */
function years(months: number): string | null {
    if (months % 12 === 0) return String(months / 12);
    if (months % 6 === 0) {
        const whole = Math.floor(months / 12);
        return whole === 0 ? '½' : `${whole}½`;
    }
    return null;
}

/**
 * The derived age label (AgeRangeIndicator, storefront spec §A11): one function for card, chip and
 * schema. `<12` months → "0–n months"; an open-ended toddler start → "n months+"; whole or half
 * years → "a–b years" / "a+ years"; anything else falls back to months. Null when both bounds are
 * open (the toy is for every age; no pill).
 */
export function ageLabel(min: number | null, max: number | null, t: Messages['listing']): string | null {
    if (min === null && max === null) return null;
    const lo = min ?? 0;
    if (max !== null && max <= 12) return format(t.ageMonths, { min: String(lo), max: String(max) });
    if (max === null) {
        const y = years(lo);
        return y !== null && lo >= 12 ? format(t.ageYearsPlus, { min: y }) : format(t.ageMonthsPlus, { min: String(lo) });
    }
    const a = years(lo);
    const b = years(max);
    if (a !== null && b !== null) return lo === 0 ? format(t.ageUpTo, { max: b }) : format(t.ageYears, { min: a, max: b });
    return format(t.ageMonths, { min: String(lo), max: String(max) });
}

/** Facet label for a price band from the API's bounds ("Under ৳500", "৳500–1,000", "৳5,000+"). */
export function priceBandLabel(min: string, max: string | null, t: Messages['listing']): string {
    if (max === null) return format(t.priceOver, { min: formatPrice(min) });
    if (/^0*(\.0*)?$/.test(min)) return format(t.priceUnder, { max: formatPrice(max) });
    return format(t.priceRange, { min: formatPrice(min), max: formatAmount(max) });
}
