import { keepPreviousData, useQuery } from '@tanstack/react-query';
import type { Schema } from '@toymart/api-types';
import { api } from '@/lib/api';

export type CustomerAnalytics = Schema<'CustomerAnalytics'>;
export type Cohort = CustomerAnalytics['cohorts'][number];

export const monthChoices = ['3', '6', '12'] as const;
export type Months = (typeof monthChoices)[number];

/** First day of the Dhaka month `months − 1` before this one, and today — a custom range for the API. */
export function monthsRange(months: number, now: Date = new Date()): { from: string; to: string } {
    const today = new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Dhaka', year: 'numeric', month: '2-digit', day: '2-digit' }).format(now);
    const start = new Date(`${today.slice(0, 7)}-01T00:00:00Z`);
    start.setUTCMonth(start.getUTCMonth() - (months - 1));
    return { from: start.toISOString().slice(0, 10), to: today };
}

/** The server defaults to six months; other choices go as `range=custom`. Nothing is computed here (rule 1). */
export function customerAnalyticsPath(months: Months, now?: Date): string {
    if (months === '6') return '/reports/customers';
    const { from, to } = monthsRange(Number(months), now);
    return `/reports/customers?${new URLSearchParams({ range: 'custom', from, to }).toString()}`;
}

export function useCustomerAnalytics(months: Months) {
    return useQuery({
        queryKey: ['reports', 'customers', months],
        queryFn: () => api<CustomerAnalytics>(customerAnalyticsPath(months)),
        placeholderData: keepPreviousData,
    });
}
