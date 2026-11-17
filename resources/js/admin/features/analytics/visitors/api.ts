import { keepPreviousData, useQuery } from '@tanstack/react-query';
import type { Schema } from '@toymart/api-types';
import { api } from '@/lib/api';

/** The server's presets (App\Domain\Reporting\Support\DateRange). Dates are Dhaka dates. */
export const rangePresets = ['today', '7d', '30d', 'this_month', 'last_month', 'custom'] as const;
export type RangePreset = (typeof rangePresets)[number];

export const funnelSteps = ['visited', 'viewed_product', 'added_to_cart', 'reached_checkout', 'placed_order', 'delivered'] as const;
export type FunnelStep = (typeof funnelSteps)[number];


/** GET /analytics/visitors (VisitorAnalyticsResource). Every figure and percent is the server's (rule 1). */
export type VisitorAnalytics = Schema<'VisitorAnalytics'>;

export type VisitorFilters = { range: RangePreset; from: string; to: string };

/** Every parameter is a server allowlist (rule 16). */
export function visitorsQuery(f: VisitorFilters): string {
    const params = new URLSearchParams();
    if (f.range !== '7d') params.set('range', f.range);
    if (f.range === 'custom') {
        if (f.from) params.set('filter[from]', f.from);
        if (f.to) params.set('filter[to]', f.to);
    }
    const text = params.toString();
    return text ? `?${text}` : '';
}

export function useVisitorAnalytics(filters: VisitorFilters) {
    const ready = filters.range !== 'custom' || (filters.from !== '' && filters.to !== '');
    return useQuery({
        queryKey: ['analytics', 'visitors', filters],
        queryFn: () => api<VisitorAnalytics>(`/analytics/visitors${visitorsQuery(filters)}`),
        placeholderData: keepPreviousData,
        enabled: ready,
    });
}
