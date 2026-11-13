import { useMutation, useQueryClient } from '@tanstack/react-query';
import type { DeliverOrderRequestInput, ShipOrderRequestInput } from '@toymart/api-types/zod';
import { api, withIdempotency } from '@/lib/api';
import { orderKeys, type BulkResult, type Order } from './api';

/*
 * Manual fulfilment (P5, until Phase 6 courier booking): mark shipped consumes stock, mark
 * delivered recognises revenue and collects COD — all on the server (rule 1). Every call
 * carries an Idempotency-Key (rule 17); a dialog mints one per opening, so a retry after a
 * timeout replays the first answer instead of shipping twice.
 */

export type ShipInput = ShipOrderRequestInput;
export type DeliverInput = DeliverOrderRequestInput;
export type FulfilMove = 'ship' | 'deliver';
type BulkResponse = { data: BulkResult[]; meta: { done: number; failed: number } };

export function useFulfilOrder(id: number) {
    const client = useQueryClient();

    return useMutation({
        mutationFn: ({ move, input, idempotencyKey }: { move: FulfilMove; input: ShipInput | DeliverInput; idempotencyKey: string }) =>
            api<Order>(`/orders/${id}/${move}`, { method: 'POST', body: JSON.stringify(input), headers: { 'Idempotency-Key': idempotencyKey } }),
        onSuccess: (order) => {
            client.setQueryData(orderKeys.detail(id), order);
            void client.invalidateQueries({ queryKey: ['orders', 'list'] });
        },
        onError: () => void client.invalidateQueries({ queryKey: orderKeys.all }),
    });
}

/** Bulk mark shipped / delivered: one result per order, partial failure reported per order. */
export function useBulkFulfil(move: FulfilMove) {
    const client = useQueryClient();

    return useMutation({
        mutationFn: (orderIds: number[]) => api<BulkResponse>(`/orders/bulk/${move}`, withIdempotency({ method: 'POST', body: JSON.stringify({ order_ids: orderIds }) })),
        onSettled: () => void client.invalidateQueries({ queryKey: orderKeys.all }),
    });
}
