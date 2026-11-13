// Flash-sale countdown maths and labels (P7, storefront spec §A15). Pure: no prices — only time.
// The end time is the API's ISO timestamp (server truth); the client only counts down to it.

export type CountdownState = 'normal' | 'urgent' | 'ended';

export interface Remaining {
    totalMs: number;
    days: number;
    hours: number;
    minutes: number;
    seconds: number;
}

const HOUR = 3_600_000;

/** Time left until `endsAt` (ISO) at `nowMs`; never negative. Null when the timestamp is unreadable. */
export function remaining(endsAt: string, nowMs: number): Remaining | null {
    const end = Date.parse(endsAt);
    if (Number.isNaN(end)) return null;
    const totalMs = Math.max(0, end - nowMs);
    const totalSeconds = Math.floor(totalMs / 1000);
    return {
        totalMs,
        days: Math.floor(totalSeconds / 86_400),
        hours: Math.floor((totalSeconds % 86_400) / 3600),
        minutes: Math.floor((totalSeconds % 3600) / 60),
        seconds: totalSeconds % 60,
    };
}

/** `Urgent` below one hour (spec §A15), `Ended` at zero. */
export function countdownState(r: Remaining | null): CountdownState {
    if (r === null || r.totalMs <= 0) return 'ended';
    return r.totalMs < HOUR ? 'urgent' : 'normal';
}

const pad = (n: number): string => String(n).padStart(2, '0');

/** "02:14:09" for the xs/sm run — hours include whole days (Western digits, rule 36). */
export function clock(r: Remaining): string {
    return `${pad(r.days * 24 + r.hours)}:${pad(r.minutes)}:${pad(r.seconds)}`;
}

/** The four md tiles: days, hours, minutes, seconds, each two digits. */
export function tiles(r: Remaining): [string, string, string, string] {
    return [pad(r.days), pad(r.hours), pad(r.minutes), pad(r.seconds)];
}

/**
 * The end time as shown without JavaScript: "14/11/2026, 23:59" in Dhaka time, Western numerals
 * in both locales (rule 36). Empty when the timestamp is unreadable.
 */
export function dhakaDateTime(iso: string): string {
    const date = new Date(iso);
    if (Number.isNaN(date.getTime())) return '';
    const parts = new Intl.DateTimeFormat('en-GB', {
        timeZone: 'Asia/Dhaka',
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        hourCycle: 'h23',
    }).formatToParts(date);
    const get = (type: Intl.DateTimeFormatPartTypes): string => parts.find((p) => p.type === type)?.value ?? '';
    return `${get('day')}/${get('month')}/${get('year')}, ${get('hour')}:${get('minute')}`;
}

/** Short form for the 96px xs pill: "14/11 23:59". */
export function dhakaShort(iso: string): string {
    const full = dhakaDateTime(iso);
    const m = /^(\d{2})\/(\d{2})\/\d{4}, (\d{2}:\d{2})$/.exec(full);
    return m ? `${m[1]}/${m[2]} ${m[3]}` : full;
}

/** The one polite announcement due when the countdown crosses 60, 10 or 1 minute(s) left (§A15). */
export function announcementCrossed(previousMs: number, currentMs: number): 60 | 10 | 1 | null {
    for (const minutes of [1, 10, 60] as const) {
        const mark = minutes * 60_000;
        if (previousMs > mark && currentMs <= mark && currentMs > 0) return minutes;
    }
    return null;
}

/** The PDP's flash offer for one variant (P7): the API's per-variant entry plus the sale's end time. */
export interface VariantFlash {
    salePrice: string;
    discountPercent: number;
    remaining: number;
    endsAt: string;
}

interface ProductFlash {
    ends_at: string;
    variants: Record<string, { sale_price: string; discount_percent: number; remaining: number }>;
}

export function variantFlash(flash: ProductFlash | null | undefined, variantId: number): VariantFlash | null {
    const offer = flash?.variants[String(variantId)];
    if (!flash || !offer) return null;
    return { salePrice: offer.sale_price, discountPercent: offer.discount_percent, remaining: offer.remaining, endsAt: flash.ends_at };
}
