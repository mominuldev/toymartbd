import { keepPreviousData, useInfiniteQuery, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import type { Schema } from '@toymart/api-types';
import type { SendTestTrackingEventRequestInput, UpdateTrackingSettingsRequestInput } from '@toymart/api-types/zod';
import { api } from '@/lib/api';

/** GET: effective values; secrets only as set / source / last-4 hint, never the value. */
export type TrackingSettings = Schema<'TrackingSettings'>;
export type TrackingTestResult = Schema<'TrackingTestResult'>;
export type TrackingEvent = Schema<'TrackingEvent'>;
export type TrackingEventDetail = Schema<'TrackingEventDetail'>;
export type TrackingSummary = Schema<'TrackingSummary'>;
export type DestinationHealth = TrackingSummary['destinations'][number];
/** The PUT body — the generated request type, never a hand-written copy (rule 27). */
export type TrackingSettingsInput = UpdateTrackingSettingsRequestInput;
export type TrackingTestInput = SendTestTrackingEventRequestInput;

export type Destination = TrackingTestResult['destination'];
export type DispatchStatus = TrackingEvent['ga4']['status'];
export type EventName = TrackingEvent['event_name'];
export type EventSource = TrackingEvent['source'];
export type PurchaseTrigger = TrackingSettings['purchase_trigger'];
export type ConsentMode = TrackingSettings['consent_mode'];

export const destinations: Destination[] = ['ga4', 'meta'];
export const eventNames: EventName[] = ['page_view', 'view_item', 'add_to_cart', 'begin_checkout', 'purchase', 'refund'];
export const eventSources: EventSource[] = ['browser', 'server'];
export const dispatchStatuses: DispatchStatus[] = ['pending', 'sent', 'failed', 'skipped_consent', 'skipped_disabled', 'skipped_stale', 'not_applicable'];

/** The log's allowlisted filters (the API answers 400 to anything else). Dates are Dhaka Y-m-d. */
export type EventFilters = {
    event_name: EventName | '';
    source: EventSource | '';
    ga4_status: DispatchStatus | '';
    meta_status: DispatchStatus | '';
    date_from: string;
    date_to: string;
    order_number: string;
};

export const emptyFilters: EventFilters = { event_name: '', source: '', ga4_status: '', meta_status: '', date_from: '', date_to: '', order_number: '' };

type Page<T> = { data: T[]; meta: { next_cursor?: string | null } };

const keys = {
    settings: ['settings', 'tracking'] as const,
    events: (filters: EventFilters) => ['tracking', 'events', filters] as const,
    event: (id: number) => ['tracking', 'event', id] as const,
    summary: ['tracking', 'summary'] as const,
};

/** `filter[x]=…` for every non-empty filter, plus the cursor. */
export function eventsPath(filters: EventFilters, cursor: string | null = null): string {
    const params = new URLSearchParams();
    for (const [name, value] of Object.entries(filters)) {
        if (value !== '') params.set(`filter[${name}]`, value);
    }
    if (cursor) params.set('cursor', cursor);
    const query = params.toString();
    return `/tracking/events${query ? `?${query}` : ''}`;
}

export function useTrackingSettings(enabled: boolean) {
    return useQuery({ queryKey: keys.settings, queryFn: () => api<TrackingSettings>('/settings/tracking'), enabled });
}

export function useSaveTrackingSettings() {
    const client = useQueryClient();
    return useMutation({
        mutationFn: (input: TrackingSettingsInput) => api<TrackingSettings>('/settings/tracking', { method: 'PUT', body: JSON.stringify(input) }),
        onSuccess: (saved) => {
            client.setQueryData(keys.settings, saved);
            void client.invalidateQueries({ queryKey: keys.summary });
        },
    });
}

/** One synthetic event through the destination's validation path, with the SAVED settings. */
export function useSendTestEvent() {
    return useMutation({
        mutationFn: (input: TrackingTestInput) => api<TrackingTestResult>('/settings/tracking/test', { method: 'POST', body: JSON.stringify(input) }),
    });
}

export function useTrackingSummary(enabled: boolean) {
    return useQuery({ queryKey: keys.summary, queryFn: () => api<TrackingSummary>('/tracking/summary'), enabled });
}

export function useTrackingEvents(filters: EventFilters, enabled: boolean) {
    return useInfiniteQuery({
        queryKey: keys.events(filters),
        queryFn: ({ pageParam }) => api<Page<TrackingEvent>>(eventsPath(filters, pageParam)),
        initialPageParam: null as string | null,
        getNextPageParam: (last) => last.meta.next_cursor ?? null,
        placeholderData: keepPreviousData,
        enabled,
    });
}

export function useTrackingEvent(id: number | null) {
    return useQuery({ queryKey: keys.event(id ?? 0), queryFn: () => api<TrackingEventDetail>(`/tracking/events/${id}`), enabled: id !== null });
}
