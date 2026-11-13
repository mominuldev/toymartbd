import { keepPreviousData, useInfiniteQuery, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import type { Schema } from '@toymart/api-types';
import type { ExpenseCategoryRequestInput, ExpenseRequestInput, PayExpenseRequestInput, RecordCodReceiptRequestInput } from '@toymart/api-types/zod';
import { ApiError, api, apiLocale, csrfToken, isProblem } from '@/lib/api';

export type Expense = Schema<'Expense'>;
export type ExpenseCategory = Schema<'ExpenseCategory'>;
export type ExpenseSummary = Schema<'ExpenseSummary'>;
export type LedgerEntry = Schema<'FinanceLedgerEntry'>;
export type Receivables = Schema<'FinanceReceivables'>;
export type CodReceipt = Schema<'ManualCodReceipt'>;
export type ExpenseStatus = Expense['status'];
export type LedgerType = LedgerEntry['type'];
export type PaymentMethod = NonNullable<Expense['payment_method']>;
/** Request bodies — the generated types, never hand-written copies (rule 27). */
export type ExpenseInput = Omit<ExpenseRequestInput, 'receipt'>;
export type CategoryInput = ExpenseCategoryRequestInput;
export type PayInput = PayExpenseRequestInput;
export type CodReceiptInput = RecordCodReceiptRequestInput;

export const expenseStatuses: ExpenseStatus[] = ['pending', 'approved', 'rejected', 'void'];
export const paymentMethods: PaymentMethod[] = ['cash', 'bank_transfer', 'bkash', 'nagad', 'card', 'cheque'];
export const ledgerTypes: LedgerType[] = [
    'sale_revenue',
    'cogs',
    'discount_given',
    'shipping_revenue',
    'courier_charge',
    'courier_cod_fee',
    'courier_return_charge',
    'gateway_fee',
    'packaging',
    'marketing',
    'salary',
    'rent',
    'utility',
    'refund',
    'inventory_write_off',
    'supplier_payment',
    'cod_received',
    'other_income',
    'other_expense',
];
export const categoryTypes: ExpenseCategory['type'][] = ['operating', 'marketing', 'logistics', 'cogs', 'other'];
export const categoryLedgerTypes = ['rent', 'salary', 'utility', 'packaging', 'marketing', 'courier_charge', 'gateway_fee', 'inventory_write_off', 'cogs', 'other_expense'] as const;

export type ExpenseFilters = { status: ExpenseStatus | ''; category: string; from: string; to: string; search: string; recurring: '' | 'true' };
export type LedgerFilters = { type: LedgerType | ''; cash: '' | 'true' | 'false'; recognised: '' | 'true' | 'false'; from: string; to: string; source: string };

type Page<T> = { data: T[]; meta: { next_cursor?: string | null } };

const keys = {
    all: ['finance'] as const,
    expenses: (f: ExpenseFilters) => ['finance', 'expenses', f] as const,
    summary: (from: string, to: string) => ['finance', 'expenses-summary', from, to] as const,
    categories: ['finance', 'expense-categories'] as const,
    ledger: (f: LedgerFilters) => ['finance', 'ledger', f] as const,
    receivables: ['finance', 'receivables'] as const,
};

function query(entries: Record<string, string | null | undefined>): string {
    const params = new URLSearchParams();
    for (const [key, value] of Object.entries(entries)) if (value) params.set(key, value);
    const text = params.toString();
    return text ? `?${text}` : '';
}

/** Every filter is a server-side allowlisted parameter; the browser filters and sums nothing (rule 1). */
export function expensesPath(f: ExpenseFilters, cursor: string | null): string {
    return `/finance/expenses${query({
        'filter[status]': f.status,
        'filter[category_id]': f.category,
        'filter[from]': f.from,
        'filter[to]': f.to,
        'filter[search]': f.search,
        'filter[recurring]': f.recurring,
        cursor,
    })}`;
}

export function ledgerPath(f: LedgerFilters, cursor: string | null): string {
    return `/finance/ledger${query({
        'filter[type]': f.type,
        'filter[cash]': f.cash,
        'filter[recognised]': f.recognised,
        'filter[from]': f.from,
        'filter[to]': f.to,
        'filter[source_type]': f.source,
        cursor,
    })}`;
}

export function receiptUrl(id: number): string {
    return `/api/v1/admin/finance/expenses/${id}/receipt`;
}

export function useExpenses(filters: ExpenseFilters) {
    return useInfiniteQuery({
        queryKey: keys.expenses(filters),
        queryFn: ({ pageParam }) => api<Page<Expense>>(expensesPath(filters, pageParam)),
        initialPageParam: null as string | null,
        getNextPageParam: (last) => last.meta.next_cursor ?? null,
        placeholderData: keepPreviousData,
    });
}

export function useExpenseSummary(from: string, to: string) {
    return useQuery({
        queryKey: keys.summary(from, to),
        queryFn: () => api<ExpenseSummary>(`/finance/expenses/summary${query({ 'filter[from]': from, 'filter[to]': to })}`),
        placeholderData: keepPreviousData,
    });
}

export function useExpenseCategories() {
    return useQuery({
        queryKey: keys.categories,
        queryFn: () => api<{ data: ExpenseCategory[] }>('/finance/expense-categories').then((r) => r.data),
        staleTime: 60_000,
    });
}

export function useLedger(filters: LedgerFilters) {
    return useInfiniteQuery({
        queryKey: keys.ledger(filters),
        queryFn: ({ pageParam }) => api<Page<LedgerEntry>>(ledgerPath(filters, pageParam)),
        initialPageParam: null as string | null,
        getNextPageParam: (last) => last.meta.next_cursor ?? null,
        placeholderData: keepPreviousData,
    });
}

/** COD receivables as at the end of a Dhaka date (`asOf`, default today) — the server's figure. */
export function useReceivables(asOf?: string, enabled = true) {
    return useQuery({
        queryKey: [...keys.receivables, asOf ?? 'today'],
        queryFn: () => api<Receivables>(`/finance/receivables${asOf ? `?as_of=${encodeURIComponent(asOf)}` : ''}`),
        enabled,
    });
}

/** Multipart POST (the browser sets the boundary); the same RFC 9457 contract as `api()`. */
async function upload<T>(path: string, body: FormData): Promise<T> {
    const response = await fetch(`/api/v1/admin${path}`, {
        method: 'POST',
        body,
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'Accept-Language': apiLocale(), 'X-XSRF-TOKEN': csrfToken() },
    });
    const payload: unknown = await response.json().catch(() => null);
    if (!response.ok) {
        throw new ApiError(response.status, isProblem(payload) ? payload : { type: 'about:blank', title: response.statusText || 'Upload failed', status: response.status });
    }
    return payload as T;
}

