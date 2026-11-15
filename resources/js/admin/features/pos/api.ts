import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import type { Schema } from '@toymart/api-types';
import type {
    ChargePosSaleRequestInput,
    ClosePosSessionRequestInput,
    CollectDueRequestInput,
    HoldPosSaleRequestInput,
    OpenPosSessionRequestInput,
    QuoteDueCollectionRequestInput,
    QuotePosSaleRequestInput,
} from '@toymart/api-types/zod';
import { api } from '@/lib/api';

export type PosContext = Schema<'PosContext'>;
export type PosSession = Schema<'PosSession'>;
export type PosCatalogItem = Schema<'PosCatalogItem'>;
export type PosQuote = Schema<'PosQuote'>;
export type PosSale = Schema<'PosSale'>;
export type PosHeldSale = Schema<'PosHeldSale'>;
export type PosReceipt = Schema<'PosReceipt'>;
export type PosCustomer = Schema<'PosCustomer'>;
export type TenderMethod = PosContext['tenders'][number]['method'];
export type PosCustomerMatch = Schema<'PosCustomerMatch'>;
export type PosDueQuote = Schema<'PosDueQuote'>;
export type PosDueCollection = Schema<'PosDueCollection'>;

/** Request bodies are the generated types, never hand-written copies (rule 27). */
export type OpenInput = OpenPosSessionRequestInput;
export type CloseInput = ClosePosSessionRequestInput;
export type QuoteInput = QuotePosSaleRequestInput;
export type ChargeInput = ChargePosSaleRequestInput;
export type HoldInput = HoldPosSaleRequestInput;
export type DueQuoteInput = QuoteDueCollectionRequestInput;
export type CollectDueInput = CollectDueRequestInput;

export const posKeys = {
    all: ['pos'] as const,
    context: ['pos', 'context'] as const,
    catalog: (sessionId: number, filters: CatalogFilters) => ['pos', 'catalog', sessionId, filters] as const,
    quote: (sessionId: number, body: QuoteInput) => ['pos', 'quote', sessionId, body] as const,
    holds: (sessionId: number) => ['pos', 'holds', sessionId] as const,
    customer: (phone: string) => ['pos', 'customer', phone] as const,
    customerSearch: (q: string) => ['pos', 'customer-search', q] as const,
    dueQuote: (sessionId: number, body: DueQuoteInput) => ['pos', 'due-quote', sessionId, body] as const,
    receipt: (saleId: number) => ['pos', 'receipt', saleId] as const,
};

export type CatalogFilters = { categoryId: number | null; q: string };

export function usePosContext() {
    return useQuery({ queryKey: posKeys.context, queryFn: () => api<PosContext>('/pos/context') });
}

export function useCatalog(sessionId: number, filters: CatalogFilters) {
    const params = new URLSearchParams();
    if (filters.categoryId !== null) params.set('category_id', String(filters.categoryId));
    if (filters.q.trim() !== '') params.set('q', filters.q.trim());
    const query = params.toString();

    return useQuery({
        queryKey: posKeys.catalog(sessionId, filters),
        queryFn: () => api<{ data: PosCatalogItem[] }>(`/pos/sessions/${sessionId}/catalog${query ? `?${query}` : ''}`),
        placeholderData: keepPreviousData,
    });
}

/** Exact barcode lookup — what a keyboard-wedge scanner types before Enter. */
export function lookupBarcode(sessionId: number, barcode: string): Promise<{ data: PosCatalogItem[] }> {
    return api<{ data: PosCatalogItem[] }>(`/pos/sessions/${sessionId}/catalog?barcode=${encodeURIComponent(barcode)}`);
}

/** Every figure the register shows comes from here (rule 1); the previous answer stays on screen while the next loads. */
export function useQuote(sessionId: number, body: QuoteInput, enabled: boolean) {
    return useQuery({
        queryKey: posKeys.quote(sessionId, body),
        queryFn: () => api<PosQuote>(`/pos/sessions/${sessionId}/quote`, { method: 'POST', body: JSON.stringify(body) }),
        placeholderData: keepPreviousData,
        enabled,
    });
}

export function useCustomer(phone: string | null) {
    return useQuery({
        queryKey: posKeys.customer(phone ?? ''),
        queryFn: () => api<PosCustomer>(`/pos/customers?phone=${encodeURIComponent(phone ?? '')}`),
        enabled: phone !== null,
        retry: false,
    });
}

