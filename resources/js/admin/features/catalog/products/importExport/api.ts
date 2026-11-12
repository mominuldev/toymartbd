import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import type { Schema } from '@toymart/api-types';
import { ApiError, api, apiLocale, csrfToken, isProblem } from '@/lib/api';
import type { ProductFilters } from '../api';

export type ProductImport = Schema<'ProductImport'>;
export type ImportFormat = 'csv' | 'xlsx';

/** The admin API base the browser downloads from (same origin, cookie session). */
const BASE = '/api/v1/admin';

const runningStatuses: ProductImport['status'][] = ['queued', 'previewing', 'importing'];

export function isRunning(status: ProductImport['status']): boolean {
    return runningStatuses.includes(status);
}

/** The export URL: the list's own allowlisted filters, so the file is exactly the list on screen. */
export function exportUrl(filters: ProductFilters, format: ImportFormat): string {
    const params = new URLSearchParams({ format });
    if (filters.search) params.set('filter[search]', filters.search);
    if (filters.status) params.set('filter[status]', filters.status);
    if (filters.type) params.set('filter[type]', filters.type);
    if (filters.brand) params.set('filter[brand_id]', filters.brand);
    if (filters.category) params.set('filter[category_id]', filters.category);
    return `${BASE}/products/export?${params.toString()}`;
}

export function errorReportUrl(id: number): string {
    return `${BASE}/product-imports/${id}/errors`;
}

/** Multipart POST (the browser sets the boundary); same contract as `api()`. */
async function upload(body: FormData): Promise<ProductImport> {
    const response = await fetch(`${BASE}/product-imports`, {
        method: 'POST',
        body,
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'Accept-Language': apiLocale(), 'X-XSRF-TOKEN': csrfToken() },
    });
    const payload: unknown = await response.json().catch(() => null);
    if (!response.ok) {
        throw new ApiError(response.status, isProblem(payload) ? payload : { type: 'about:blank', title: response.statusText || 'Upload failed', status: response.status });
    }
    return payload as ProductImport;
}

/** Upload the file; the server queues a preview (dry run) of every row. */
export function useUploadImport() {
    return useMutation({
        mutationFn: (file: File) => {
            const body = new FormData();
            body.append('file', file);
            return upload(body);
        },
    });
}

/** Polls while the preview or the import runs; progress and counts come from the server. */
export function useProductImport(id: number | null) {
    return useQuery({
        queryKey: ['product-imports', id],
        queryFn: () => api<ProductImport>(`/product-imports/${id}`),
        enabled: id !== null,
        refetchInterval: (query) => (query.state.data && isRunning(query.state.data.status) ? 1000 : false),
    });
}

export function useCommitImport(id: number | null) {
    const client = useQueryClient();

    return useMutation({
        mutationFn: () => api<ProductImport>(`/product-imports/${id}/commit`, { method: 'POST' }),
        onSuccess: (data) => {
            client.setQueryData(['product-imports', id], data);
            void client.invalidateQueries({ queryKey: ['products', 'list'] });
        },
    });
}
