import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import type { Schema } from '@toymart/api-types';
import type { CancelOrderRequestInput, HoldOrderRequestInput, StoreOrderNoteRequestInput } from '@toymart/api-types/zod';
import { api } from '@/lib/api';

export type OrderRow = Schema<'OrderListItem'>;
export type Order = Schema<'Order'>;
export type OrderNote = Schema<'OrderNote'>;
export type BulkResult = Schema<'OrderBulkResult'>;
export type OrderStatus = OrderRow['status'];
export type PaymentStatus = OrderRow['payment_status'];
export type FulfillmentStatus = OrderRow['fulfillment_status'];
export type OrderSource = OrderRow['source'];
export type RiskLevel = NonNullable<OrderRow['risk_level']>;
export type Anomaly = NonNullable<OrderRow['anomaly']>;
export type OrderAction = Order['available_actions'][number];
export type TimelineEntry = Order['timeline'][number];
/** POST bodies — the generated request types, never hand-written copies (rule 27). */
export type CancelInput = CancelOrderRequestInput;
export type HoldInput = HoldOrderRequestInput;
export type NoteInput = StoreOrderNoteRequestInput;
export type CancelReason = CancelInput['reason'];

export const statuses: OrderStatus[] = [
    'pending',
    'on_hold',
    'confirmed',
    'processing',
    'ready_to_ship',
    'shipped',
    'delivered',
    'completed',
    'partially_returned',
    'returned',
    'cancelled',
];
export const paymentStatuses: PaymentStatus[] = ['unpaid', 'pending', 'partially_paid', 'paid', 'partially_refunded', 'refunded', 'failed'];
export const sources: OrderSource[] = ['web', 'admin', 'phone', 'facebook', 'whatsapp'];
export const riskLevels: RiskLevel[] = ['low', 'medium', 'high'];
export const cancelReasons: CancelReason[] = [
    'customer_request',
    'customer_unreachable',
    'fake_order',
    'duplicate_order',
    'out_of_stock',
    'pricing_error',
    'address_unserviceable',
    'payment_failed',
    'other',
];

/** A board tab: one status, everything, the verification queue, or the anomalies. */
export type BoardTab = OrderStatus | 'all' | 'verify' | 'anomalies';
export type Counts = Partial<Record<OrderStatus | 'all' | 'verify' | 'anomalies', number>>;

export type BoardFilters = {
    tab: BoardTab;
    payment: PaymentStatus | '';
    source: OrderSource | '';
    risk: RiskLevel | '';
    from: string;
    to: string;
    search: string;
    onlyAnomalies: boolean;
};

export type OrdersPage = {
    data: OrderRow[];
    meta: { next_cursor?: string | null; prev_cursor?: string | null; counts: Counts };
};

type BulkResponse = { data: BulkResult[]; meta: { done: number; failed: number } };

export const orderKeys = {
    all: ['orders'] as const,
    list: (filters: BoardFilters, cursor: string | null) => ['orders', 'list', filters, cursor] as const,
    detail: (id: number) => ['orders', 'detail', id] as const,
};

/** Every filter is a server-side allowlisted parameter (rule 16); nothing is filtered in the browser. */
export function ordersPath(filters: BoardFilters, cursor: string | null): string {
    const params = new URLSearchParams();
    const set = (key: string, value: string | null | undefined) => {
        if (value) params.set(key, value);
    };
    if (filters.tab === 'verify') set('filter[verification_status]', 'pending');
    else if (filters.tab === 'anomalies') set('filter[anomaly]', '1');
    else if (filters.tab !== 'all') set('filter[status]', filters.tab);
    if (filters.onlyAnomalies && filters.tab !== 'anomalies') set('filter[anomaly]', '1');
    set('filter[payment_status]', filters.payment);
    set('filter[source]', filters.source);
    set('filter[risk_level]', filters.risk);
    set('filter[placed_from]', filters.from);
    set('filter[placed_to]', filters.to);
    set('filter[search]', filters.search.trim());
    set('cursor', cursor);
    const text = params.toString();
    return `/orders${text ? `?${text}` : ''}`;
}

/** The board: one cursor page at a time. Counts refresh every 30 s (Figma 205:681 subtitle). */
export function useOrders(filters: BoardFilters, cursor: string | null) {
    return useQuery({
        queryKey: orderKeys.list(filters, cursor),
        queryFn: () => api<OrdersPage>(ordersPath(filters, cursor)),
        placeholderData: keepPreviousData,
        refetchInterval: 30_000,
    });
}

export function useOrder(id: number) {
    return useQuery({ queryKey: orderKeys.detail(id), queryFn: () => api<Order>(`/orders/${id}`) });
}

export type WorkflowMove = 'confirm' | 'release' | 'processing' | 'ready_to_ship';

const movePath: Record<WorkflowMove, string> = {
    confirm: 'confirm',
    release: 'release',
    processing: 'processing',
    ready_to_ship: 'ready-to-ship',
};

type Move =
    | { action: WorkflowMove; note?: string }
    | { action: 'hold'; input: HoldInput }
    | { action: 'cancel'; input: CancelInput };

/**
 * One state-machine move on one order. The server answers with the whole order, which
 * replaces the cached detail; a refused move is an ApiError 409 (`conflict`) for the caller.
 */
export function useOrderMove(id: number) {
    const client = useQueryClient();

    return useMutation({
        mutationFn: (move: Move) => {
            const path = move.action === 'hold' ? 'hold' : move.action === 'cancel' ? 'cancel' : movePath[move.action];
            const body = move.action === 'hold' || move.action === 'cancel' ? move.input : move.note ? { note: move.note } : {};
            return api<Order>(`/orders/${id}/${path}`, { method: 'POST', body: JSON.stringify(body) });
        },
        onSuccess: (order) => {
            client.setQueryData(orderKeys.detail(id), order);
            void client.invalidateQueries({ queryKey: ['orders', 'list'] });
        },
        onError: () => void client.invalidateQueries({ queryKey: orderKeys.all }),
    });
}

/**
 * Re-check an online payment with its gateway (P4-PAY-03): the server re-queries the provider
 * and answers with the whole order. A fresh Idempotency-Key per click (rule 17).
 */
export function useVerifyPayment(id: number) {
    const client = useQueryClient();

    return useMutation({
        mutationFn: (paymentId: number) =>
            api<Order>(`/orders/${id}/payments/${paymentId}/verify`, { method: 'POST', headers: { 'Idempotency-Key': crypto.randomUUID() } }),
        onSuccess: (order) => {
            client.setQueryData(orderKeys.detail(id), order);
            void client.invalidateQueries({ queryKey: ['orders', 'list'] });
        },
    });
}

export function useAddNote(id: number) {
    const client = useQueryClient();

    return useMutation({
        mutationFn: (input: NoteInput) => api<OrderNote>(`/orders/${id}/notes`, { method: 'POST', body: JSON.stringify(input) }),
        onSuccess: () => void client.invalidateQueries({ queryKey: orderKeys.detail(id) }),
    });
}

/** Bulk confirm: one result per order, partial failure reported per order (P4-ORD-08). */
export function useBulkConfirm() {
    const client = useQueryClient();

    return useMutation({
        mutationFn: (orderIds: number[]) => api<BulkResponse>('/orders/bulk/confirm', { method: 'POST', body: JSON.stringify({ order_ids: orderIds }) }),
        onSettled: () => void client.invalidateQueries({ queryKey: orderKeys.all }),
    });
}
