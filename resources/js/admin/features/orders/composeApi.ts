import { useEffect, useState } from 'react';
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import type { Schema } from '@toymart/api-types';
import type { StoreOrderRequestInput, UpdateOrderRequestInput } from '@toymart/api-types/zod';
import { ApiError, api, apiLocale, csrfToken, isProblem, withIdempotency } from '@/lib/api';
import { orderKeys, type BulkResult, type Order } from './api';

/*
 * Create / edit order (P4-ORD-06/07) and the board's bulk pack + invoices (P4-ORD-08).
 * Every figure on these screens is the server's quote (rule 1): the client sends lines
 * and an address and shows what comes back; it never adds a price.
 */

export type Quote = Schema<'OrderQuote'>;
export type QuoteLine = Quote['lines'][number];
export type ComposerCustomer = Schema<'OrderComposerCustomer'>;
export type ComposerVariant = Schema<'OrderComposerVariant'>;
export type District = Schema<'StorefrontDistrict'>;
export type Upazila = Schema<'StorefrontUpazila'>;
/** Request bodies — the generated types, never hand-written copies (rule 27). */
export type CreateOrderInput = StoreOrderRequestInput;
export type EditOrderInput = UpdateOrderRequestInput;
export type StaffSource = CreateOrderInput['source'];
export type Line = { variant_id: number; quantity: number };

export const staffSources: StaffSource[] = ['phone', 'facebook', 'whatsapp', 'admin'];

/** Same pattern as storefront checkout: 013–019 only (rule 30). Decides when to look up; the server validates. */
export const PHONE_PATTERN = /^(?:\+?880|0)1[3-9]\d{8}$/;

export function useDebounced<T>(value: T, delay = 300): T {
    const [debounced, setDebounced] = useState(value);
    useEffect(() => {
        const timer = setTimeout(() => setDebounced(value), delay);
        return () => clearTimeout(timer);
    }, [value, delay]);
    return debounced;
}

const composeKeys = {
    customer: (phone: string) => ['orders', 'compose', 'customer', phone] as const,
    variants: (q: string) => ['orders', 'compose', 'variants', q] as const,
    districts: ['orders', 'compose', 'districts'] as const,
    upazilas: (district: number) => ['orders', 'compose', 'upazilas', district] as const,
    quote: (scope: string, body: unknown) => ['orders', 'quote', scope, body] as const,
};

export function useCustomerLookup(phone: string) {
    const valid = PHONE_PATTERN.test(phone.replace(/[\s-]/g, ''));
    return useQuery({
        queryKey: composeKeys.customer(phone),
        queryFn: () => api<ComposerCustomer>(`/orders/compose/customer?phone=${encodeURIComponent(phone)}`),
        enabled: valid,
        staleTime: 60_000,
    });
}

export function useVariantSearch(term: string) {
    const q = term.trim();
    return useQuery({
        queryKey: composeKeys.variants(q),
        queryFn: () => api<{ data: ComposerVariant[] }>(`/orders/compose/variants?q=${encodeURIComponent(q)}`).then((r) => r.data),
        enabled: q.length >= 2,
        placeholderData: keepPreviousData,
    });
}

export function useDistricts() {
    return useQuery({
        queryKey: composeKeys.districts,
        queryFn: () => api<{ data: District[] }>('/orders/compose/districts').then((r) => r.data),
        staleTime: Infinity,
    });
}

export function useUpazilas(district: number | null) {
    return useQuery({
        queryKey: composeKeys.upazilas(district ?? 0),
        queryFn: () => api<{ data: Upazila[] }>(`/orders/compose/districts/${district}/upazilas`).then((r) => r.data),
        enabled: district !== null,
        staleTime: Infinity,
    });
}

export type QuoteBody = {
    lines: Line[];
    coupon_code?: string | null;
    phone?: string | null;
    district_id?: number | null;
    upazila_id?: number | null;
};

