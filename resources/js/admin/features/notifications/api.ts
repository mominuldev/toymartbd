import { keepPreviousData, useInfiniteQuery, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import type { Schema } from '@toymart/api-types';
import type { PreviewNotificationTemplateRequestInput, StoreNotificationTemplateRequestInput, UpdateNotificationTemplateRequestInput } from '@toymart/api-types/zod';
import { api } from '@/lib/api';

export type NotificationTemplate = Schema<'NotificationTemplate'>;
export type NotificationLog = Schema<'NotificationLog'>;
export type NotificationSummary = Schema<'NotificationSummary'>;
export type TemplatePreview = Schema<'NotificationTemplatePreview'>;
export type Channel = NotificationTemplate['channel'];
export type LogStatus = NotificationLog['status'];
/** The PUT body — the generated request type, never a hand-written copy (rule 27). */
export type TemplateInput = UpdateNotificationTemplateRequestInput;
export type TemplateVersion = Schema<'NotificationTemplateVersion'>;
export type TemplateKind = NotificationTemplate['kind'];
export type WhatsAppStatus = NonNullable<NotificationTemplate['whatsapp_status']>;
/** The POST body for a staff-made template — generated, never hand-written (rule 27). */
export type NewTemplateInput = StoreNotificationTemplateRequestInput;

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

/* ── Message templates library (P7) ─────────────────────────────────────────────── */

/** Library groups: the opt-out category for system templates, the kind for staff-made ones. */
export type LibraryGroup = 'order_updates' | 'security' | 'marketing' | 'manual';
export const libraryGroups: LibraryGroup[] = ['order_updates', 'security', 'marketing', 'manual'];
export type LibraryStatus = '' | 'active' | 'off' | 'archived';
export type LibraryFilters = { channel: Channel | ''; group: LibraryGroup | ''; status: LibraryStatus };
export const whatsAppStatuses: WhatsAppStatus[] = ['draft', 'submitted', 'approved'];
/** The generic variables a staff-made template may choose from — the generated enum, not a copy. */
export const customVariables = ['name', 'order_number', 'store_name', 'hotline', 'store_url', 'track_url'] as const satisfies NewTemplateInput['variables'];

export function groupOf(tpl: Pick<NotificationTemplate, 'kind' | 'category'>): LibraryGroup {
    if (tpl.kind === 'manual') return 'manual';
    if (tpl.kind === 'marketing') return 'marketing';
    return tpl.category;
}

/** Every filter goes to the server's allowlist (`filter[...]`); nothing is filtered by guesswork here. */
export function libraryPath(filters: LibraryFilters): string {
    const group = filters.group;
    return `/notifications/templates${query({
        'filter[channel]': filters.channel,
        'filter[category]': group === 'order_updates' || group === 'security' || group === 'marketing' ? group : '',
        'filter[kind]': group === 'manual' ? 'manual' : group === 'order_updates' || group === 'security' ? 'system' : '',
        'filter[active]': filters.status === 'active' ? '1' : filters.status === 'off' ? '0' : '',
        'filter[archived]': filters.status === 'archived' ? '1' : '',
    })}`;
}

export function useLibrary(filters: LibraryFilters) {
    return useQuery({
        queryKey: ['notifications', 'templates', 'library', filters] as const,
        queryFn: () => api<{ data: NotificationTemplate[] }>(libraryPath(filters)).then((r) => r.data),
        placeholderData: keepPreviousData,
    });
}

export function useTemplate(id: number) {
    return useQuery({
        queryKey: ['notifications', 'templates', 'one', id] as const,
        queryFn: () => api<NotificationTemplate>(`/notifications/templates/${id}`),
    });
}

export function useVersions(id: number) {
    return useQuery({
        queryKey: ['notifications', 'templates', 'versions', id] as const,
        queryFn: () => api<{ data: TemplateVersion[] }>(`/notifications/templates/${id}/versions`).then((r) => r.data),
    });
}

/** Any library write refreshes every template view (list, editor, Automations, versions). */
function useLibraryMutation<TInput, TOutput>(fn: (input: TInput) => Promise<TOutput>) {
    const client = useQueryClient();
    return useMutation({ mutationFn: fn, onSuccess: () => void client.invalidateQueries({ queryKey: ['notifications', 'templates'] }) });
}

export function useUpdateTemplate(id: number) {
    return useLibraryMutation((input: TemplateInput) =>
        api<NotificationTemplate>(`/notifications/templates/${id}`, { method: 'PUT', body: JSON.stringify(input) }),
    );
}

export function useCreateTemplate() {
    return useLibraryMutation((input: NewTemplateInput) =>
        api<{ data: NotificationTemplate[] }>('/notifications/templates', { method: 'POST', body: JSON.stringify(input) }).then((r) => r.data),
    );
}

export function useArchiveTemplate(id: number) {
    return useLibraryMutation((archive: boolean) =>
        api<{ data: NotificationTemplate[] }>(`/notifications/templates/${id}/${archive ? 'archive' : 'unarchive'}`, { method: 'POST' }).then((r) => r.data),
    );
}

export function useRestoreVersion(id: number) {
    return useLibraryMutation((version: number) =>
        api<NotificationTemplate>(`/notifications/templates/${id}/versions/${version}/restore`, { method: 'POST' }),
    );
}

export function useSetWhatsAppStatus(id: number) {
    return useLibraryMutation((status: WhatsAppStatus) =>
        api<NotificationTemplate>(`/notifications/templates/${id}/whatsapp-status`, { method: 'PUT', body: JSON.stringify({ status }) }),
    );
}

/** Switches every channel of one template on or off — one PUT per row, each the full document. */
export function useSetTemplatesActive() {
    return useLibraryMutation(({ templates, active }: { templates: NotificationTemplate[]; active: boolean }) =>
        Promise.all(
            templates.map((tpl) =>
                api<NotificationTemplate>(`/notifications/templates/${tpl.id}`, {
                    method: 'PUT',
                    body: JSON.stringify({
                        body_en: tpl.body_en,
                        body_bn: tpl.body_bn,
                        is_active: active,
                        subject_en: tpl.subject_en,
                        subject_bn: tpl.subject_bn,
                        preheader_en: tpl.preheader_en,
                        preheader_bn: tpl.preheader_bn,
                    } satisfies TemplateInput),
                }),
            ),
        ),
    );
}
