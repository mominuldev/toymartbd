import { keepPreviousData, useQuery } from '@tanstack/react-query';
import type { Schema } from '@toymart/api-types';
import { api } from '@/lib/api';

export type CourierPerformance = Schema<'CourierPerformance'>;
export type CourierRow = CourierPerformance['providers'][number];
export type DistrictRow = CourierPerformance['districts'][number];
export type Metrics = CourierPerformance['totals'];

/** A Dhaka-date range; empty strings let the server pick its default (the last seven days). */
export type Range = { from: string; to: string };

/** Every filter is a server allowlist (rule 16); the browser computes nothing (rule 1). */
export type CourierFilters = Range & { provider: string };

function query(f: CourierFilters): string {
    const params = new URLSearchParams();
    const entries: Record<string, string> = { 'filter[from]': f.from, 'filter[to]': f.to, 'filter[provider]': f.provider };
    for (const [key, value] of Object.entries(entries)) if (value) params.set(key, value);
    const text = params.toString();
    return text ? `?${text}` : '';
}

export const courierReportPath = (f: CourierFilters) => `/reports/couriers${query(f)}`;
/** The CSV is a plain download link: the server builds the file, the cookie session authorises it. */
export const courierReportExportUrl = (f: CourierFilters) => `/api/v1/admin/reports/couriers/export${query(f)}`;

export function useCourierPerformance(filters: CourierFilters) {
    return useQuery({
        queryKey: ['reports', 'couriers', filters],
        queryFn: () => api<CourierPerformance>(courierReportPath(filters)),
        placeholderData: keepPreviousData,
    });
}
