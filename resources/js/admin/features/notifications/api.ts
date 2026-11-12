import { keepPreviousData, useInfiniteQuery, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import type { Schema } from '@toymart/api-types';
import type { PreviewNotificationTemplateRequestInput, UpdateNotificationTemplateRequestInput } from '@toymart/api-types/zod';
import { api } from '@/lib/api';

export type NotificationTemplate = Schema<'NotificationTemplate'>;
export type NotificationLog = Schema<'NotificationLog'>;
export type NotificationSummary = Schema<'NotificationSummary'>;
export type TemplatePreview = Schema<'NotificationTemplatePreview'>;
export type Channel = NotificationTemplate['channel'];
export type LogStatus = NotificationLog['status'];
/** The PUT body — the generated request type, never a hand-written copy (rule 27). */
export type TemplateInput = UpdateNotificationTemplateRequestInput;

export const channels: Channel[] = ['whatsapp', 'sms', 'email'];
export const logStatuses: LogStatus[] = ['queued', 'sent', 'failed', 'dead_lettered', 'skipped'];

export type LogFilters = { status: LogStatus | ''; channel: Channel | ''; template: string };

type Page<T> = { data: T[]; meta: { next_cursor?: string | null } };

const keys = {
    templates: ['notifications', 'templates'] as const,
    preview: (id: number, body: PreviewNotificationTemplateRequestInput) => ['notifications', 'preview', id, body] as const,
    logs: (filters: LogFilters) => ['notifications', 'logs', filters] as const,
    summary: ['notifications', 'summary'] as const,
};

function query(entries: Record<string, string | null | undefined>): string {
    const params = new URLSearchParams();
    for (const [key, value] of Object.entries(entries)) if (value) params.set(key, value);
    const text = params.toString();
    return text ? `?${text}` : '';
}

export function logsPath(filters: LogFilters, cursor: string | null): string {
    return `/notifications/logs${query({
        'filter[status]': filters.status,
        'filter[channel]': filters.channel,
        'filter[template]': filters.template,
        cursor,
    })}`;
}

export function useTemplates() {
    return useQuery({
        queryKey: keys.templates,
        queryFn: () => api<{ data: NotificationTemplate[] }>('/notifications/templates').then((r) => r.data),
    });
}

/** Server-side measurement of unsaved bodies: characters, encoding, segments, cost (rule 1). */
export function useTemplatePreview(id: number | null, body: PreviewNotificationTemplateRequestInput) {
    return useQuery({
        queryKey: keys.preview(id ?? 0, body),
        queryFn: () => api<TemplatePreview>(`/notifications/templates/${id}/preview`, { method: 'POST', body: JSON.stringify(body) }),
        enabled: id !== null && body.body_en.trim() !== '' && body.body_bn.trim() !== '',
        placeholderData: keepPreviousData,
    });
}

export function useSaveTemplate(id: number) {
    const client = useQueryClient();
    return useMutation({
        mutationFn: (input: TemplateInput) => api<NotificationTemplate>(`/notifications/templates/${id}`, { method: 'PUT', body: JSON.stringify(input) }),
        onSuccess: (saved) => {
            client.setQueryData<NotificationTemplate[]>(keys.templates, (rows) => rows?.map((row) => (row.id === saved.id ? saved : row)));
        },
    });
}

/** Saves several templates (the card switch turns every channel of one event on or off). */
export function useSaveTemplates() {
    const client = useQueryClient();
    return useMutation({
        mutationFn: (jobs: { id: number; input: TemplateInput }[]) =>
            Promise.all(jobs.map(({ id, input }) => api<NotificationTemplate>(`/notifications/templates/${id}`, { method: 'PUT', body: JSON.stringify(input) }))),
        onSuccess: (saved) => {
            client.setQueryData<NotificationTemplate[]>(keys.templates, (rows) => rows?.map((row) => saved.find((s) => s.id === row.id) ?? row));
        },
    });
}

export function useLogs(filters: LogFilters, enabled = true) {
    return useInfiniteQuery({
        queryKey: keys.logs(filters),
        queryFn: ({ pageParam }) => api<Page<NotificationLog>>(logsPath(filters, pageParam)),
        initialPageParam: null as string | null,
        getNextPageParam: (last) => last.meta.next_cursor ?? null,
        placeholderData: keepPreviousData,
        enabled,
    });
}

export function useSummary() {
    return useQuery({ queryKey: keys.summary, queryFn: () => api<NotificationSummary>('/notifications/logs/summary') });
}

export function useRetry() {
    const client = useQueryClient();
    return useMutation({
        mutationFn: (id: number) => api<NotificationLog>(`/notifications/logs/${id}/retry`, { method: 'POST' }),
        onSuccess: () => void client.invalidateQueries({ queryKey: ['notifications'] }),
    });
}
