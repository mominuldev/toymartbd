import { useEffect, useState } from 'react';
import { keepPreviousData, useInfiniteQuery, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import type { Schema } from '@toymart/api-types';
import type { StoreProductRequestInput } from '@toymart/api-types/zod';
import { ApiError, api, apiLocale, csrfToken, isProblem } from '@/lib/api';
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
export type ProductSeo = Product['seo'];
export type SeoChecklistItem = ProductSeo['checklist']['items'][number];
export type ProductCompliance = Product['compliance'];
export type StockByLocation = NonNullable<Product['stock_by_location']>[number];
/** A server-computed per-unit margin (rule 1: the admin never computes one). */
export type Margin = NonNullable<Product['margin']>;
export type SeoMeasure = ProductSeo['measure'];
export type RobotsDirective = ProductSeo['robots'];
export type TwitterCard = ProductSeo['twitter_card'];
export const robotsDirectives: RobotsDirective[] = ['index,follow', 'noindex,follow', 'index,nofollow', 'noindex,nofollow'];
export const twitterCards: TwitterCard[] = ['summary_large_image', 'summary'];

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

/** A value that settles `ms` after the last change — for the server-side previews below. */
export function useDebouncedValue<T>(value: T, ms = 300): T {
    const [debounced, setDebounced] = useState(value);
    useEffect(() => {
        const timer = window.setTimeout(() => setDebounced(value), ms);
        return () => window.clearTimeout(timer);
    }, [value, ms]);
    return debounced;
}

export type MarginRow = { price: string; cost_price: string };

/**
 * Margins for unsaved prices and costs, computed by the API (`POST /products/margin-preview`,
 * catalog.view_cost). `rows[i]` answers `rows[i]`; null where a price or cost is missing.
 */
export function useMarginPreview(rows: MarginRow[], vatRate: string, enabled: boolean) {
    const input = useDebouncedValue(JSON.stringify({ rows, vat: vatRate }));
    return useQuery({
        queryKey: ['products', 'margin-preview', input],
        queryFn: () => {
            const { rows: sent, vat } = JSON.parse(input) as { rows: MarginRow[]; vat: string };
            return api<{ rows: (Margin | null)[] }>('/products/margin-preview', {
                method: 'POST',
                body: JSON.stringify({
                    vat_rate: /^\d{1,3}(\.\d{1,2})?$/.test(vat.trim()) ? vat.trim() : null,
                    rows: sent.map((r) => ({ price: clean(r.price), cost_price: clean(r.cost_price) })),
                }),
            }).then((result) => result.rows);
        },
        enabled: enabled && rows.length > 0,
        placeholderData: keepPreviousData,
        staleTime: 60_000,
        retry: false,
    });
}

/** A money string the API accepts, or null (an unfinished edit simply has no margin yet). */
function clean(value: string): string | null {
    const v = value.trim().replace(/,/g, '');
    return /^\d{1,12}(\.\d{1,2})?$/.test(v) ? v : null;
}

/** The server's SERP pixel measurement of an unsaved meta title and description. */
export function useSeoMeasure(title: string, description: string) {
    const input = useDebouncedValue(JSON.stringify([title, description]));
    return useQuery({
        queryKey: ['products', 'seo-measure', input],
        queryFn: () => {
            const [t, d] = JSON.parse(input) as [string, string];
            return api<SeoMeasure>('/products/seo-measure', { method: 'POST', body: JSON.stringify({ title: t.slice(0, 500), description: d.slice(0, 1000) }) });
        },
        placeholderData: keepPreviousData,
        staleTime: 5 * 60_000,
        retry: false,
    });
}

/** Where the certificate PDF downloads from (a cookie-session GET; served as an attachment). */
export const certificateUrl = (productId: number) => `/api/v1/admin/products/${productId}/compliance/certificate`;

function patchCompliance(client: ReturnType<typeof useQueryClient>, productId: number, compliance: ProductCompliance) {
    client.setQueryData<Product>(keys.one(productId), (current) => (current ? { ...current, compliance } : current));
}

/** Attach or replace the BSTI certificate PDF (multipart; content-sniffed by the server). */
export function useUploadCertificate(productId: number) {
    const client = useQueryClient();
    return useMutation({
        mutationFn: async (file: File) => {
            const body = new FormData();
            body.append('file', file);
            const response = await fetch(`/api/v1/admin/products/${productId}/compliance/certificate`, {
                method: 'POST',
                body,
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'Accept-Language': apiLocale(), 'X-XSRF-TOKEN': csrfToken() },
            });
            const payload: unknown = await response.json().catch(() => null);
            if (!response.ok) {
                throw new ApiError(response.status, isProblem(payload) ? payload : { type: 'about:blank', title: response.statusText || 'Upload failed', status: response.status });
            }
            return payload as ProductCompliance;
        },
        onSuccess: (compliance) => patchCompliance(client, productId, compliance),
    });
}

export function useRemoveCertificate(productId: number) {
    const client = useQueryClient();
    return useMutation({
        mutationFn: () => api<ProductCompliance>(`/products/${productId}/compliance/certificate`, { method: 'DELETE' }),
        onSuccess: (compliance) => patchCompliance(client, productId, compliance),
    });
}
