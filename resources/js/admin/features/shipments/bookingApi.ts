import { useInfiniteQuery, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import type { Schema } from '@toymart/api-types';
import type { BulkRequestShipmentsRequestInput } from '@toymart/api-types/zod';
import { api } from '@/lib/api';

/*
 * Courier booking queue (Figma B · 07, docs/api/booking-queue.md). Everything shown — counts,
 * weights, COD, which courier can take which order and why not — comes from the server
 * (rule 1). Booking goes through the existing `POST /shipments/bulk` with an Idempotency-Key
 * minted once per confirmation, so a retry after a timeout replays instead of booking twice
 * (rule 17). Bulk returns no consignment ids: the rows move to "Submitted — awaiting ID" (rule 24d).
 */

export type BookingSummary = Schema<'BookingQueueSummary'>;
export type BookingCourier = BookingSummary['couriers'][number];
export type BookingCounts = BookingSummary['counts'];
export type ReadyOrder = Schema<'BookingQueueOrder'>;
export type BlockedReason = NonNullable<ReadyOrder['couriers'][number]['blocked_reason']>;
export type QueueShipment = Schema<'BookingQueueShipment'>;
export type UnmappedArea = Schema<'BookingQueueUnmappedArea'>;
export type BulkInput = BulkRequestShipmentsRequestInput;
export type BulkResult = Schema<'ShipmentBulkResult'>;
export type BulkResponse = { data: BulkResult[]; meta: { requested: number; failed: number } };

export const bookingTabs = ['ready', 'submitted', 'booked_today', 'failed', 'unmapped'] as const;
export type BookingTab = (typeof bookingTabs)[number];
export type ShipmentTab = Extract<BookingTab, 'submitted' | 'booked_today' | 'failed'>;

type Page<T> = { data: T[]; meta?: { next_cursor?: string | null } };

const BASE = '/shipments/booking-queue';

export const bookingKeys = {
    all: ['shipments', 'booking-queue'] as const,
    summary: ['shipments', 'booking-queue', 'summary'] as const,
    ready: ['shipments', 'booking-queue', 'ready'] as const,
    shipments: (tab: ShipmentTab) => ['shipments', 'booking-queue', 'shipments', tab] as const,
    unmapped: ['shipments', 'booking-queue', 'unmapped'] as const,
};

export function useBookingSummary(enabled = true) {
    return useQuery({ queryKey: bookingKeys.summary, queryFn: () => api<BookingSummary>(BASE), enabled });
}

function fetchPage<T>(path: string, cursor: string | null): Promise<Page<T>> {
    const join = path.includes('?') ? '&' : '?';
    return api<Page<T>>(cursor ? `${path}${join}cursor=${encodeURIComponent(cursor)}` : path);
}

export function useReadyOrders(enabled = true) {
    return useInfiniteQuery({
        queryKey: bookingKeys.ready,
        queryFn: ({ pageParam }: { pageParam: string | null }) => fetchPage<ReadyOrder>(`${BASE}/ready`, pageParam),
        initialPageParam: null as string | null,
        getNextPageParam: (last: Page<ReadyOrder>) => last.meta?.next_cursor ?? null,
        enabled,
    });
}

export function useQueueShipments(tab: ShipmentTab, enabled = true) {
    return useInfiniteQuery({
        queryKey: bookingKeys.shipments(tab),
        queryFn: ({ pageParam }: { pageParam: string | null }) => fetchPage<QueueShipment>(`${BASE}/shipments?filter[tab]=${tab}`, pageParam),
        initialPageParam: null as string | null,
        getNextPageParam: (last: Page<QueueShipment>) => last.meta?.next_cursor ?? null,
        enabled,
    });
}

export function useUnmappedAreas(enabled = true) {
    return useQuery({ queryKey: bookingKeys.unmapped, queryFn: () => api<{ data: UnmappedArea[] }>(`${BASE}/unmapped`).then((r) => r.data), enabled });
}

/** `POST /shipments/bulk` — one call per courier, its key minted once per confirmation. */
export function useQueueBulkBook() {
    const client = useQueryClient();
    return useMutation({
        mutationFn: ({ input, idempotencyKey }: { input: BulkInput; idempotencyKey: string }) =>
            api<BulkResponse>('/shipments/bulk', { method: 'POST', body: JSON.stringify(input), headers: { 'Idempotency-Key': idempotencyKey } }),
        onSettled: () => {
            void client.invalidateQueries({ queryKey: ['shipments'] });
            void client.invalidateQueries({ queryKey: ['orders'] });
        },
    });
}
