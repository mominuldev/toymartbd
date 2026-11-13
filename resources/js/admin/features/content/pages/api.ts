import { keepPreviousData, useInfiniteQuery, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import type { Schema } from '@toymart/api-types';
import type { CmsPageRequestInput } from '@toymart/api-types/zod';
import { api } from '@/lib/api';

export type CmsPage = Schema<'CmsPage'>;
export type PageState = CmsPage['state'];
export type PageStatusFilter = 'all' | 'draft' | 'published' | 'archived';
export type CmsPageInput = CmsPageRequestInput;

type ListPage = { data: CmsPage[]; meta: { next_cursor?: string | null } };

const keys = {
    all: ['content-pages'] as const,
    list: (search: string, status: PageStatusFilter) => ['content-pages', 'list', { search, status }] as const,
    one: (id: number) => ['content-pages', 'one', id] as const,
};

export function pagesPath(search: string, status: PageStatusFilter, cursor: string | null): string {
    const params = new URLSearchParams();
    if (search) params.set('filter[search]', search);
    if (status !== 'all') params.set('filter[status]', status);
    if (cursor) params.set('cursor', cursor);
    const query = params.toString();
    return `/content/pages${query ? `?${query}` : ''}`;
}

export function usePages(search: string, status: PageStatusFilter) {
    return useInfiniteQuery({
        queryKey: keys.list(search, status),
        queryFn: ({ pageParam }) => api<ListPage>(pagesPath(search, status, pageParam)),
        initialPageParam: null as string | null,
        getNextPageParam: (last) => last.meta.next_cursor ?? null,
        placeholderData: keepPreviousData,
    });
}

export function usePage(id: number | null) {
    return useQuery({ queryKey: keys.one(id ?? 0), queryFn: () => api<CmsPage>(`/content/pages/${id}`), enabled: id !== null });
}

export function useSavePage(id: number | null) {
    const client = useQueryClient();
    return useMutation({
        mutationFn: (input: CmsPageInput) =>
            id === null
                ? api<CmsPage>('/content/pages', { method: 'POST', body: JSON.stringify(input) })
                : api<CmsPage>(`/content/pages/${id}`, { method: 'PUT', body: JSON.stringify(input) }),
        onSuccess: (page) => {
            client.setQueryData(keys.one(page.id), page);
            void client.invalidateQueries({ queryKey: [...keys.all, 'list'] });
        },
    });
}

export function useArchivePage(id: number) {
    const client = useQueryClient();
    return useMutation({
        mutationFn: () => api<CmsPage>(`/content/pages/${id}`, { method: 'DELETE' }),
        onSuccess: (page) => {
            client.setQueryData(keys.one(page.id), page);
            void client.invalidateQueries({ queryKey: [...keys.all, 'list'] });
        },
    });
}
