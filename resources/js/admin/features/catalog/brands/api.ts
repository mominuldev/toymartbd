import { keepPreviousData, useInfiniteQuery, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import type { Schema } from '@toymart/api-types';
import { api } from '@/lib/api';

export type Brand = Schema<'Brand'>;
export type BrandStatus = 'all' | 'active' | 'archived';
export type BrandInput = {
    name: string;
    name_bn?: string | null;
    slug?: string | null;
    description?: string | null;
    description_bn?: string | null;
    is_active?: boolean;
};

type Page = { data: Brand[]; meta: { next_cursor?: string | null }; links: { next?: string | null } };

const keys = {
    all: ['brands'] as const,
    list: (search: string, status: BrandStatus) => ['brands', 'list', { search, status }] as const,
    one: (id: number) => ['brands', 'one', id] as const,
};

function listPath(search: string, status: BrandStatus, cursor: string | null): string {
    const params = new URLSearchParams();
    if (search) params.set('filter[search]', search);
    if (status !== 'all') params.set('filter[status]', status);
    if (cursor) params.set('cursor', cursor);
    const query = params.toString();
    return `/brands${query ? `?${query}` : ''}`;
}

/** Cursor-paginated brand list (`Load more`), filtered server-side — never in the browser. */
export function useBrands(search: string, status: BrandStatus) {
    return useInfiniteQuery({
        queryKey: keys.list(search, status),
        queryFn: ({ pageParam }) => api<Page>(listPath(search, status, pageParam)),
        initialPageParam: null as string | null,
        getNextPageParam: (last) => last.meta.next_cursor ?? null,
        placeholderData: keepPreviousData,
    });
}

export function useBrand(id: number | null) {
    return useQuery({
        queryKey: keys.one(id ?? 0),
        queryFn: () => api<Brand>(`/brands/${id}`),
        enabled: id !== null,
    });
}

export function useSaveBrand(id: number | null) {
    const client = useQueryClient();

    return useMutation({
        mutationFn: (input: BrandInput) =>
            id === null
                ? api<Brand>('/brands', { method: 'POST', body: JSON.stringify(input) })
                : api<Brand>(`/brands/${id}`, { method: 'PATCH', body: JSON.stringify(input) }),
        onSuccess: (brand) => {
            client.setQueryData(keys.one(brand.id), brand);
            void client.invalidateQueries({ queryKey: [...keys.all, 'list'] });
        },
    });
}

export function useArchiveBrand(id: number) {
    const client = useQueryClient();

    return useMutation({
        mutationFn: () => api<void>(`/brands/${id}`, { method: 'DELETE' }),
        onSuccess: () => void client.invalidateQueries({ queryKey: keys.all }),
    });
}
