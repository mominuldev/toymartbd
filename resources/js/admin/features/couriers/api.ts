import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import type { Schema } from '@toymart/api-types';
import type {
    BulkRequestShipmentsRequestInput,
    RequestShipmentRequestInput,
    UpdateCourierCredentialsRequestInput,
    UpdateCourierProviderRequestInput,
    UpdateCourierRatesRequestInput,
    UpdateDeliverySettingsRequestInput,
} from '@toymart/api-types/zod';
import { api, csrfToken, apiLocale } from '@/lib/api';

/*
 * Courier settings, the booking panel's server preview and the shipment endpoints (P6).
 * Every number — charges, COD, weights — comes from the server (rule 1); bodies are the
 * generated request types (rule 27). Booking, retry and cancel carry an Idempotency-Key
 * minted once per panel opening, so a retry after a timeout replays instead of booking twice.
 */

export type CourierProvider = Schema<'CourierProviderSettings'>;
export type DeliverySettings = Schema<'DeliverySettings'>;
export type ShipmentQuote = Schema<'ShipmentQuote'>;
export type QuoteProvider = ShipmentQuote['providers'][number];
export type QuoteOrder = ShipmentQuote['orders'][number];
export type OrderQuote = NonNullable<QuoteOrder['quotes']>[number];
export type CostEstimate = NonNullable<OrderQuote['estimate']>;
export type Trust = CourierProvider['trust'];
export type RatesInput = UpdateCourierRatesRequestInput;
export type CredentialsInput = UpdateCourierCredentialsRequestInput;
export type ProviderInput = UpdateCourierProviderRequestInput;
export type DeliveryInput = UpdateDeliverySettingsRequestInput;

/** Normalised shipment states (App\Domain\Fulfilment\Enums\ShipmentStatus). */
export const shipmentStatuses = [
    'pending',
    'submitted',
    'booked',
    'picked_up',
    'in_transit',
    'out_for_delivery',
    'delivered',
    'partially_delivered',
    'returned',
    'cancelled',
    'on_hold',
    'failed',
] as const;
export type ShipmentStatus = (typeof shipmentStatuses)[number];

export type TrackingEvent = {
    id: number;
    normalised_status: string | null;
    provider_status: string | null;
    message: string | null;
    location: string | null;
    source: 'webhook' | 'poll' | 'manual' | string;
    occurred_at: string | null;
};

/**
 * `ShipmentResource` (docs/api/shipments.md) — the generated schema (rule 27). Tracking events
 * are not part of it yet (no `courier_tracking_events` endpoint), so `events` is optional and
 * the card says "no tracking events yet" rather than inventing any.
 */
export type Shipment = Schema<'Shipment'> & { events?: TrackingEvent[] };

export type ShipmentPage = { data: Shipment[]; meta?: { next_cursor?: string | null } };
export type BulkBookResult = Schema<'ShipmentBulkResult'>;
export type BulkBookResponse = { data: BulkBookResult[]; meta: { requested: number; failed: number } };

export const courierKeys = {
    settings: ['couriers', 'settings'] as const,
    delivery: ['couriers', 'delivery'] as const,
    quote: (orders: { id: number; weight_grams?: number | null }[]) => ['couriers', 'quote', orders] as const,
    forOrder: (orderId: number) => ['shipments', 'order', orderId] as const,
    failed: ['shipments', 'failed'] as const,
};

/* ---------- Settings ---------- */

export function useCourierSettings(enabled = true) {
    return useQuery({ queryKey: courierKeys.settings, queryFn: () => api<{ data: CourierProvider[] }>('/settings/couriers').then((r) => r.data), enabled });
}

export function useDeliverySettings(enabled = true) {
    return useQuery({ queryKey: courierKeys.delivery, queryFn: () => api<DeliverySettings>('/settings/delivery'), enabled });
}

function useProviderWrite<TInput>(path: (id: number) => string, method: 'PUT' | 'PATCH') {
    const client = useQueryClient();
    return useMutation({
        mutationFn: ({ id, input }: { id: number; input: TInput }) => api<CourierProvider>(path(id), { method, body: JSON.stringify(input) }),
        onSuccess: (saved) => {
            client.setQueryData<CourierProvider[]>(courierKeys.settings, (rows) => rows?.map((row) => (row.id === saved.id ? saved : row)));
            // Making one courier the default clears the flag on the others: re-read them all.
            void client.invalidateQueries({ queryKey: courierKeys.settings });
            void client.invalidateQueries({ queryKey: ['couriers', 'quote'] });
        },
    });
}