export function useHolds(sessionId: number) {
    return useQuery({ queryKey: posKeys.holds(sessionId), queryFn: () => api<{ data: PosHeldSale[] }>(`/pos/sessions/${sessionId}/holds`) });
}

export function useReceipt(saleId: number | null) {
    return useQuery({
        queryKey: posKeys.receipt(saleId ?? 0),
        queryFn: () => api<PosReceipt>(`/pos/sales/${saleId ?? 0}/receipt`),
        enabled: saleId !== null,
    });
}

export function useOpenSession() {
    const client = useQueryClient();
    return useMutation({
        mutationFn: (input: OpenInput) => api<PosSession>('/pos/sessions', { method: 'POST', body: JSON.stringify(input) }),
        onSuccess: () => client.invalidateQueries({ queryKey: posKeys.context }),
    });
}

export function useCloseSession(sessionId: number) {
    return useMutation({
        mutationFn: (input: CloseInput) => api<PosSession>(`/pos/sessions/${sessionId}/close`, { method: 'POST', body: JSON.stringify(input) }),
    });
}

/**
 * Charge. One Idempotency-Key per attempt, kept by the caller across retries of the same
 * cart so a double tap on a flaky connection rings up one sale (rule 17).
 */
export function useCharge(sessionId: number) {
    const client = useQueryClient();
    return useMutation({
        mutationFn: ({ input, key }: { input: ChargeInput; key: string }) =>
            api<PosSale>(`/pos/sessions/${sessionId}/sales`, { method: 'POST', body: JSON.stringify(input), headers: { 'Idempotency-Key': key } }),
        onSuccess: () => {
            void client.invalidateQueries({ queryKey: posKeys.context });
            void client.invalidateQueries({ queryKey: ['pos', 'catalog', sessionId] });
        },
    });
}

export function useHold(sessionId: number) {
    const client = useQueryClient();
    return useMutation({
        mutationFn: (input: HoldInput) => api<PosHeldSale>(`/pos/sessions/${sessionId}/holds`, { method: 'POST', body: JSON.stringify(input) }),
        onSuccess: () => client.invalidateQueries({ queryKey: posKeys.holds(sessionId) }),
    });
}

export function useResume(sessionId: number) {
    const client = useQueryClient();
    return useMutation({
        mutationFn: (holdId: number) => api<PosHeldSale>(`/pos/holds/${holdId}/resume`, { method: 'POST' }),
        onSuccess: () => client.invalidateQueries({ queryKey: posKeys.holds(sessionId) }),
    });
}

/** Name fragment or phone digits (≥ 2 characters); the server matches and caps the list. */
export function useCustomerSearch(q: string) {
    const term = q.trim();
    return useQuery({
        queryKey: posKeys.customerSearch(term),
        queryFn: () => api<{ data: PosCustomerMatch[] }>(`/pos/customers/search?q=${encodeURIComponent(term)}`),
        enabled: term.length >= 2,
        placeholderData: keepPreviousData,
    });
}

/** Discard a parked cart for good (it never comes back to a register). */
export function useDiscardHold(sessionId: number) {
    const client = useQueryClient();
    return useMutation({
        mutationFn: (holdId: number) => api<void>(`/pos/holds/${holdId}`, { method: 'DELETE' }),
        onSuccess: () => client.invalidateQueries({ queryKey: posKeys.holds(sessionId) }),
    });
}

/** The tenders read against a customer's open due — applied oldest order first, change from cash only (rule 1). */
export function useDueQuote(sessionId: number, body: DueQuoteInput, enabled: boolean) {
    return useQuery({
        queryKey: posKeys.dueQuote(sessionId, body),
        queryFn: () => api<PosDueQuote>(`/pos/sessions/${sessionId}/due-collections/quote`, { method: 'POST', body: JSON.stringify(body) }),
        placeholderData: keepPreviousData,
        enabled,
    });
}

/** Collect against open dues; one Idempotency-Key per attempt, kept across retries (rule 17). */
export function useCollectDue(sessionId: number) {
    const client = useQueryClient();
    return useMutation({
        mutationFn: ({ input, key }: { input: CollectDueInput; key: string }) =>
            api<PosDueCollection>(`/pos/sessions/${sessionId}/due-collections`, { method: 'POST', body: JSON.stringify(input), headers: { 'Idempotency-Key': key } }),
        onSuccess: () => {
            void client.invalidateQueries({ queryKey: ['pos', 'customer'] });
            void client.invalidateQueries({ queryKey: ['pos', 'customer-search'] });
            void client.invalidateQueries({ queryKey: posKeys.context });
        },
    });
}
