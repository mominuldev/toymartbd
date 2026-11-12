import { keepPreviousData, useInfiniteQuery, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import type { Schema } from '@toymart/api-types';
import type {
    ReceivePurchaseRequestInput,
    StorePurchaseRequestInput,
    StoreSupplierPaymentRequestInput,
    StoreSupplierRequestInput,
    UpdateSupplierRequestInput,
} from '@toymart/api-types/zod';
import { api } from '@/lib/api';

export type Supplier = Schema<'Supplier'>;
export type Purchase = Schema<'Purchase'>;
export type PurchaseLine = Schema<'PurchaseLine'>;
export type PurchaseListItem = Schema<'PurchaseListItem'>;
export type PurchaseReceipt = Schema<'PurchaseReceipt'>;
export type ReceiptQuote = Schema<'PurchaseReceiptQuote'>;
export type SupplierPayment = Schema<'SupplierPayment'>;
export type PurchaseStatus = Purchase['status'];
export type PaymentMethod = SupplierPayment['method'];
export type ApportionMethod = ReceiptQuote['apportion_method'];
/** Stock rows double as the PO form's variant search (name, SKU, current cost basis). */
export type VariantOption = Schema<'InventoryStockRow'>;
export type StockLocation = Schema<'InventorySummary'>['locations'][number];
export type PurchaseCounts = Record<PurchaseStatus, number>;

/** Request bodies are the generated types, never hand-written copies (rule 27). */
export type PurchaseInput = StorePurchaseRequestInput;
export type ReceiptInput = ReceivePurchaseRequestInput;
export type PaymentInput = StoreSupplierPaymentRequestInput;
export type SupplierInput = StoreSupplierRequestInput & UpdateSupplierRequestInput;

export const purchaseStatuses: PurchaseStatus[] = ['ordered', 'partially_received', 'received', 'closed_short', 'draft', 'cancelled'];
/** How a third party (freight forwarder, C&F agent) was paid — the expense payment methods. */
export const thirdPartyPaymentMethods = ['cash', 'bank_transfer', 'bkash', 'nagad', 'card', 'cheque'] as const;
export type ThirdPartyPaymentMethod = (typeof thirdPartyPaymentMethods)[number];
export const paymentMethods: PaymentMethod[] = ['cash', 'bank_transfer', 'cheque', 'bkash', 'nagad', 'other'];
export const apportionMethods: ApportionMethod[] = ['value', 'quantity', 'weight'];

type Page<T> = { data: T[]; meta: { next_cursor?: string | null } };

const keys = {
    all: ['purchasing'] as const,
    purchases: (filters: PurchaseFilters) => ['purchasing', 'purchases', filters] as const,
    counts: ['purchasing', 'counts'] as const,
    purchase: (id: number) => ['purchasing', 'purchase', id] as const,
    suppliers: (filters: SupplierFilters) => ['purchasing', 'suppliers', filters] as const,
    supplier: (id: number) => ['purchasing', 'supplier', id] as const,
    payments: (id: number) => ['purchasing', 'payments', id] as const,
    quote: (id: number, input: ReceiptInput) => ['purchasing', 'quote', id, input] as const,
    variants: (search: string) => ['purchasing', 'variants', search] as const,
    locations: ['purchasing', 'locations'] as const,
};

export type PurchaseFilters = { status: PurchaseStatus | ''; supplier: string; search: string };
export type SupplierFilters = { status: 'active' | 'archived' | ''; search: string };

function query(entries: Record<string, string | null | undefined>): string {
    const params = new URLSearchParams();
    for (const [key, value] of Object.entries(entries)) if (value) params.set(key, value);
    const text = params.toString();
    return text ? `?${text}` : '';
}

/** Every filter is a server-side allowlisted parameter; nothing is filtered or computed in the browser. */
export function usePurchases(filters: PurchaseFilters, enabled = true) {
    return useInfiniteQuery({
        queryKey: keys.purchases(filters),
        queryFn: ({ pageParam }) =>
            api<Page<PurchaseListItem>>(
                `/purchases${query({ 'filter[status]': filters.status, 'filter[supplier_id]': filters.supplier, 'filter[search]': filters.search, cursor: pageParam })}`,
            ),
        initialPageParam: null as string | null,
        getNextPageParam: (last) => last.meta.next_cursor ?? null,
        placeholderData: keepPreviousData,
        enabled,
    });
}

export function usePurchaseCounts() {
    return useQuery({ queryKey: keys.counts, queryFn: () => api<PurchaseCounts>('/purchases/counts') });
}

export function usePurchase(id: number | null) {
    return useQuery({ queryKey: keys.purchase(id ?? 0), queryFn: () => api<Purchase>(`/purchases/${id}`), enabled: id !== null });
}

export function useSuppliers(filters: SupplierFilters) {
    return useInfiniteQuery({
        queryKey: keys.suppliers(filters),
        queryFn: ({ pageParam }) => api<Page<Supplier>>(`/suppliers${query({ 'filter[status]': filters.status, 'filter[search]': filters.search, cursor: pageParam })}`),
        initialPageParam: null as string | null,
        getNextPageParam: (last) => last.meta.next_cursor ?? null,
        placeholderData: keepPreviousData,
    });
}

export function useSupplier(id: number | null) {
    return useQuery({ queryKey: keys.supplier(id ?? 0), queryFn: () => api<Supplier>(`/suppliers/${id}`), enabled: id !== null });
}

export function useSupplierPayments(id: number, enabled: boolean) {
    return useInfiniteQuery({
        queryKey: keys.payments(id),
        queryFn: ({ pageParam }) => api<Page<SupplierPayment>>(`/suppliers/${id}/payments${query({ cursor: pageParam })}`),
        initialPageParam: null as string | null,
        getNextPageParam: (last) => last.meta.next_cursor ?? null,
        enabled,
    });
}

/** The PO form's variant picker searches the stock list (server-side search, first page only). */
export function useVariantSearch(search: string) {
    return useQuery({
        queryKey: keys.variants(search),
        queryFn: () => api<Page<VariantOption>>(`/inventory/stock${query({ 'filter[search]': search })}`),
        enabled: search.length >= 2,
        placeholderData: keepPreviousData,
    });
}

export function useStockLocations(enabled: boolean) {
    return useQuery({
        queryKey: keys.locations,
        queryFn: async () => (await api<Schema<'InventorySummary'>>('/inventory/summary')).locations,
        enabled,
        staleTime: 300_000,
    });
}

/**
 * The receive dialog's preview. Every figure — line value, landed share, landed unit cost,
 * the new average — comes from `POST …/receipts/quote` (rule 1: the browser never computes money).
 */
export function useReceiptQuote(id: number, input: ReceiptInput | null) {
    return useQuery({
        queryKey: keys.quote(id, input ?? { lines: [] }),
        queryFn: () => api<ReceiptQuote>(`/purchases/${id}/receipts/quote`, { method: 'POST', body: JSON.stringify(input) }),
        enabled: input !== null,
        placeholderData: keepPreviousData,
        retry: false,
    });
}

function useInvalidate() {
    const client = useQueryClient();
    return () => {
        void client.invalidateQueries({ queryKey: keys.all });
        // A receipt moves stock: the inventory screens refetch too.
        void client.invalidateQueries({ queryKey: ['inventory'] });
    };
}

export function useSavePurchase(id: number | null) {
    const invalidate = useInvalidate();
    return useMutation({
        mutationFn: (input: PurchaseInput) => api<Purchase>(id === null ? '/purchases' : `/purchases/${id}`, { method: id === null ? 'POST' : 'PUT', body: JSON.stringify(input) }),
        onSuccess: invalidate,
    });
}

/** Close a partly received PO short; the reason is required by the server. */
export function useClosePurchase(id: number) {
    const invalidate = useInvalidate();
    return useMutation({
        mutationFn: (reason: string) => api<Purchase>(`/purchases/${id}/close`, { method: 'POST', body: JSON.stringify({ reason }) }),
        onSuccess: invalidate,
    });
}

export function usePurchaseMove(id: number) {
    const invalidate = useInvalidate();
    return useMutation({
        mutationFn: (move: 'order' | 'cancel') => api<Purchase>(`/purchases/${id}/${move}`, { method: 'POST' }),
        onSuccess: invalidate,
    });
}

/** One Idempotency-Key per receive panel opening: a retry replays instead of receiving twice. */
export function useReceive(id: number) {
    const invalidate = useInvalidate();
    return useMutation({
        mutationFn: ({ input, idempotencyKey }: { input: ReceiptInput; idempotencyKey: string }) =>
            api<Purchase>(`/purchases/${id}/receipts`, { method: 'POST', body: JSON.stringify(input), headers: { 'Idempotency-Key': idempotencyKey } }),
        onSuccess: invalidate,
    });
}

export function useSaveSupplier(id: number | null) {
    const invalidate = useInvalidate();
    return useMutation({
        mutationFn: (input: SupplierInput) => api<Supplier>(id === null ? '/suppliers' : `/suppliers/${id}`, { method: id === null ? 'POST' : 'PATCH', body: JSON.stringify(input) }),
        onSuccess: invalidate,
    });
}

export function useArchiveSupplier(id: number) {
    const invalidate = useInvalidate();
    return useMutation({ mutationFn: () => api<void>(`/suppliers/${id}`, { method: 'DELETE' }), onSuccess: invalidate });
}

export function useRecordPayment(supplierId: number) {
    const invalidate = useInvalidate();
    return useMutation({
        mutationFn: ({ input, idempotencyKey }: { input: PaymentInput; idempotencyKey: string }) =>
            api<SupplierPayment>(`/suppliers/${supplierId}/payments`, { method: 'POST', body: JSON.stringify(input), headers: { 'Idempotency-Key': idempotencyKey } }),
        onSuccess: invalidate,
    });
}
