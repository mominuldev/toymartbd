import { format, type Messages } from '../i18n';
import { formatPrice } from '../listing/format';
import type { DeliveryQuote, DeliveryRates, District, Upazila } from './types';

// Display only: every figure (charge, dates, progress) comes from the API's resolver — the
// storefront never computes a delivery charge or date (CLAUDE.md rule 1).

const DATE = /^(\d{4})-(\d{2})-(\d{2})$/;

/** "2026-11-10" → "10 Nov" / "10 নভেম্বর" — Western digits in both locales (rule 36). */
export function shortDate(ymd: string, t: Messages['delivery']): string {
    const match = DATE.exec(ymd);
    if (!match) return ymd;
    const month = t.months[Number(match[2]) - 1] ?? match[2];
    return `${Number(match[3])} ${month}`;
}

/** "Arrives 10 – 11 Nov": the month is said once when both dates share it. */
export function arrivalLine(eta: NonNullable<DeliveryQuote['eta']>, t: Messages['delivery']): string {
    if (eta.earliest === eta.latest) return format(t.arrivesOn, { date: shortDate(eta.earliest, t) });
    const from = shortDate(eta.earliest, t);
    const to = shortDate(eta.latest, t);
    const sameMonth = eta.earliest.slice(0, 7) === eta.latest.slice(0, 7);
    return format(t.arrives, { from: sameMonth ? from.split(' ')[0] ?? from : from, to });
}

export function workingDaysLine(eta: NonNullable<DeliveryQuote['eta']>, t: Messages['delivery']): string {
    return eta.min_days === eta.max_days
        ? format(t.workingDay, { days: String(eta.min_days) })
        : format(t.workingDays, { min: String(eta.min_days), max: String(eta.max_days) });
}

/** The published fallback caption: "৳70 in Dhaka · ৳130 outside · free over ৳3,000". */
export function publishedLine(rates: DeliveryRates | null, t: Messages['delivery']): string | null {
    if (!rates) return null;
    const metro = rates.zones.find((z) => z.zone === 'inside_dhaka_metro');
    const outside = rates.zones.find((z) => z.zone === 'outside_dhaka');
    if (!metro || !outside) return null;
    const values = { metro: formatPrice(metro.charge), outside: formatPrice(outside.charge) };
    return rates.free_shipping_threshold
        ? format(t.published, { ...values, threshold: formatPrice(rates.free_shipping_threshold) })
        : format(t.publishedNoFree, values);
}

export function placeName(entity: Pick<District, 'name' | 'name_bn'> | Pick<Upazila, 'name' | 'name_bn'>, bn: boolean): string {
    return bn ? entity.name_bn : entity.name;
}

/** Where the quote is for: the upazila when one was chosen, else the district. */
export function quotePlace(quote: DeliveryQuote, bn: boolean): string {
    return placeName(quote.upazila ?? quote.district, bn);
}
