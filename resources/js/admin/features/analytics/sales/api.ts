import { keepPreviousData, useQuery } from '@tanstack/react-query';
import type { Schema } from '@toymart/api-types';
import { api } from '@/lib/api';

export type SalesAnalytics = Schema<'SalesAnalytics'>;
export type TrendRow = SalesAnalytics['trend'][number];
export type PerformanceRow = SalesAnalytics['categories'][number] | SalesAnalytics['brands'][number];

/** The server's presets (App\Domain\Reporting\Support\DateRange). Dates are Dhaka dates. */
export const rangePresets = ['today', '7d', '30d', 'this_month', 'last_month', 'custom'] as const;
export type RangePreset = (typeof rangePresets)[number];
export const groups = ['day', 'week', 'month'] as const;
export type Group = (typeof groups)[number];

/** Empty strings let the server choose (7d, and a bucket size for the range). */
export type SalesFilters = { range: RangePreset; from: string; to: string; group: Group | '' };

/** Every parameter is a server allowlist (rule 16); the browser computes no figure (rule 1). */
export function salesQuery(f: SalesFilters, extra: Record<string, string> = {}): string {
    const params = new URLSearchParams();
    if (f.range !== '7d') params.set('range', f.range);
    if (f.range === 'custom') {
        if (f.from) params.set('filter[from]', f.from);
        if (f.to) params.set('filter[to]', f.to);
    }
    if (f.group) params.set('group', f.group);
    for (const [key, value] of Object.entries(extra)) params.set(key, value);
    const text = params.toString();
    return text ? `?${text}` : '';
}

/** A plain download link: the server streams the file, the cookie session authorises it. */
export const salesExportUrl = (f: SalesFilters, format: 'csv' | 'xlsx', locale: string) =>
    `/api/v1/admin/analytics/sales/export${salesQuery(f, { format, locale: locale === 'bn' ? 'bn' : 'en' })}`;

export function useSalesAnalytics(filters: SalesFilters) {
    const ready = filters.range !== 'custom' || (filters.from !== '' && filters.to !== '');
    return useQuery({
        queryKey: ['analytics', 'sales', filters],
        queryFn: () => api<SalesAnalytics>(`/analytics/sales${salesQuery(filters)}`),
        placeholderData: keepPreviousData,
        enabled: ready,
    });
}
