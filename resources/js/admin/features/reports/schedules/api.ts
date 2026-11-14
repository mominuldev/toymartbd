import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import type { Schema } from '@toymart/api-types';
import type { ReportScheduleRequestInput } from '@toymart/api-types/zod';
import { api } from '@/lib/api';

export type ReportSchedule = Schema<'ReportSchedule'>;
export type ReportScheduleRun = Schema<'ReportScheduleRun'>;
export type RunCopy = ReportScheduleRun['deliveries'][number];
export type ScheduleInput = ReportScheduleRequestInput;
export type Recipient = { id: number; name: string; roles: string[] };

export const reportTypes = ['sales', 'pnl', 'couriers'] as const;
export const frequencies = ['daily', 'weekly', 'monthly'] as const;
export const formats = ['xlsx', 'csv'] as const;
export type ReportType = (typeof reportTypes)[number];

/** Quarter hours (the server sweep runs every 15 minutes), Dhaka time. */
export const sendTimes: string[] = Array.from({ length: 96 }, (_, i) => `${String(Math.floor(i / 4)).padStart(2, '0')}:${String((i % 4) * 15).padStart(2, '0')}`);

const key = ['reports', 'schedules'] as const;

export function useSchedules() {
    return useQuery({ queryKey: key, queryFn: () => api<{ data: ReportSchedule[] }>('/reports/schedules').then((r) => r.data) });
}

export function useRuns(id: number | null) {
    return useQuery({
        queryKey: [...key, id, 'runs'],
        queryFn: () => api<{ data: ReportScheduleRun[] }>(`/reports/schedules/${id}/runs`).then((r) => r.data),
        enabled: id !== null,
    });
}

/** Active staff who may receive this report type — names and roles only, never addresses. */
export function useRecipients(type: ReportType) {
    return useQuery({
        queryKey: [...key, 'recipients', type],
        queryFn: () => api<{ data: Recipient[] }>(`/reports/schedules/recipients?report_type=${type}`).then((r) => r.data),
    });
}

export function useSaveSchedule(id: number | null) {
    const client = useQueryClient();
    return useMutation({
        mutationFn: (input: ScheduleInput) =>
            id === null
                ? api<ReportSchedule>('/reports/schedules', { method: 'POST', body: JSON.stringify(input) })
                : api<ReportSchedule>(`/reports/schedules/${id}`, { method: 'PUT', body: JSON.stringify(input) }),
        onSuccess: () => client.invalidateQueries({ queryKey: key }),
    });
}

/**
 * Resend a failed run for the same period (P9): every failed copy, or one recipient's
 * (`userId`). The server re-checks permissions and never mails a copy twice.
 */
export function useResendRun(scheduleId: number) {
    const client = useQueryClient();
    return useMutation({
        mutationFn: ({ runId, userId }: { runId: number; userId?: number }) =>
            api<ReportScheduleRun>(`/reports/schedules/${scheduleId}/runs/${runId}/resend`, { method: 'POST', body: JSON.stringify(userId === undefined ? {} : { user_id: userId }) }),
        onSuccess: () => client.invalidateQueries({ queryKey: key }),
    });
}

export function useDeleteSchedule() {
    const client = useQueryClient();
    return useMutation({
        mutationFn: (id: number) => api<undefined>(`/reports/schedules/${id}`, { method: 'DELETE' }),
        onSuccess: () => client.invalidateQueries({ queryKey: key }),
    });
}

export function toInput(s: ReportSchedule, overrides: Partial<ScheduleInput> = {}): ScheduleInput {
    return {
        name: s.name,
        report_type: s.report_type,
        frequency: s.frequency,
        send_time: s.send_time,
        locale: s.locale,
        format: s.format,
        is_active: s.is_active,
        recipient_ids: s.recipients.map((r) => r.id),
        ...overrides,
    };
}
