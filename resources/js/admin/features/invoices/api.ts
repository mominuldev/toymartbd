import { keepPreviousData, useInfiniteQuery, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import type { Schema } from '@toymart/api-types';
import type { UpdateInvoiceSettingsRequestInput } from '@toymart/api-types/zod';
import { api } from '@/lib/api';

export type InvoiceListItem = Schema<'InvoiceListItem'>;
export type InvoiceType = InvoiceListItem['type'];
export type InvoiceSettings = Schema<'InvoiceSettings'>;
export type CreditNoteListItem = Schema<'CreditNoteListItem'>;
/** The PUT body — the generated request type, never a hand-written copy (rule 27). */
export type InvoiceSettingsInput = UpdateInvoiceSettingsRequestInput;

export const invoiceTypes: InvoiceType[] = ['sales', 'purchase'];

/** The list tabs: every invoice, one type, or the credit notes (their own endpoint). */
export type InvoiceTab = InvoiceType | 'credit_notes' | '';
export const invoiceTabs: InvoiceTab[] = ['', ...invoiceTypes, 'credit_notes'];

/** Every filter goes to the server's allowlist (`filter[...]`); nothing is filtered here. */
export type InvoiceFilters = { type: InvoiceType | ''; search: string; from: string; to: string };

type Page<T> = { data: T[]; meta: { next_cursor?: string | null } };

const keys = {
    list: (filters: InvoiceFilters) => ['invoices', 'list', filters] as const,
    creditNotes: (filters: CreditNoteFilters) => ['invoices', 'credit-notes', filters] as const,
    settings: ['settings', 'invoice'] as const,
};

export function invoicesPath(filters: InvoiceFilters, cursor: string | null): string {
    const params = new URLSearchParams();
    const entries: Record<string, string | null> = {
        'filter[type]': filters.type,
        'filter[search]': filters.search.trim(),
        'filter[issued_from]': filters.from,
        'filter[issued_to]': filters.to,
        cursor,
    };
    for (const [key, value] of Object.entries(entries)) if (value) params.set(key, value);
    const text = params.toString();
    return `/invoices${text ? `?${text}` : ''}`;
}

export function useInvoices(filters: InvoiceFilters, enabled = true) {
    return useInfiniteQuery({
        queryKey: keys.list(filters),
        queryFn: ({ pageParam }) => api<Page<InvoiceListItem>>(invoicesPath(filters, pageParam)),
        initialPageParam: null as string | null,
        getNextPageParam: (last) => last.meta.next_cursor ?? null,
        placeholderData: keepPreviousData,
        enabled,
    });
}

export type CreditNoteFilters = { search: string; from: string; to: string };

export function creditNotesPath(filters: CreditNoteFilters, cursor: string | null): string {
    const params = new URLSearchParams();
    const entries: Record<string, string | null> = {
        'filter[search]': filters.search.trim(),
        'filter[issued_from]': filters.from,
        'filter[issued_to]': filters.to,
        cursor,
    };
    for (const [key, value] of Object.entries(entries)) if (value) params.set(key, value);
    const text = params.toString();
    return `/credit-notes${text ? `?${text}` : ''}`;
}

/** Credit notes (P8 batch 2) — issued by the server on cancellation after issue and on a paid refund; read-only here. */
export function useCreditNotes(filters: CreditNoteFilters, enabled: boolean) {
    return useInfiniteQuery({
        queryKey: keys.creditNotes(filters),
        queryFn: ({ pageParam }) => api<Page<CreditNoteListItem>>(creditNotesPath(filters, pageParam)),
        initialPageParam: null as string | null,
        getNextPageParam: (last) => last.meta.next_cursor ?? null,
        placeholderData: keepPreviousData,
        enabled,
    });
}

export function useInvoiceSettings(enabled: boolean) {
    return useQuery({ queryKey: keys.settings, queryFn: () => api<InvoiceSettings>('/settings/invoice'), enabled });
}

export function useSaveInvoiceSettings() {
    const client = useQueryClient();
    return useMutation({
        mutationFn: (input: InvoiceSettingsInput) => api<InvoiceSettings>('/settings/invoice', { method: 'PUT', body: JSON.stringify(input) }),
        onSuccess: (saved) => client.setQueryData(keys.settings, saved),
    });
}
