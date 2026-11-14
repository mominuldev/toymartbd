import { useInfiniteQuery } from '@tanstack/react-query';
import type { Schema } from '@toymart/api-types';
import { api } from '@/lib/api';

export type AuditEntry = Schema<'AuditEntryResource'>;
export type AuditArea = AuditEntry['area'];
export type AuditKind = AuditEntry['kind'];

/** The server's areas (App\Models\Enums\AuditAction::AREAS), in display order. */
export const auditAreas: AuditArea[] = ['catalog', 'refunds', 'staff', 'integrations', 'fulfilment', 'reports'];

export type RangePreset = 'today' | '7d' | '30d' | '90d' | 'custom';
export const rangePresets: RangePreset[] = ['today', '7d', '30d', '90d', 'custom'];

export type AuditFilters = { from: string; to: string; userId: string; area: string };

type Page<T> = { data: T[]; meta: { next_cursor?: string | null } };

/** Today's Dhaka calendar date, YYYY-MM-DD (rule 11): the range presets count back from it. */
export function dhakaToday(now: Date = new Date()): string {
    return new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Dhaka', year: 'numeric', month: '2-digit', day: '2-digit' }).format(now);
}

/** A YYYY-MM-DD date `days` before another, as plain calendar arithmetic (no timezone involved). */
export function daysBefore(date: string, days: number): string {
    const d = new Date(`${date}T00:00:00Z`);
    d.setUTCDate(d.getUTCDate() - days);
    return d.toISOString().slice(0, 10);
}

/** The from/to a preset stands for; `custom` keeps what the person typed. */
export function presetRange(preset: Exclude<RangePreset, 'custom'>, today: string): { from: string; to: string } {
    const back = { today: 0, '7d': 6, '30d': 29, '90d': 89 }[preset];
    return { from: daysBefore(today, back), to: today };
}

/** `filter[…]` query for both the list and the export — only the allowlisted keys (rule 16). */
export function auditQuery(filters: AuditFilters): URLSearchParams {
    const params = new URLSearchParams();
    if (filters.from) params.set('filter[from]', filters.from);
    if (filters.to) params.set('filter[to]', filters.to);
    if (filters.userId) params.set('filter[user_id]', filters.userId);
    if (filters.area) params.set('filter[area]', filters.area);
    return params;
}

export function useAuditLog(filters: AuditFilters, enabled: boolean) {
    return useInfiniteQuery({
        queryKey: ['audit', filters],
        queryFn: ({ pageParam }) => {
            const params = auditQuery(filters);
            if (pageParam) params.set('cursor', pageParam);
            return api<Page<AuditEntry>>(`/audit?${params.toString()}`);
        },
        initialPageParam: null as string | null,
        getNextPageParam: (last) => last.meta.next_cursor ?? null,
        enabled,
    });
}

/** A plain download link: the server builds the CSV for these filters, the cookie session authorises it. */
export function auditExportUrl(filters: AuditFilters): string {
    return `/api/v1/admin/audit/export?${auditQuery(filters).toString()}`;
}
