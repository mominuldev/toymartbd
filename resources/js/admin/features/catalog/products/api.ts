import { keepPreviousData, useInfiniteQuery, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import type { Schema } from '@toymart/api-types';
import type { StoreProductRequestInput } from '@toymart/api-types/zod';
import { api } from '@/lib/api';
import type { Brand } from '../brands/api';

export type Product = Schema<'Product'>;
export type ProductListItem = Schema<'ProductListItem'>;
export type ProductVariant = Schema<'ProductVariant'>;
export type ProductRelation = Schema<'ProductRelation'>;
export type ProductStatus = Product['status'];
export type ProductType = Product['type'];
export type RelationType = ProductRelation['type'];
export type Gender = NonNullable<Product['gender']>;
/** The POST/PUT document — the generated request type, never a hand-written copy (rule 27). */
export type ProductInput = StoreProductRequestInput;

export const productStatuses: ProductStatus[] = ['active', 'draft', 'archived'];
export const productTypes: ProductType[] = ['simple', 'variable'];
export const relationTypes: RelationType[] = ['related', 'upsell', 'cross_sell', 'bundle', 'age_progression'];
export const genders: Gender[] = ['boys', 'girls', 'unisex'];
export const productSorts = ['-updated_at', 'updated_at', 'name', '-name', '-created_at', 'created_at'] as const;
export type ProductSort = (typeof productSorts)[number];

export type ProductFilters = {
    search: string;
    status: ProductStatus | '';
    type: ProductType | '';
    brand: string;
    category: string;
    sort: ProductSort;
};

type Page<T> = { data: T[]; meta: { next_cursor?: string | null }; links: { next?: string | null } };

const keys = {
    all: ['products'] as const,
    list: (filters: ProductFilters) => ['products', 'list', filters] as const,
    one: (id: number) => ['products', 'one', id] as const,
    picker: (term: string) => ['products', 'picker', term] as const,
};

/** Every filter is a server-side allowlisted query parameter; nothing is filtered in the browser. */
export function listPath(filters: ProductFilters, cursor: string | null): string {
    const params = new URLSearchParams();
    if (filters.search) params.set('filter[search]', filters.search);
    if (filters.status) params.set('filter[status]', filters.status);
    if (filters.type) params.set('filter[type]', filters.type);
    if (filters.brand) params.set('filter[brand_id]', filters.brand);
    if (filters.category) params.set('filter[category_id]', filters.category);
    if (filters.sort !== '-updated_at') params.set('sort', filters.sort);
    if (cursor) params.set('cursor', cursor);
    const query = params.toString();
    return `/products${query ? `?${query}` : ''}`;
}

export function useProducts(filters: ProductFilters) {
    return useInfiniteQuery({
        queryKey: keys.list(filters),
        queryFn: ({ pageParam }) => api<Page<ProductListItem>>(listPath(filters, pageParam)),
        initialPageParam: null as string | null,
        getNextPageParam: (last) => last.meta.next_cursor ?? null,
        placeholderData: keepPreviousData,
    });
}

export function useProduct(id: number | null) {
    return useQuery({
        queryKey: keys.one(id ?? 0),
        queryFn: () => api<Product>(`/products/${id}`),
        enabled: id !== null,
    });
}

/** POST creates; PUT replaces the whole document (fields, categories, matrix, curated relations). */
export function useSaveProduct(id: number | null) {
    const client = useQueryClient();

    return useMutation({
        mutationFn: (input: ProductInput) =>
            id === null
                ? api<Product>('/products', { method: 'POST', body: JSON.stringify(input) })
                : api<Product>(`/products/${id}`, { method: 'PUT', body: JSON.stringify(input) }),
        onSuccess: (product) => {
            client.setQueryData(keys.one(product.id), product);
            void client.invalidateQueries({ queryKey: [...keys.all, 'list'] });
        },
    });
}

/** A draft copy with a new slug and new SKUs, made by the server. */
export function useDuplicateProduct(id: number) {
    const client = useQueryClient();

    return useMutation({
        mutationFn: () => api<Product>(`/products/${id}/duplicate`, { method: 'POST' }),
        onSuccess: (copy) => {
            client.setQueryData(keys.one(copy.id), copy);
            void client.invalidateQueries({ queryKey: [...keys.all, 'list'] });
        },
    });
}

export function useArchiveProduct(id: number) {
    const client = useQueryClient();

    return useMutation({
        mutationFn: () => api<void>(`/products/${id}`, { method: 'DELETE' }),
        onSuccess: () => void client.invalidateQueries({ queryKey: keys.all }),
    });
}

/** Product search for the relation picker (server-side `filter[search]`). */
export function useProductSearch(term: string) {
    return useQuery({
        queryKey: keys.picker(term),
        queryFn: () => api<Page<ProductListItem>>(listPath({ search: term, status: '', type: '', brand: '', category: '', sort: 'name' }, null)).then((page) => page.data),
        enabled: term.length >= 2,
        placeholderData: keepPreviousData,
    });
}

/**
 * Every brand, for the brand select and filter. Brands are few; the cursor pages are
 * walked once and cached.
 */
export function useBrandOptions() {
    return useQuery({
        queryKey: ['brands', 'options'],
        queryFn: async () => {
            const all: Brand[] = [];
            let cursor: string | null = null;
            do {
                const path: string = `/brands${cursor ? `?cursor=${encodeURIComponent(cursor)}` : ''}`;
                const page: Page<Brand> = await api<Page<Brand>>(path);
                all.push(...page.data);
                cursor = page.meta.next_cursor ?? null;
            } while (cursor !== null && all.length < 1000);
            return all;
        },
        staleTime: 5 * 60_000,
    });
}
