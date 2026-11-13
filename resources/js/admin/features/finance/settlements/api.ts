import { useInfiniteQuery, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import type { Schema } from '@toymart/api-types';
import type { ImportSettlementRequestInput, ReviewSettlementLineRequestInput } from '@toymart/api-types/zod';
import { ApiError, api, apiLocale, csrfToken, isProblem } from '@/lib/api';

/**
 * COD settlements (§4.5). The server matches, compares and books; the browser only shows its
 * decimal strings and sends a person's decisions (rule 1). Nothing is booked until confirm.
 */
export type Settlement = Schema<'CourierSettlementResource'>;
export type SettlementLine = NonNullable<Settlement['items']>[number];
export type VarianceType = SettlementLine['variance_type'];
export type Provider = { code: string; name: string; name_bn: string | null };
export type ManualLine = NonNullable<ImportSettlementRequestInput['lines']>[number];
export type ImportInput = Omit<ImportSettlementRequestInput, 'file' | 'lines'>;

type Page = {
    data: Settlement[];
    meta: { next_cursor?: string | null; providers?: Provider[] };
};

const keys = {
    all: ['finance'] as const,
    list: ['finance', 'settlements'] as const,
    one: (id: number) => ['finance', 'settlements', id] as const,
};

export function useSettlements() {
    return useInfiniteQuery({
        queryKey: keys.list,
        queryFn: ({ pageParam }) => api<Page>(`/finance/settlements${pageParam ? `?cursor=${encodeURIComponent(pageParam)}` : ''}`),
        initialPageParam: null as string | null,
        getNextPageParam: (last) => last.meta.next_cursor ?? null,
    });
}

export function useSettlement(id: number | null) {
    return useQuery({
        queryKey: keys.one(id ?? 0),
        queryFn: () => api<Settlement>(`/finance/settlements/${id}`),
        enabled: id !== null,
    });
}

/** Multipart when a statement file is attached; JSON for typed lines. Always with an Idempotency-Key. */
async function importStatement(input: ImportInput, file: File | null, lines: ManualLine[], key: string): Promise<Settlement> {
    if (!file) {
        return api<Settlement>('/finance/settlements', {
            method: 'POST',
            body: JSON.stringify({ ...input, lines }),
            headers: { 'Idempotency-Key': key },
        });
    }
    const body = new FormData();
    for (const [k, v] of Object.entries(input)) if (v !== null && v !== undefined && v !== '') body.append(k, String(v));
    body.append('file', file);
    const response = await fetch('/api/v1/admin/finance/settlements', {
        method: 'POST',
        body,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Accept-Language': apiLocale(),
            'X-XSRF-TOKEN': csrfToken(),
            'Idempotency-Key': key,
        },
    });
    const payload: unknown = await response.json().catch(() => null);
    if (!response.ok)
        throw new ApiError(
            response.status,
            isProblem(payload)
                ? payload
                : {
                      type: 'about:blank',
                      title: response.statusText || 'Upload failed',
                      status: response.status,
                  },
        );
    return payload as Settlement;
}

function useInvalidate() {
    const client = useQueryClient();
    return () => void client.invalidateQueries({ queryKey: keys.all });
}

export function useImportSettlement() {
    const invalidate = useInvalidate();
    return useMutation({
        mutationFn: ({ input, file, lines, key }: { input: ImportInput; file: File | null; lines: ManualLine[]; key: string }) => importStatement(input, file, lines, key),
        onSuccess: invalidate,
    });
}

export function useReviewLine(settlementId: number) {
    const invalidate = useInvalidate();
    return useMutation({
        mutationFn: ({ itemId, input }: { itemId: number; input: ReviewSettlementLineRequestInput }) =>
            api<Settlement>(`/finance/settlements/${settlementId}/items/${itemId}/review`, { method: 'POST', body: JSON.stringify(input) }),
        onSuccess: invalidate,
    });
}

export function useConfirmSettlement(settlementId: number) {
    const invalidate = useInvalidate();
    return useMutation({
        mutationFn: (key: string) =>
            api<Settlement>(`/finance/settlements/${settlementId}/confirm`, {
                method: 'POST',
                headers: { 'Idempotency-Key': key },
            }),
        onSuccess: invalidate,
    });
}
