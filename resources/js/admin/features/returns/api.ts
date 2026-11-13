import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import type { Schema } from '@toymart/api-types';
import type { InspectReturnRequestInput, PayRefundRequestInput, StoreReturnRequestInput } from '@toymart/api-types/zod';
import { api } from '@/lib/api';

export type OrderReturn = Schema<'OrderReturn'>;
export type Refund = Schema<'Refund'>;
export type RefundPanel = Schema<'OrderRefundPanel'>;
export type ReturnStatus = OrderReturn['status'];
export type ReturnReason = OrderReturn['reason'];
export type RefundStatus = Refund['status'];
/** Request bodies — the generated types, never hand-written copies (rule 27). */
export type OpenReturnInput = StoreReturnRequestInput;
export type InspectInput = InspectReturnRequestInput;
export type PayInput = PayRefundRequestInput;

/** The queue's tabs, in Figma 241:35772 order (exchanges are not built yet). */
export const returnTabs: ReturnStatus[] = ['requested', 'received', 'inspected', 'closed', 'rejected'];
export const returnReasons: ReturnReason[] = ['damaged_in_transit', 'defective', 'wrong_item', 'missing_parts', 'not_as_described', 'changed_mind', 'other'];
/** How staff can send money back by hand for a COD order. */
export const payoutMethods = ['bkash', 'nagad', 'cash', 'bank_transfer'] as const;
export type PayoutMethod = (typeof payoutMethods)[number];

type Page<T> = { data: T[]; meta: { next_cursor?: string | null; prev_cursor?: string | null; counts: Record<ReturnStatus, number> } };

export const returnKeys = {
    all: ['returns'] as const,
    list: (status: ReturnStatus, search: string, cursor: string | null) => ['returns', 'list', status, search, cursor] as const,
    detail: (id: number) => ['returns', 'detail', id] as const,
    panel: (orderId: number) => ['returns', 'order', orderId] as const,
};

/** Status and search are server-side allowlisted filters (rule 16). */
export function returnsPath(status: ReturnStatus, search: string, cursor: string | null): string {
    const params = new URLSearchParams({ 'filter[status]': status });
    if (search) params.set('filter[search]', search);
    if (cursor) params.set('cursor', cursor);
    return `/returns?${params.toString()}`;
}

export function useReturns(status: ReturnStatus, search: string, cursor: string | null) {
    return useQuery({
        queryKey: returnKeys.list(status, search, cursor),
        queryFn: () => api<Page<OrderReturn>>(returnsPath(status, search, cursor)),
        placeholderData: keepPreviousData,
    });
}

export function useReturn(id: number | null) {
    return useQuery({
        queryKey: returnKeys.detail(id ?? 0),
        queryFn: () => api<OrderReturn>(`/returns/${id}`),
        enabled: id !== null,
    });
}

export function useRefundPanel(orderId: number) {
    return useQuery({ queryKey: returnKeys.panel(orderId), queryFn: () => api<RefundPanel>(`/orders/${orderId}/returns`) });
}

/** Every step can move stock, the order's statuses and money: refresh returns and orders after it. */
function useInvalidate() {
    const client = useQueryClient();
    return () => {
        void client.invalidateQueries({ queryKey: returnKeys.all });
        void client.invalidateQueries({ queryKey: ['orders'] });
    };
}

const post = <T,>(path: string, body?: unknown, headers?: Record<string, string>) =>
    api<T>(path, { method: 'POST', body: body === undefined ? undefined : JSON.stringify(body), headers });

export function useOpenReturn(orderId: number) {
    const invalidate = useInvalidate();
    return useMutation({ mutationFn: (input: OpenReturnInput) => post<OrderReturn>(`/orders/${orderId}/returns`, input), onSuccess: invalidate });
}

export function useReturnStep() {
    const invalidate = useInvalidate();
    return useMutation({
        mutationFn: ({ id, step, body }: { id: number; step: 'receive' | 'inspect' | 'reject'; body?: InspectInput | { note: string } }) => post<OrderReturn>(`/returns/${id}/${step}`, body),
        onSuccess: invalidate,
    });
}

export function useRefundDecision() {
    const invalidate = useInvalidate();
    return useMutation({
        mutationFn: ({ id, decision, note }: { id: number; decision: 'approve' | 'reject'; note?: string }) =>
            post<Refund>(`/refunds/${id}/${decision}`, decision === 'reject' ? { note } : undefined),
        onSuccess: invalidate,
    });
}

/**
 * Pays an approved refund out. `idempotencyKey` is minted once per payout form, so a retry
 * after a dropped connection replays the first answer instead of paying twice.
 */
export function usePayRefund() {
    const invalidate = useInvalidate();
    return useMutation({
        mutationFn: ({ id, input, idempotencyKey }: { id: number; input: PayInput; idempotencyKey: string }) =>
            post<Refund>(`/refunds/${id}/pay`, input, { 'Idempotency-Key': idempotencyKey }),
        onSuccess: invalidate,
    });
}
