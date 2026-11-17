import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import type { Schema } from '@toymart/api-types';
import { saveAdSpendRequestSchema, type SaveAdSpendRequestInput } from '@toymart/api-types/zod';
import { api } from '@/lib/api';

/** The server's presets (App\Domain\Reporting\Support\DateRange). Dates are Dhaka dates. */
export const rangePresets = ['today', '7d', '30d', 'this_month', 'last_month', 'custom'] as const;
export type RangePreset = (typeof rangePresets)[number];
export type MarketingFilters = { range: RangePreset; from: string; to: string };

/*
 * MarketingAnalyticsResource / AdSpendResource / SaveAdSpendRequest by hand
 * (Schema<'MarketingAnalytics'>, Schema<'AdSpend'>, saveAdSpendRequestSchema).
 */
export type MarketingSummary = MarketingAnalytics['summary'];
export type CampaignRow = MarketingAnalytics['campaigns'][number];
export type DealRow = MarketingAnalytics['deals'][number];
export type MarketingAnalytics = Schema<'MarketingAnalytics'>;
export type AdSpend = Schema<'AdSpend'>;
export type AdSpendList = { data: AdSpend[]; meta: { campaigns: string[] } };

/** The generated SaveAdSpendRequest rules (rule 27). */
export const adSpendInputSchema = saveAdSpendRequestSchema;
export type AdSpendInput = SaveAdSpendRequestInput;

/** Every parameter is a server allowlist (rule 16); the browser computes no figure (rule 1). */
export function marketingQuery(f: MarketingFilters): string {
    const params = new URLSearchParams();
    if (f.range !== '7d') params.set('range', f.range);
    if (f.range === 'custom') {
        if (f.from) params.set('filter[from]', f.from);
        if (f.to) params.set('filter[to]', f.to);
    }
    const text = params.toString();
    return text ? `?${text}` : '';
}

const ready = (f: MarketingFilters) => f.range !== 'custom' || (f.from !== '' && f.to !== '');

export function useMarketingAnalytics(filters: MarketingFilters) {
    return useQuery({
        queryKey: ['analytics', 'marketing', filters],
        queryFn: () => api<MarketingAnalytics>(`/analytics/marketing${marketingQuery(filters)}`),
        placeholderData: keepPreviousData,
        enabled: ready(filters),
    });
}

export function useAdSpend(filters: MarketingFilters) {
    return useQuery({
        queryKey: ['analytics', 'marketing', 'spend', filters],
        queryFn: () => api<AdSpendList>(`/analytics/marketing/spend${marketingQuery(filters)}`),
        placeholderData: keepPreviousData,
        enabled: ready(filters),
    });
}

/** Add (id null) or change ad spend; the report and the list are refetched. */
export function useSaveAdSpend(id: number | null) {
    const client = useQueryClient();
    return useMutation({
        mutationFn: (input: AdSpendInput) =>
            api<AdSpend>(id === null ? '/analytics/marketing/spend' : `/analytics/marketing/spend/${id}`, { method: id === null ? 'POST' : 'PUT', body: JSON.stringify(input) }),
        onSuccess: () => client.invalidateQueries({ queryKey: ['analytics', 'marketing'] }),
    });
}

export function useDeleteAdSpend() {
    const client = useQueryClient();
    return useMutation({
        mutationFn: (id: number) => api<void>(`/analytics/marketing/spend/${id}`, { method: 'DELETE' }),
        onSuccess: () => client.invalidateQueries({ queryKey: ['analytics', 'marketing'] }),
    });
}