export const useSaveRates = () => useProviderWrite<RatesInput>((id) => `/settings/couriers/${id}/rates`, 'PUT');
export const useSaveCredentials = () => useProviderWrite<CredentialsInput>((id) => `/settings/couriers/${id}/credentials`, 'PUT');
export const useUpdateProvider = () => useProviderWrite<ProviderInput>((id) => `/settings/couriers/${id}`, 'PATCH');

export function useSaveDelivery() {
    const client = useQueryClient();
    return useMutation({
        mutationFn: (input: DeliveryInput) => api<DeliverySettings>('/settings/delivery', { method: 'PUT', body: JSON.stringify(input) }),
        onSuccess: (saved) => client.setQueryData(courierKeys.delivery, saved),
    });
}

/* ---------- Booking ---------- */

/** The server's preview for these orders across every courier. Re-asked when a weight changes. */
export function useShipmentQuote(orders: { id: number; weight_grams?: number | null }[], enabled: boolean) {
    return useQuery({
        queryKey: courierKeys.quote(orders),
        queryFn: () => api<ShipmentQuote>('/shipments/quote', { method: 'POST', body: JSON.stringify({ orders }) }),
        enabled: enabled && orders.length > 0,
        placeholderData: keepPreviousData,
    });
}

/** The booking bodies — the generated request types, never hand-written copies (rule 27). */
export type BookInput = RequestShipmentRequestInput;
export type BulkBookInput = BulkRequestShipmentsRequestInput;

function invalidateShipments(client: ReturnType<typeof useQueryClient>) {
    void client.invalidateQueries({ queryKey: ['shipments'] });
    void client.invalidateQueries({ queryKey: ['orders'] });
}

export function useBookShipment(orderId: number) {
    const client = useQueryClient();
    return useMutation({
        mutationFn: ({ input, idempotencyKey }: { input: BookInput; idempotencyKey: string }) =>
            api<Shipment>(`/orders/${orderId}/shipments`, { method: 'POST', body: JSON.stringify(input), headers: { 'Idempotency-Key': idempotencyKey } }),
        onSettled: () => invalidateShipments(client),
    });
}

export function useBulkBook() {
    const client = useQueryClient();
    return useMutation({
        mutationFn: ({ input, idempotencyKey }: { input: BulkBookInput; idempotencyKey: string }) =>
            api<BulkBookResponse>('/shipments/bulk', { method: 'POST', body: JSON.stringify(input), headers: { 'Idempotency-Key': idempotencyKey } }),
        onSettled: () => invalidateShipments(client),
    });
}

export function useOrderShipments(orderId: number, enabled = true) {
    return useQuery({
        queryKey: courierKeys.forOrder(orderId),
        queryFn: () => api<ShipmentPage>(`/shipments?filter[order_id]=${orderId}`).then((r) => r.data),
        enabled,
    });
}

export function useFailedShipments(enabled = true) {
    return useQuery({ queryKey: courierKeys.failed, queryFn: () => api<ShipmentPage>('/shipments/failed').then((r) => r.data), enabled });
}

function useShipmentMove(move: 'retry' | 'cancel') {
    const client = useQueryClient();
    return useMutation({
        mutationFn: (shipmentId: number) => api<Shipment>(`/shipments/${shipmentId}/${move}`, { method: 'POST', headers: { 'Idempotency-Key': crypto.randomUUID() } }),
        onSettled: () => invalidateShipments(client),
    });
}

export const useRetryShipment = () => useShipmentMove('retry');
export const useCancelShipment = () => useShipmentMove('cancel');

/* ---------- Labels ---------- */

/** One label: a plain GET the browser opens in a new tab (the session cookie authorises it). */
export function labelUrl(shipmentId: number, locale: string): string {
    return `/api/v1/admin/shipments/${shipmentId}/label?locale=${locale === 'bn' ? 'bn' : 'en'}`;
}

/** Bulk labels: POST, then open the PDF blob. Returns the ids the server skipped (no consignment yet). */
export async function printLabels(shipmentIds: number[], locale: string): Promise<number[]> {
    const response = await fetch('/api/v1/admin/shipments/labels', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { Accept: 'application/pdf', 'Content-Type': 'application/json', 'Accept-Language': apiLocale(), 'X-XSRF-TOKEN': csrfToken() },
        body: JSON.stringify({ shipment_ids: shipmentIds, locale: locale === 'bn' ? 'bn' : 'en' }),
    });
    if (!response.ok) throw new Error(String(response.status));
    const url = URL.createObjectURL(await response.blob());
    window.open(url, '_blank', 'noopener');
    window.setTimeout(() => URL.revokeObjectURL(url), 60_000);
    return (response.headers.get('X-Shipments-Skipped') ?? '').split(',').filter(Boolean).map(Number);
}
