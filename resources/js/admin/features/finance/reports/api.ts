import { keepPreviousData, useQuery } from '@tanstack/react-query';
import type { Schema } from '@toymart/api-types';
import { api } from '@/lib/api';
import type { ExportFormat } from '@/components/ui/ExportMenu';

export type PnlStatement = Schema<'PnlStatement'>;
export type PnlSeries = Schema<'PnlSeries'>;
export type ProfitabilityReport = Schema<'ProfitabilityReport'>;
export type ProfitabilityRow = ProfitabilityReport['data'][number];
export type PnlTotals = PnlStatement['totals'];
export type PnlBlock = PnlStatement['blocks'][number];

export type Compare = 'none' | 'previous_period' | 'previous_year';
export type Granularity = PnlSeries['granularity'];
export type ProfitBy = ProfitabilityReport['by'];

export const compares: Compare[] = ['previous_period', 'previous_year', 'none'];
export const granularities: Granularity[] = ['day', 'week', 'month', 'year'];
export const profitBys: ProfitBy[] = ['product', 'category', 'source'];
export const profitSorts = ['net_revenue', 'gross_profit', 'margin_percent', 'units', 'cogs', 'name'] as const;
export type ProfitSort = (typeof profitSorts)[number];

/** A Dhaka-date range; empty strings let the server pick its default (the last seven days). */
export type Range = { from: string; to: string };

/** The admin API base the browser downloads CSVs from (same origin, cookie session). */
const BASE = '/api/v1/admin';

function query(entries: Record<string, string | null | undefined>): string {
    const params = new URLSearchParams();
    for (const [key, value] of Object.entries(entries)) if (value) params.set(key, value);
    const text = params.toString();
    return text ? `?${text}` : '';
}

const rangeParams = (range: Range) => ({ 'filter[from]': range.from, 'filter[to]': range.to });

export const pnlPath = (range: Range, compare: Compare) => `/finance/reports/pnl${query({ ...rangeParams(range), compare })}`;
export const seriesPath = (range: Range, group: Granularity | '') => `/finance/reports/pnl/series${query({ ...rangeParams(range), group })}`;

export type ProfitFilters = { by: ProfitBy; sort: string; search: string; source: string };
export const profitabilityPath = (range: Range, f: ProfitFilters) =>
    `/finance/reports/profitability${query({ ...rangeParams(range), by: f.by, sort: f.sort, 'filter[search]': f.search, 'filter[source]': f.source })}`;

/**
 * CSV / XLSX downloads are plain links: the server builds the file (rule 1), the cookie session
 * authorises it. `locale` is the language of the labels and sheet names inside the file.
 */
export type ExportFile = { format: ExportFormat; locale: 'en' | 'bn' };
export const pnlExportUrl = (range: Range, file: ExportFile) => `${BASE}/finance/reports/pnl/export${query({ ...rangeParams(range), ...file })}`;
export const seriesExportUrl = (range: Range, group: Granularity | '', file: ExportFile) =>
    `${BASE}/finance/reports/pnl/series/export${query({ ...rangeParams(range), group, ...file })}`;
export const profitabilityExportUrl = (range: Range, f: ProfitFilters, file: ExportFile) =>
    `${BASE}/finance/reports/profitability/export${query({ ...rangeParams(range), by: f.by, sort: f.sort, 'filter[search]': f.search, 'filter[source]': f.source, ...file })}`;

export function usePnl(range: Range, compare: Compare) {
    return useQuery({
        queryKey: ['finance-reports', 'pnl', range, compare],
        queryFn: () => api<PnlStatement>(pnlPath(range, compare)),
        placeholderData: keepPreviousData,
    });
}

export function usePnlSeries(range: Range, group: Granularity | '') {
    return useQuery({
        queryKey: ['finance-reports', 'series', range, group],
        queryFn: () => api<PnlSeries>(seriesPath(range, group)),
        placeholderData: keepPreviousData,
    });
}

export function useProfitability(range: Range, filters: ProfitFilters) {
    return useQuery({
        queryKey: ['finance-reports', 'profitability', range, filters],
        queryFn: () => api<ProfitabilityReport>(profitabilityPath(range, filters)),
        placeholderData: keepPreviousData,
    });
}