/** The live quote for a new order — re-asked whenever the lines, coupon, phone or destination change. */
export function useCreateQuote(body: QuoteBody) {
    return useQuery({
        queryKey: composeKeys.quote('new', body),
        queryFn: () => api<Quote>('/orders/quote', { method: 'POST', body: JSON.stringify(body) }),
        enabled: body.lines.length > 0,
        placeholderData: keepPreviousData,
    });
}

/** The live re-quote for an edit. Needs the whole editable body (the server validates the address). */
export type EditQuoteBody = Omit<EditOrderInput, 'expected_total' | 'note'>;

export function useEditQuote(orderId: number, body: EditQuoteBody | null) {
    return useQuery({
        queryKey: composeKeys.quote(`edit-${orderId}`, body),
        queryFn: () => api<Quote>(`/orders/${orderId}/edit-quote`, { method: 'POST', body: JSON.stringify(body) }),
        enabled: body !== null && body.lines.length > 0,
        placeholderData: keepPreviousData,
        retry: false,
    });
}

/** POST /orders with an Idempotency-Key (rule 17): a double tap returns the first order. */
export function useCreateOrder() {
    const client = useQueryClient();
    return useMutation({
        mutationFn: (input: CreateOrderInput) => api<Order>('/orders', withIdempotency({ method: 'POST', body: JSON.stringify(input) })),
        onSuccess: (order) => {
            client.setQueryData(orderKeys.detail(order.id), order);
            void client.invalidateQueries({ queryKey: ['orders', 'list'] });
        },
    });
}

export function useUpdateOrder(orderId: number) {
    const client = useQueryClient();
    return useMutation({
        mutationFn: (input: EditOrderInput) => api<Order>(`/orders/${orderId}`, { method: 'PUT', body: JSON.stringify(input) }),
        onSuccess: (order) => {
            client.setQueryData(orderKeys.detail(orderId), order);
            void client.invalidateQueries({ queryKey: ['orders', 'list'] });
        },
    });
}

type BulkResponse = { data: BulkResult[]; meta: { done: number; failed: number } };

/** Bulk "mark packed": one result per order, partial failure reported per order (P4-ORD-08). */
export function useBulkPack() {
    const client = useQueryClient();
    return useMutation({
        mutationFn: (orderIds: number[]) => api<BulkResponse>('/orders/bulk/pack', { method: 'POST', body: JSON.stringify({ order_ids: orderIds }) }),
        onSettled: () => void client.invalidateQueries({ queryKey: orderKeys.all }),
    });
}

/**
 * Fetch the invoice PDF (one order, or many in one file) and open it in a new tab for the
 * browser's print dialog. Returns the ids the server reported missing.
 */
export async function openInvoices(orderIds: number[]): Promise<{ missing: number[]; notInvoiced: number[] }> {
    const single = orderIds.length === 1;
    const response = await fetch(single ? `/api/v1/admin/orders/${orderIds[0]}/invoice` : '/api/v1/admin/orders/bulk/invoices', {
        method: single ? 'GET' : 'POST',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/pdf, application/problem+json',
            'Accept-Language': apiLocale(),
            ...(single ? {} : { 'Content-Type': 'application/json', 'X-XSRF-TOKEN': csrfToken() }),
        },
        body: single ? undefined : JSON.stringify({ order_ids: orderIds }),
    });
    if (!response.ok) {
        const payload: unknown = await response.json().catch(() => null);
        throw new ApiError(response.status, isProblem(payload) ? payload : { type: 'about:blank', title: response.statusText, status: response.status });
    }
    const url = URL.createObjectURL(await response.blob());
    window.open(url, '_blank', 'noopener');
    setTimeout(() => URL.revokeObjectURL(url), 60_000);
    const header = response.headers.get('X-Orders-Not-Found');
    // P8: `{order id}:{reason}` for orders left out (a cancelled order is never invoiced).
    const refused = response.headers.get('X-Orders-Not-Invoiced');
    return { missing: header ? header.split(',').map(Number) : [], notInvoiced: refused ? refused.split(',').map((part) => Number(part.split(':')[0])) : [] };
}
