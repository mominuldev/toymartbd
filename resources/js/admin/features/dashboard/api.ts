import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { useSearchParams } from 'react-router';
import type { Schema } from '@toymart/api-types';
import { api } from '@/lib/api';

export type Dashboard = Schema<'Dashboard'>;
export type DashboardKpis = Dashboard['kpis'];
export type DashboardRangePreset = Dashboard['range']['preset'];
export type AttentionItem = Dashboard['attention'][number];
export type ActivityEvent = NonNullable<Dashboard['activity']>[number];
export type TrendBucket = NonNullable<Dashboard['trend']>['buckets'][number];

/** The server's allowlist (DashboardRequest); anything else is a 400. */
export const rangePresets: DashboardRangePreset[] = ['today', '7d', '30d', 'this_month', 'last_month', 'custom'];

export type DashboardRange = { preset: DashboardRangePreset; from: string; to: string };

const isDate = (value: string | null): value is string => /^\d{4}-\d{2}-\d{2}$/.test(value ?? '');

export function dashboardPath(range: DashboardRange): string {
    const params = new URLSearchParams();
    if (range.preset !== '7d') params.set('range', range.preset);
    if (range.preset === 'custom') {
        params.set('from', range.from);
        params.set('to', range.to);
    }
    const text = params.toString();
    return `/dashboard${text ? `?${text}` : ''}`;
}

export type ExportFormat = 'csv' | 'xlsx';

/**
 * A plain download link to `GET /dashboard/export` for the range on screen: the server builds
 * and streams the file, the cookie session authorises it (as on the sales analytics export).
 */
export function dashboardExportUrl(range: DashboardRange, format: ExportFormat, locale: string): string {
    const params = new URLSearchParams();
    if (range.preset !== '7d') params.set('range', range.preset);
    if (range.preset === 'custom') {
        params.set('from', range.from);
        params.set('to', range.to);
    }
    params.set('format', format);
    params.set('locale', locale === 'bn' ? 'bn' : 'en');
    return `/api/v1/admin/dashboard/export?${params.toString()}`;
}

/**
 * The range lives in the URL (`?range=30d`, or `?range=custom&from=…&to=…`) so a dashboard view
 * is a shareable link. A custom range without both dates falls back to the default.
 */
export function useDashboardRange() {
    const [params, setParams] = useSearchParams();
    const preset = rangePresets.find((p) => p === params.get('range')) ?? '7d';
    const from = params.get('from');
    const to = params.get('to');
    const range: DashboardRange = preset === 'custom' && isDate(from) && isDate(to) ? { preset, from, to } : { preset: preset === 'custom' ? '7d' : preset, from: '', to: '' };

    const setRange = (next: DashboardRange) =>
        setParams(
            () => {
                const out = new URLSearchParams();
                if (next.preset !== '7d') out.set('range', next.preset);
                if (next.preset === 'custom') {
                    out.set('from', next.from);
                    out.set('to', next.to);
                }
                return out;
            },
            { replace: true },
        );

    return { range, setRange };
}

export function useDashboard(range: DashboardRange) {
    return useQuery({
        queryKey: ['dashboard', range],
        queryFn: () => api<Dashboard>(dashboardPath(range)),
        placeholderData: keepPreviousData,
        // Live queues and today's counts: refresh while the owner keeps the tab open.
        refetchInterval: 60_000,
    });
}
