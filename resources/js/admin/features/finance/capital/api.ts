import { keepPreviousData, useInfiniteQuery, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import type { Schema } from '@toymart/api-types';
import type { CapitalHolderRequestInput, RecordCapitalEntryRequestInput, ReverseCapitalEntryRequestInput } from '@toymart/api-types/zod';
import { api } from '@/lib/api';

/** Owner capital (P10, docs/api/capital.md) — money put in and taken out. Cash only, never profit. */
export type CapitalSummary = Schema<'CapitalSummary'>;
export type CapitalHolderSummary = CapitalSummary['holders'][number];
export type CapitalLastEntry = NonNullable<CapitalHolderSummary['last_entry']>;
export type CapitalEntry = Schema<'CapitalEntry'>;
export type CapitalHolder = Schema<'CapitalHolder'>;
export type CapitalKind = CapitalEntry['kind'];
export type CapitalMethod = CapitalEntry['method'];
export type HolderRole = CapitalHolder['role'];
/** Request bodies — the generated types, never hand-written copies (rule 27). */
export type CapitalEntryInput = RecordCapitalEntryRequestInput;
export type ReverseInput = ReverseCapitalEntryRequestInput;
export type HolderInput = CapitalHolderRequestInput;
export type StaffOption = { id: number; name: string };

export const capitalMethods: CapitalMethod[] = ['cash', 'bank_transfer', 'bkash', 'nagad', 'card', 'cheque'];
export const holderRoles: HolderRole[] = ['owner', 'partner'];

/** The entries table's filters: one holder, money in / out, a Dhaka date range. */
export type EntryFilters = { holder: string; kind: '' | CapitalKind; from: string; to: string };

type EntryPage = { data: CapitalEntry[]; meta: { next_cursor?: string | null; total: number } };

const keys = {
    all: ['finance', 'capital'] as const,
    summary: ['finance', 'capital', 'summary'] as const,
    entries: (f: EntryFilters) => ['finance', 'capital', 'entries', f] as const,
    holders: ['finance', 'capital', 'holders'] as const,
    staff: ['finance', 'capital', 'staff'] as const,
};

function query(params: Record<string, string | null | undefined>): string {
    const search = new URLSearchParams();
    for (const [key, value] of Object.entries(params)) if (value) search.set(key, value);
    const text = search.toString();
    return text ? `?${text}` : '';
}

/** The KPI cards and the holder cards: every entry, all time. */
export function useCapitalSummary() {
    return useQuery({ queryKey: keys.summary, queryFn: () => api<CapitalSummary>('/finance/capital') });
}

export function useCapitalEntries(filters: EntryFilters) {
    return useInfiniteQuery({
        queryKey: keys.entries(filters),
        queryFn: ({ pageParam }) =>
            api<EntryPage>(
                `/finance/capital/entries${query({
                    'filter[holder_id]': filters.holder,
                    'filter[kind]': filters.kind,
                    'filter[from]': filters.from,
                    'filter[to]': filters.to,
                    cursor: pageParam,
                })}`,
            ),
        initialPageParam: null as string | null,
        getNextPageParam: (last) => last.meta.next_cursor ?? null,
        placeholderData: keepPreviousData,
    });
}

export function useCapitalHolders() {
    return useQuery({ queryKey: keys.holders, queryFn: () => api<{ data: CapitalHolder[] }>('/finance/capital/holders').then((r) => r.data) });
}

/** Staff accounts a holder may be linked to (finance.manage_capital only). */
export function useCapitalStaff(enabled: boolean) {
    return useQuery({ queryKey: keys.staff, enabled, queryFn: () => api<{ data: StaffOption[] }>('/finance/capital/staff').then((r) => r.data) });
}

/** After a write, the overview, the entries and the ledger views all refetch from the server. */
function useInvalidate() {
    const client = useQueryClient();
    return () => Promise.all([client.invalidateQueries({ queryKey: keys.all }), client.invalidateQueries({ queryKey: ['finance', 'ledger'] })]);
}

/** One Idempotency-Key per dialog opening: a double submit replays instead of recording twice. */
export function useRecordCapitalEntry() {
    const invalidate = useInvalidate();
    return useMutation({
        mutationFn: ({ input, idempotencyKey }: { input: CapitalEntryInput; idempotencyKey: string }) =>
            api<CapitalEntry>('/finance/capital/entries', { method: 'POST', body: JSON.stringify(input), headers: { 'Idempotency-Key': idempotencyKey } }),
        onSuccess: () => void invalidate(),
    });
}

export function useReverseCapitalEntry() {
    const invalidate = useInvalidate();
    return useMutation({
        mutationFn: ({ id, input, idempotencyKey }: { id: number; input: ReverseInput; idempotencyKey: string }) =>
            api<CapitalEntry>(`/finance/capital/entries/${id}/reverse`, { method: 'POST', body: JSON.stringify(input), headers: { 'Idempotency-Key': idempotencyKey } }),
        onSuccess: () => void invalidate(),
    });
}

export function useSaveCapitalHolder() {
    const invalidate = useInvalidate();
    const client = useQueryClient();
    return useMutation({
        mutationFn: ({ id, input }: { id?: number; input: HolderInput }) =>
            api<CapitalHolder>(id === undefined ? '/finance/capital/holders' : `/finance/capital/holders/${id}`, { method: id === undefined ? 'POST' : 'PATCH', body: JSON.stringify(input) }),
        onSuccess: () => {
            void invalidate();
            void client.invalidateQueries({ queryKey: keys.staff });
        },
    });
}
