import { keepPreviousData, useQuery } from '@tanstack/react-query';
import type { Schema } from '@toymart/api-types';
import { api } from '@/lib/api';
import type { ExportFormat } from '@/components/ui/ExportMenu';

export type CourierPerformance = Schema<'CourierPerformance'>;
export type CourierRow = CourierPerformance['providers'][number];
export type DistrictRow = CourierPerformance['districts'][number];
export type Metrics = CourierPerformance['totals'];

/** A Dhaka-date range; empty strings let the server pick its default (the last seven days). */
export type Range = { from: string; to: string };

/** Every filter is a server allowlist (rule 16); the browser computes nothing (rule 1). */
export type CourierFilters = Range & { provider: string };

function query(f: CourierFilters, extra: Record<string, string> = {}): string {
    const params = new URLSearchParams();
    const entries: Record<string, string> = { 'filter[from]': f.from, 'filter[to]': f.to, 'filter[provider]': f.provider, ...extra };
    for (const [key, value] of Object.entries(entries)) if (value) params.set(key, value);
    const text = params.toString();
    return text ? `?${text}` : '';
}

export const courierReportPath = (f: CourierFilters) => `/reports/couriers${query(f)}`;
/** CSV / XLSX are plain download links: the server builds the file, the cookie session authorises it; `locale` labels its contents. */
export const courierReportExportUrl = (f: CourierFilters, format: ExportFormat, locale: 'en' | 'bn') =>
    `/api/v1/admin/reports/couriers/export${query(f, { format, locale })}`;

export function useCourierPerformance(filters: CourierFilters) {
    return useQuery({
        queryKey: ['reports', 'couriers', filters],
        queryFn: () => api<CourierPerformance>(courierReportPath(filters)),
        placeholderData: keepPreviousData,
    });
}
