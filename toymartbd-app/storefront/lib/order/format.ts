import { format, type Locale, type Messages } from '../i18n';
import type { OrderStage, StorefrontOrder } from '../cart/types';

// Display helpers for the confirmation and tracking pages (P4-STO-10). Dates are shown in Dhaka
// time with Western digits (rules 11, 36); figures are the API's strings.

const parts = (iso: string | null) => {
    if (!iso) return null;
    const date = new Date(iso);
    if (Number.isNaN(date.getTime())) return null;
    const fields = new Intl.DateTimeFormat('en-GB', { timeZone: 'Asia/Dhaka', day: 'numeric', month: 'numeric', hour: 'numeric', minute: '2-digit', hourCycle: 'h23' }).formatToParts(date);
    const pick = (type: string) => Number(fields.find((p) => p.type === type)?.value ?? '0');
    return { day: pick('day'), month: pick('month'), hour: pick('hour'), minute: pick('minute') };
};

/** "10 Nov" in Dhaka (empty when the API has no date). */
export function orderDate(iso: string | null, t: Messages): string {
    const p = parts(iso);
    if (!p) return iso ?? '';
    return format(t.order.dayMonth, { day: String(p.day), month: t.delivery.months[p.month - 1] ?? String(p.month) });
}

/** "10 Nov, 11:02 am" in Dhaka. */
export function orderDateTime(iso: string, t: Messages): string {
    const p = parts(iso);
    if (!p) return iso;
    const h = p.hour % 12 === 0 ? 12 : p.hour % 12;
    const time = format(p.hour < 12 ? t.order.timeAm : t.order.timePm, { h: String(h), m: String(p.minute).padStart(2, '0') });
    return format(t.order.dateTime, { date: orderDate(iso, t), time });
}

/** The happy path the OrderTimeline draws (Figma 121:1558). "Out for delivery" has no API stage yet. */
export const TIMELINE: readonly OrderStage[] = ['placed', 'confirmed', 'processing', 'shipped', 'delivered'];

export type TimelineState = 'complete' | 'current' | 'upcoming' | 'stopped';

export interface TimelineStep {
    stage: OrderStage;
    state: TimelineState;
    at: string | null;
}

/**
 * The steps to draw: the happy path up to the current stage (each with the API's timestamp), the
 * rest upcoming. A cancelled or returned order ends at its last reached step plus the stop.
 */
export function timelineSteps(order: Pick<StorefrontOrder, 'stage' | 'timeline'>): TimelineStep[] {
    const at = (stage: OrderStage) => order.timeline.filter((e) => e.stage === stage).at(-1)?.at ?? null;
    if (order.stage === 'cancelled' || order.stage === 'returned') {
        const reached = TIMELINE.filter((s) => at(s) !== null);
        return [...reached.map((stage) => ({ stage, state: 'complete' as const, at: at(stage) })), { stage: order.stage, state: 'stopped', at: at(order.stage) }];
    }
    const current = TIMELINE.indexOf(order.stage);
    return TIMELINE.map((stage, i) => ({
        stage,
        state: i < current || (i === current && stage === 'delivered') ? 'complete' : i === current ? 'current' : 'upcoming',
        at: i <= current ? at(stage) : null,
    }));
}

export function orderItemName(item: StorefrontOrder['items'][number], locale: Locale): { text: string; lang?: 'en' } {
    if (locale === 'bn') return item.name_bn ? { text: item.name_bn } : { text: item.name, lang: 'en' };
    return { text: item.name };
}

/** Units in the order ("3 items"): a count of things, not money. */
export function orderItemCount(order: Pick<StorefrontOrder, 'items'>): number {
    return order.items.reduce((n, item) => n + item.quantity, 0);
}
