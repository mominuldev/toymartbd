import { keepPreviousData, useInfiniteQuery, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import type { Schema } from '@toymart/api-types';
import type {
    AcknowledgeInventoryAlertRequestInput,
    ApproveStockAdjustmentRequestInput,
    RejectStockAdjustmentRequestInput,
    StoreStockAdjustmentRequestInput,
    StoreStockTransferRequestInput,
} from '@toymart/api-types/zod';
import { api } from '@/lib/api';

export type StockRow = Schema<'InventoryStockRow'>;
export type Movement = Schema<'InventoryMovement'>;
export type InventorySummary = Schema<'InventorySummary'>;
export type InventoryLocation = InventorySummary['locations'][number];
export type Reconciliation = Schema<'InventoryReconciliation'>;
export type Adjustment = Schema<'InventoryAdjustment'>;
export type MovementType = Movement['type'];
export type LowStockCount = Schema<'InventoryLowStockCount'>;
export type AdjustmentReason = Adjustment['reason'];
/** The POST body — the generated request type, never a hand-written copy (rule 27). */
export type AdjustmentInput = StoreStockAdjustmentRequestInput;
export type AdjustmentStatus = Adjustment['status'];
export type Transfer = Schema<'InventoryTransfer'>;
export type TransferInput = StoreStockTransferRequestInput;
export type InventoryAlert = Schema<'InventoryAlert'>;
export type AlertKind = InventoryAlert['kind'];

export const stockStates = ['low', 'out', 'overstock', 'idle'] as const;
export type StockState = (typeof stockStates)[number];

export const movementTypes: MovementType[] = [
    'purchase_in',
    'sale_out',
    'sale_cancelled_in',
    'customer_return_in',
    'courier_return_in',
    'adjustment_in',
    'adjustment_out',
    'damage_out',
    'transfer_in',
    'transfer_out',
    'opening_balance',
];

export const adjustmentReasons: AdjustmentReason[] = ['damage', 'loss', 'theft', 'found', 'recount', 'expiry', 'correction', 'gift_sample'];
export const alertKinds: AlertKind[] = ['ledger_drift', 'reserved_drift', 'out_of_stock', 'low_stock'];

export type StockFilters = { location: string; state: StockState | ''; search: string; variant?: string };
export type MovementFilters = { variant: string; product: string; location: string; type: MovementType | ''; reason?: AdjustmentReason | ''; from: string; to: string };
export type AdjustmentFilters = { status: AdjustmentStatus | '' };
export type AlertFilters = { kind: AlertKind | '' };

type Page<T> = { data: T[]; meta: { next_cursor?: string | null } };
type CountedPage<T, C> = Page<T> & { meta: { counts: C } };

const keys = {
    all: ['inventory'] as const,
    summary: (location: string) => ['inventory', 'summary', location] as const,
    lowStockCount: ['inventory', 'low-stock-count'] as const,
    stock: (filters: StockFilters) => ['inventory', 'stock', filters] as const,
    movements: (filters: MovementFilters) => ['inventory', 'movements', filters] as const,
    reconciliation: (filters: MovementFilters) => ['inventory', 'reconciliation', filters] as const,
    adjustments: (filters: AdjustmentFilters) => ['inventory', 'adjustments', filters] as const,
    alerts: (filters: AlertFilters) => ['inventory', 'alerts', filters] as const,
};

function query(entries: Record<string, string | null | undefined>): string {
    const params = new URLSearchParams();
    for (const [key, value] of Object.entries(entries)) if (value) params.set(key, value);
    const text = params.toString();
    return text ? `?${text}` : '';
}

/** Every filter is a server-side allowlisted parameter; nothing is filtered or computed in the browser. */
export function stockPath(filters: StockFilters, cursor: string | null): string {
    return `/inventory/stock${query({
        'filter[location_id]': filters.location,
        'filter[state]': filters.state,
        'filter[search]': filters.search,
        'filter[variant_id]': filters.variant,
        cursor,
    })}`;
}

export function movementsPath(filters: MovementFilters, cursor: string | null, withType = true): string {
    return query({
        'filter[variant_id]': filters.variant,
        'filter[product_id]': filters.product,
        'filter[location_id]': filters.location,
        'filter[type]': withType ? filters.type : '',
        'filter[reason]': withType ? filters.reason : '',
        'filter[from]': filters.from,
        'filter[to]': filters.to,
        cursor,
    });
}

export function useInventorySummary(location: string) {
    return useQuery({
        queryKey: keys.summary(location),
        queryFn: () => api<InventorySummary>(`/inventory/summary${query({ 'filter[location_id]': location })}`),
        placeholderData: keepPreviousData,
    });
}

/**
 * The Inventory nav badge: low-stock variants at the default location, counted by the
 * server (one cached COUNT). Refreshed every few minutes and after any adjustment
 * (every inventory key is invalidated then).
 */
export function useLowStockCount(enabled: boolean) {
    return useQuery({
        queryKey: keys.lowStockCount,
        queryFn: () => api<LowStockCount>('/inventory/low-stock-count'),
        enabled,
        staleTime: 60_000,
        refetchInterval: 300_000,
    });
}

export function useStock(filters: StockFilters, enabled = true) {
    return useInfiniteQuery({
        queryKey: keys.stock(filters),
        queryFn: ({ pageParam }) => api<Page<StockRow>>(stockPath(filters, pageParam)),
        initialPageParam: null as string | null,
        getNextPageParam: (last) => last.meta.next_cursor ?? null,
        placeholderData: keepPreviousData,
        enabled,
    });
}

export function useMovements(filters: MovementFilters) {
    return useInfiniteQuery({
        queryKey: keys.movements(filters),
        queryFn: ({ pageParam }) => api<Page<Movement>>(`/inventory/movements${movementsPath(filters, pageParam)}`),
        initialPageParam: null as string | null,
        getNextPageParam: (last) => last.meta.next_cursor ?? null,
        placeholderData: keepPreviousData,
    });
}

/** The type filter never applies: an opening/closing balance needs every movement. */
export function useReconciliation(filters: MovementFilters) {
    return useQuery({
        queryKey: keys.reconciliation({ ...filters, type: '', reason: '' }),
        queryFn: () => api<Reconciliation>(`/inventory/movements/reconciliation${movementsPath(filters, null, false)}`),
        placeholderData: keepPreviousData,
    });
}

/**
 * Records an adjustment. `idempotencyKey` is minted once per dialog, so a retry after a
 * dropped connection replays the first result instead of moving stock twice.
 */
export function useAdjustStock() {
    const client = useQueryClient();

    return useMutation({
        mutationFn: ({ input, idempotencyKey }: { input: AdjustmentInput; idempotencyKey: string }) =>
            api<Adjustment>('/inventory/adjustments', {
                method: 'POST',
                body: JSON.stringify(input),
                headers: { 'Idempotency-Key': idempotencyKey },
            }),
        onSuccess: () => void client.invalidateQueries({ queryKey: keys.all }),
    });
}

export type AdjustmentCounts = { pending: number };
export type AlertCounts = Record<AlertKind, number>;

/** The adjustment list; `filter[status]=pending` is the approval queue. `meta.counts.pending` is the queue size. */
export function useAdjustments(filters: AdjustmentFilters, enabled = true) {
    return useInfiniteQuery({
        queryKey: keys.adjustments(filters),
        queryFn: ({ pageParam }) =>
            api<CountedPage<Adjustment, AdjustmentCounts>>(`/inventory/adjustments${query({ 'filter[status]': filters.status, cursor: pageParam })}`),
        initialPageParam: null as string | null,
        getNextPageParam: (last) => last.meta.next_cursor ?? null,
        placeholderData: keepPreviousData,
        enabled,
    });
}

/**
 * Approve (writes the movements, server-side, against the stock as it is now) or reject (writes
 * nothing) a pending adjustment. Approve carries an Idempotency-Key minted per dialog opening.
 */
export function useDecideAdjustment() {
    const client = useQueryClient();

    return useMutation({
        mutationFn: ({
            id,
            decision,
            input,
            idempotencyKey,
        }: { id: number; idempotencyKey: string } & (
            | { decision: 'approve'; input: ApproveStockAdjustmentRequestInput }
            | { decision: 'reject'; input: RejectStockAdjustmentRequestInput }
        )) =>
            api<Adjustment>(`/inventory/adjustments/${id}/${decision}`, {
                method: 'POST',
                body: JSON.stringify(input),
                headers: decision === 'approve' ? { 'Idempotency-Key': idempotencyKey } : {},
            }),
        onSuccess: () => void client.invalidateQueries({ queryKey: keys.all }),
    });
}

/** Moves stock between two locations; one Idempotency-Key per dialog opening. */
export function useTransferStock() {
    const client = useQueryClient();

    return useMutation({
        mutationFn: ({ input, idempotencyKey }: { input: TransferInput; idempotencyKey: string }) =>
            api<Transfer>('/inventory/transfers', {
                method: 'POST',
                body: JSON.stringify(input),
                headers: { 'Idempotency-Key': idempotencyKey },
            }),
        onSuccess: () => void client.invalidateQueries({ queryKey: keys.all }),
    });
}

/** Open inventory alerts (drift from the nightly reconciliation, low / out of stock); `meta.counts` per kind. */
export function useAlerts(filters: AlertFilters, enabled = true) {
    return useInfiniteQuery({
        queryKey: keys.alerts(filters),
        queryFn: ({ pageParam }) => api<CountedPage<InventoryAlert, AlertCounts>>(`/inventory/alerts${query({ 'filter[kind]': filters.kind, cursor: pageParam })}`),
        initialPageParam: null as string | null,
        getNextPageParam: (last) => last.meta.next_cursor ?? null,
        placeholderData: keepPreviousData,
        enabled,
    });
}

export function useAcknowledgeAlert() {
    const client = useQueryClient();

    return useMutation({
        mutationFn: ({ id, input }: { id: number; input: AcknowledgeInventoryAlertRequestInput }) =>
            api<InventoryAlert>(`/inventory/alerts/${id}/acknowledge`, { method: 'POST', body: JSON.stringify(input) }),
        onSuccess: () => void client.invalidateQueries({ queryKey: keys.all }),
    });
}