function toFormData(input: ExpenseInput, receipt: File | null): FormData {
    const body = new FormData();
    for (const [key, value] of Object.entries(input)) {
        if (value === null || value === undefined) continue;
        body.append(key, typeof value === 'boolean' ? (value ? '1' : '0') : String(value));
    }
    if (receipt) body.append('receipt', receipt);
    return body;
}

function receiptForm(receipt: File): FormData {
    const body = new FormData();
    body.append('receipt', receipt);
    return body;
}

function useInvalidate() {
    const client = useQueryClient();
    return () => void client.invalidateQueries({ queryKey: keys.all });
}

/** Create (multipart, with an optional receipt) or edit (JSON) an expense. */
export function useSaveExpense(id: number | null) {
    const invalidate = useInvalidate();
    return useMutation({
        mutationFn: ({ input, receipt }: { input: ExpenseInput; receipt: File | null }) =>
            id === null
                ? upload<Expense>('/finance/expenses', toFormData(input, receipt))
                : api<Expense>(`/finance/expenses/${id}`, { method: 'PATCH', body: JSON.stringify(input) }).then((saved) =>
                      receipt ? upload<Expense>(`/finance/expenses/${id}/receipt`, receiptForm(receipt)) : saved,
                  ),
        onSuccess: invalidate,
    });
}

export type ExpenseAction = 'approve' | 'reject' | 'void' | 'pay' | 'delete';

export function useExpenseAction() {
    const invalidate = useInvalidate();
    return useMutation({
        mutationFn: ({ id, action, reason, pay }: { id: number; action: ExpenseAction; reason?: string; pay?: PayInput }) => {
            if (action === 'delete') return api<undefined>(`/finance/expenses/${id}`, { method: 'DELETE' });
            const body = action === 'pay' ? pay : action === 'approve' ? undefined : { reason };
            return api<Expense>(`/finance/expenses/${id}/${action}`, { method: 'POST', ...(body ? { body: JSON.stringify(body) } : {}) });
        },
        onSuccess: invalidate,
    });
}

export function useSaveCategory(id: number | null) {
    const invalidate = useInvalidate();
    return useMutation({
        mutationFn: (input: Partial<CategoryInput>) =>
            api<ExpenseCategory>(id === null ? '/finance/expense-categories' : `/finance/expense-categories/${id}`, {
                method: id === null ? 'POST' : 'PATCH',
                body: JSON.stringify(input),
            }),
        onSuccess: invalidate,
    });
}

export function useDeactivateCategory() {
    const invalidate = useInvalidate();
    return useMutation({
        mutationFn: (id: number) => api<ExpenseCategory>(`/finance/expense-categories/${id}`, { method: 'DELETE' }),
        onSuccess: invalidate,
    });
}

/** "Record COD received" — `idempotencyKey` is minted once per dialog, so a retry replays instead of recording twice. */
export function useRecordCodReceipt() {
    const invalidate = useInvalidate();
    return useMutation({
        mutationFn: ({ orderId, input, idempotencyKey }: { orderId: number; input: CodReceiptInput; idempotencyKey: string }) =>
            api<CodReceipt>(`/finance/orders/${orderId}/cod-receipts`, {
                method: 'POST',
                body: JSON.stringify(input),
                headers: { 'Idempotency-Key': idempotencyKey },
            }),
        onSuccess: invalidate,
    });
}
