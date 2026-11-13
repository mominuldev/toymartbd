import { keepPreviousData, useQuery } from '@tanstack/react-query';
import type { Schema } from '@toymart/api-types';
import { api } from '@/lib/api';
import type { ExportFormat } from '@/components/ui/ExportMenu';

export type InventoryValuation = Schema<'InventoryValuation'>;
export type ValuationRow = InventoryValuation['data'][number];

export const valuationSorts = ['value', 'on_hand', 'unit_cost', 'name'] as const;
export type ValuationSort = (typeof valuationSorts)[number];

/** `asAt` empty = now (the live projection). Every filter is a server allowlist (rule 16). */
export type ValuationFilters = { asAt: string; location: string; search: string; sort: string };

function query(f: ValuationFilters, extra: Record<string, string> = {}): string {
    const params = new URLSearchParams();
    const entries: Record<string, string> = { 'filter[as_at]': f.asAt, 'filter[location_id]': f.location, 'filter[search]': f.search, sort: f.sort, ...extra };
    for (const [key, value] of Object.entries(entries)) if (value) params.set(key, value);
    const text = params.toString();
    return text ? `?${text}` : '';
}

export const valuationPath = (f: ValuationFilters) => `/reports/inventory-valuation${query(f)}`;
/** CSV / XLSX are plain download links: the server builds the file (rule 1); `locale` labels its contents. */
export const valuationExportUrl = (f: ValuationFilters, format: ExportFormat, locale: 'en' | 'bn') =>
    `/api/v1/admin/reports/inventory-valuation/export${query(f, { format, locale })}`;

export function useValuation(filters: ValuationFilters) {
    return useQuery({
        queryKey: ['reports', 'inventory-valuation', filters],
        queryFn: () => api<InventoryValuation>(valuationPath(filters)),
        placeholderData: keepPreviousData,
    });
}
