import { keepPreviousData, useInfiniteQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import type { Schema } from '@toymart/api-types';
import { api } from '@/lib/api';

export type AdminReview = Schema<'AdminReview'>;
export type ReviewStatus = AdminReview['status'];
export const reviewStatuses: ReviewStatus[] = ['pending', 'approved', 'rejected'];

export type ReviewFilters = {
    status: ReviewStatus | '';
    search: string;
    rating: string;
    verified: '' | '0' | '1';
};

type Counts = Record<ReviewStatus, number>;
type Page = { data: AdminReview[]; meta: { next_cursor?: string | null; counts: Counts } };

const keys = {
    all: ['reviews'] as const,
    list: (filters: ReviewFilters) => ['reviews', 'list', filters] as const,
};

/** Every filter is a server-side allowlisted query parameter (rule 16). */
export function listPath(filters: ReviewFilters, cursor: string | null): string {
    const params = new URLSearchParams();
    if (filters.status) params.set('filter[status]', filters.status);
    if (filters.search) params.set('filter[search]', filters.search);
    if (filters.rating) params.set('filter[rating]', filters.rating);
    if (filters.verified) params.set('filter[verified]', filters.verified);
    if (cursor) params.set('cursor', cursor);
    const query = params.toString();
    return `/reviews${query ? `?${query}` : ''}`;
}

export function useReviews(filters: ReviewFilters) {
    return useInfiniteQuery({
        queryKey: keys.list(filters),
        queryFn: ({ pageParam }) => api<Page>(listPath(filters, pageParam)),
        initialPageParam: null as string | null,
        getNextPageParam: (last) => last.meta.next_cursor ?? null,
        placeholderData: keepPreviousData,
    });
}

/** Approve / reject: the server recomputes the product's rating in the same transaction. */
export function useModerateReview() {
    const client = useQueryClient();

    return useMutation({
        mutationFn: ({ id, action }: { id: number; action: 'approve' | 'reject' }) => api<AdminReview>(`/reviews/${id}/${action}`, { method: 'POST' }),
        onSuccess: () => void client.invalidateQueries({ queryKey: keys.all }),
    });
}

export function useReplyToReview() {
    const client = useQueryClient();

    return useMutation({
        mutationFn: ({ id, reply }: { id: number; reply: string }) => api<AdminReview>(`/reviews/${id}/reply`, { method: 'PUT', body: JSON.stringify({ reply }) }),
        onSuccess: () => void client.invalidateQueries({ queryKey: keys.all }),
    });
}
