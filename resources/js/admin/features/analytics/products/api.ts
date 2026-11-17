import { keepPreviousData, useQuery } from '@tanstack/react-query';
import type { Schema } from '@toymart/api-types';
import { api } from '@/lib/api';

/** A product's figures for the range. Money is a decimal string; percentages are 2-dp strings or null. */
export type ProductAnalyticsRow = ProductAnalytics['products'][number];

/** A side-card row: stock and pace only. */
export type ProductStockRow = ProductAnalytics['running_out'][number];


export type ProductAnalytics = Schema<'ProductAnalytics'>;

/** The server's presets (App\Domain\Reporting\Support\DateRange). Dates are Dhaka dates. */
export const rangePresets = ['today', '7d', '30d', 'this_month', 'last_month', 'custom'] as const;
export type RangePreset = (typeof rangePresets)[number];
export const productSorts = ['revenue', 'units', 'margin', 'returns'] as const;
export type ProductSort = (typeof productSorts)[number];

/** Defaults (30d, revenue) are left to the server. */
export type ProductFilters = { range: RangePreset; from: string; to: string; sort: ProductSort };

/** Every parameter is a server allowlist (rule 16); the browser computes no figure (rule 1). */
export function productAnalyticsQuery(f: ProductFilters): string {
    const params = new URLSearchParams();
    if (f.range !== '30d') params.set('range', f.range);
    if (f.range === 'custom') {
        if (f.from) params.set('filter[from]', f.from);
        if (f.to) params.set('filter[to]', f.to);
    }
    if (f.sort !== 'revenue') params.set('sort', f.sort);
    const text = params.toString();
    return text ? `?${text}` : '';
}

export function useProductAnalytics(filters: ProductFilters) {
    const ready = filters.range !== 'custom' || (filters.from !== '' && filters.to !== '');
    return useQuery({
        queryKey: ['analytics', 'products', filters],
        queryFn: () => api<ProductAnalytics>(`/analytics/products${productAnalyticsQuery(filters)}`),
        placeholderData: keepPreviousData,
        enabled: ready,
    });
}
