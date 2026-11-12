import { keepPreviousData, useInfiniteQuery, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, apiLocale, ApiError, csrfToken, isProblem, type ProblemDetails } from '@/lib/api';

/*
 * Media library client — docs/api/media-library.md is the contract. Every shape below
 * mirrors a schema there; test fixtures (fixtures.ts) mirror the same examples.
 * TODO(contract): switch these to `Schema<'Media'>` etc. from @toymart/api-types once the
 * backend's `composer openapi:export` + `npm run api-types:generate` carries them.
 */

export type MediaKind = 'image' | 'document';
export type AltStatus = 'complete' | 'missing_bn' | 'missing_en' | 'missing' | 'not_applicable';
export type ProcessingStatus = 'processing' | 'ready' | 'failed';
export type MediaSort = 'newest' | 'oldest' | 'name' | 'size';
export type MissingAlt = 'bn' | 'en';
export type SystemFolderKey = 'products' | 'category_banners' | 'blog' | 'pages';

export type Media = {
    id: number;
    kind: MediaKind;
    filename: string;
    caption: string | null;
    alt_text: string | null;
    alt_text_bn: string | null;
    alt_status: AltStatus;
    folder_id: number | null;
    mime: string;
    original_mime: string;
    width: number | null;
    height: number | null;
    size_bytes: number;
    original_size_bytes: number;
    processing_status: ProcessingStatus;
    urls: { thumb: string | null; medium: string | null; large: string | null };
    srcset: { webp: string; avif: string } | null;
    public_url: string | null;
    used_in_count: number;
    origin: 'library' | 'product';
    uploaded_by: { id: number; name: string } | null;
    created_at: string;
    updated_at: string;
};

export type MediaUsage = {
    /** "product_image" | "seo_og_image"; unknown future types are rendered generically. */
    type: string;
    subject_type: string;
    subject_id: number;
    title: string;
    title_bn: string | null;
    position: number | null;
    is_primary: boolean | null;
    admin_path: string;
};

export type Rendition = { format: 'webp' | 'avif'; width: number; bytes: number; url: string };

export type MediaDetail = Media & {
    used_in: MediaUsage[];
    renditions: Rendition[];
    master_bytes: number;
    renditions_bytes: number;
    download_path: string;
};

export type MediaFolder = {
    id: number;
    name: string;
    system_key: SystemFolderKey | null;
    is_system: boolean;
    position: number;
    created_at: string;
};

export type MediaCounts = {
    all: number;
    unused: number;
    unfiled: number;
    folders: { id: number; count: number }[];
    kinds: { image: number; document: number };
    missing_alt: { bn: number; en: number };
};

export type MediaStorage = {
    used_bytes: number;
    originals_bytes: number;
    renditions_bytes: number;
    quota_bytes: number | null;
    file_count: number;
};

export type MediaPage = {
    data: Media[];
    meta: { per_page: number; next_cursor: string | null; prev_cursor: string | null; total: number };
};

export type BulkDeleteResult = {
    deleted: number;
    skipped: number;
    results: { id: number; status: 'deleted' | 'in_use' | 'not_found'; used_in_count: number }[];
};

/** The 409 `media-in-use` problem carries the usages as an extension member. */
export type MediaInUseProblem = ProblemDetails & { usages?: MediaUsage[] };

export type MediaPatch = { alt_text?: string | null; alt_text_bn?: string | null; caption?: string | null; folder_id?: number | null };

/** The rail + toolbar state. `folder: null` with `unused: false` is "All media". */
export type MediaFilters = {
    folder: number | 'none' | null;
    unused: boolean;
    kind: MediaKind | null;
    missingAlt: MissingAlt | null;
    search: string;
    sort: MediaSort;
};

export const defaultFilters: MediaFilters = { folder: null, unused: false, kind: null, missingAlt: null, search: '', sort: 'newest' };
export const sorts: MediaSort[] = ['newest', 'oldest', 'name', 'size'];
export const kinds: MediaKind[] = ['image', 'document'];

/** Client-side pre-check only (UX). The server sniffs the real type and is authoritative. */
export const MAX_UPLOAD_BYTES = 10 * 1024 * 1024;
export const ACCEPT = 'image/jpeg,image/png,image/webp,image/avif,application/pdf';

export const mediaKeys = {
    all: ['media'] as const,
    lists: ['media', 'list'] as const,
    list: (filters: MediaFilters, perPage?: number) => ['media', 'list', filters, perPage ?? null] as const,
    counts: ['media', 'counts'] as const,
    storage: ['media', 'storage'] as const,
    folders: ['media', 'folders'] as const,
    detail: (id: number) => ['media', 'detail', id] as const,
};

/** Every filter is a server-side allowlisted parameter (rule 16); nothing is filtered in the browser. */
export function listQuery(filters: MediaFilters, cursor: string | null, perPage?: number): string {
    const params = new URLSearchParams();
    if (filters.folder !== null) params.set('filter[folder]', String(filters.folder));
    if (filters.unused) params.set('filter[unused]', '1');
    if (filters.kind) params.set('filter[kind]', filters.kind);
    if (filters.missingAlt) params.set('filter[missing_alt]', filters.missingAlt);
    const search = filters.search.trim();
    if (search) params.set('filter[search]', search.slice(0, 100));
    if (filters.sort !== 'newest') params.set('sort', filters.sort);
    if (perPage) params.set('per_page', String(perPage));
    if (cursor) params.set('cursor', cursor);
    const query = params.toString();
    return query ? `/media?${query}` : '/media';
}

const anyProcessing = (items: { processing_status: ProcessingStatus }[] | undefined) => items?.some((m) => m.processing_status === 'processing') ?? false;

/** The cursor-paginated list ("Showing 30 of 846" + Load more). Polls every 2 s while a file is processing. */
export function useMediaList(filters: MediaFilters, { perPage, enabled = true }: { perPage?: number; enabled?: boolean } = {}) {
    return useInfiniteQuery({
        queryKey: mediaKeys.list(filters, perPage),
        queryFn: ({ pageParam }) => api<MediaPage>(listQuery(filters, pageParam, perPage)),
        initialPageParam: null as string | null,
        getNextPageParam: (last) => last.meta.next_cursor ?? undefined,
        placeholderData: keepPreviousData,
        enabled,
        refetchInterval: (query) => (anyProcessing(query.state.data?.pages.flatMap((p) => p.data)) ? 2000 : false),
    });
}

export function useMediaCounts() {
    return useQuery({ queryKey: mediaKeys.counts, queryFn: () => api<{ data: MediaCounts }>('/media/counts').then((r) => r.data) });
}

export function useMediaStorage() {
    return useQuery({ queryKey: mediaKeys.storage, queryFn: () => api<{ data: MediaStorage }>('/media/storage').then((r) => r.data) });
}

export function useMediaFolders() {
    return useQuery({
        queryKey: mediaKeys.folders,
        queryFn: () => api<{ data: MediaFolder[] }>('/media/folders').then((r) => r.data),
        staleTime: 5 * 60_000,
    });
}

export function fetchMedia(id: number): Promise<MediaDetail> {
    return api<MediaDetail>(`/media/${id}`);
}

/** One asset with its usages and renditions. Polls while its renditions are being made. */
export function useMedia(id: number | null) {
    return useQuery({
        queryKey: mediaKeys.detail(id ?? 0),
        queryFn: () => fetchMedia(id!),
        enabled: id !== null,
        refetchInterval: (query) => (query.state.data?.processing_status === 'processing' ? 2000 : false),
    });
}

/** Any write can move a file between views, change a count or the storage meter. */
export function useInvalidateMedia() {
    const client = useQueryClient();
    return () => client.invalidateQueries({ queryKey: mediaKeys.all });
}

export function useUpdateMedia() {
    const client = useQueryClient();
    return useMutation({
        mutationFn: ({ id, patch }: { id: number; patch: MediaPatch }) => api<MediaDetail>(`/media/${id}`, { method: 'PATCH', body: JSON.stringify(patch) }),
        onSuccess: (detail) => client.setQueryData(mediaKeys.detail(detail.id), detail),
        onSettled: () => {
            void client.invalidateQueries({ queryKey: mediaKeys.lists });
            void client.invalidateQueries({ queryKey: mediaKeys.counts });
            // Alt text is shared with every product image that uses this file.
            void client.invalidateQueries({ queryKey: ['products'] });
        },
    });
}

export function useDeleteMedia() {
    const invalidate = useInvalidateMedia();
    return useMutation({
        mutationFn: (id: number) => api<void>(`/media/${id}`, { method: 'DELETE' }),
        onSettled: invalidate,
    });
}

/** The usages carried by a 409 `media-in-use`, or null for any other error. */
export function inUseUsages(error: unknown): MediaUsage[] | null {
    if (error instanceof ApiError && error.is('media-in-use')) return (error.problem as MediaInUseProblem).usages ?? [];
    return null;
}

export function useBulkMove() {
    const invalidate = useInvalidateMedia();
    return useMutation({
        mutationFn: ({ ids, folderId }: { ids: number[]; folderId: number | null }) =>
            api<{ data: { moved: number } }>('/media/bulk/move', { method: 'POST', body: JSON.stringify({ ids, folder_id: folderId }) }).then((r) => r.data),
        onSettled: invalidate,
    });
}

export function useBulkDelete() {
    const invalidate = useInvalidateMedia();
    return useMutation({
        mutationFn: (ids: number[]) => api<{ data: BulkDeleteResult }>('/media/bulk/delete', { method: 'POST', body: JSON.stringify({ ids }) }).then((r) => r.data),
        onSettled: invalidate,
    });
}

/** POST → zip blob. Not `api()`: the success body is binary, the failure an RFC 9457 problem. */
export async function downloadZip(ids: number[]): Promise<{ blob: Blob; filename: string }> {
    const response = await fetch('/api/v1/admin/media/bulk/download', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/zip, application/problem+json',
            'Accept-Language': apiLocale(),
            'Content-Type': 'application/json',
            'X-XSRF-TOKEN': csrfToken(),
        },
        body: JSON.stringify({ ids }),
    });
    if (!response.ok) {
        const payload: unknown = await response.json().catch(() => null);
        throw new ApiError(response.status, isProblem(payload) ? payload : { type: 'about:blank', title: response.statusText || 'Download failed', status: response.status });
    }
    const disposition = response.headers.get('Content-Disposition') ?? '';
    const filename = /filename\*?=(?:UTF-8'')?"?([^";]+)"?/i.exec(disposition)?.[1] ?? 'toymartbd-media.zip';
    return { blob: await response.blob(), filename: decodeURIComponent(filename) };
}

export function useBulkDownload() {
    return useMutation({ mutationFn: downloadZip });
}

/** Hands a blob to the browser as a download. */
export function saveBlob(blob: Blob, filename: string): void {
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    a.remove();
    window.setTimeout(() => URL.revokeObjectURL(url), 1000);
}

export function useCreateFolder() {
    const client = useQueryClient();
    return useMutation({
        mutationFn: (name: string) => api<MediaFolder>('/media/folders', { method: 'POST', body: JSON.stringify({ name }) }),
        onSettled: () => {
            void client.invalidateQueries({ queryKey: mediaKeys.folders });
            void client.invalidateQueries({ queryKey: mediaKeys.counts });
        },
    });
}

export function useRenameFolder() {
    const client = useQueryClient();
    return useMutation({
        mutationFn: ({ id, name }: { id: number; name: string }) => api<MediaFolder>(`/media/folders/${id}`, { method: 'PATCH', body: JSON.stringify({ name }) }),
        onSettled: () => client.invalidateQueries({ queryKey: mediaKeys.folders }),
    });
}

export function useDeleteFolder() {
    const invalidate = useInvalidateMedia();
    return useMutation({
        mutationFn: (id: number) => api<void>(`/media/folders/${id}`, { method: 'DELETE' }),
        onSettled: invalidate,
    });
}

/**
 * Multipart write over XMLHttpRequest — fetch cannot report upload progress. Same contract
 * as `api()`: cookie session, CSRF header, RFC 9457 problems as ApiError; an abort rejects.
 */
export function sendMultipart<T>(path: string, body: FormData, onProgress?: (loaded: number, total: number) => void, signal?: AbortSignal): Promise<T> {
    return new Promise((resolve, reject) => {
        const xhr = new XMLHttpRequest();
        xhr.open('POST', `/api/v1/admin${path}`);
        xhr.withCredentials = true;
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.setRequestHeader('Accept-Language', apiLocale());
        xhr.setRequestHeader('X-XSRF-TOKEN', csrfToken());
        xhr.upload.onprogress = (event) => {
            if (event.lengthComputable) onProgress?.(event.loaded, event.total);
        };
        const fail = (status: number, title: string) => reject(new ApiError(status, { type: 'about:blank', title, status }));
        xhr.onerror = () => fail(0, 'Network error');
        xhr.onabort = () => fail(0, 'Aborted');
        xhr.onload = () => {
            let payload: unknown = null;
            try {
                payload = JSON.parse(xhr.responseText);
            } catch {
                payload = null;
            }
            if (xhr.status >= 200 && xhr.status < 300) resolve(payload as T);
            else reject(new ApiError(xhr.status, isProblem(payload) ? payload : { type: 'about:blank', title: xhr.statusText || 'Upload failed', status: xhr.status }));
        };
        signal?.addEventListener('abort', () => xhr.abort(), { once: true });
        xhr.send(body);
    });
}

export type UploadFields = { folderId?: number | null; altText?: string; altTextBn?: string; caption?: string };

/** POST /media — one file per request (the queue shows per-file progress). 201 `Media`. */
export function uploadMedia(file: File, fields: UploadFields = {}, onProgress?: (loaded: number, total: number) => void, signal?: AbortSignal): Promise<Media> {
    const body = new FormData();
    body.append('file', file);
    if (fields.folderId) body.append('folder_id', String(fields.folderId));
    if (fields.altText) body.append('alt_text', fields.altText);
    if (fields.altTextBn) body.append('alt_text_bn', fields.altTextBn);
    if (fields.caption) body.append('caption', fields.caption);
    return sendMultipart<Media>('/media', body, onProgress, signal);
}

/** POST /media/{id}/replace — same kind only; keeps the id, alt, folder and every usage. */
export function replaceMedia(id: number, file: File, onProgress?: (loaded: number, total: number) => void, signal?: AbortSignal): Promise<MediaDetail> {
    const body = new FormData();
    body.append('file', file);
    return sendMultipart<MediaDetail>(`/media/${id}/replace`, body, onProgress, signal);
}

export function useReplaceMedia() {
    const client = useQueryClient();
    return useMutation({
        mutationFn: ({ id, file, onProgress }: { id: number; file: File; onProgress?: (loaded: number, total: number) => void }) => replaceMedia(id, file, onProgress),
        onSuccess: (detail) => client.setQueryData(mediaKeys.detail(detail.id), detail),
        onSettled: () => {
            void client.invalidateQueries({ queryKey: mediaKeys.all });
            void client.invalidateQueries({ queryKey: ['products'] });
        },
    });
}

/**
 * POST /products/{product}/images with `media_id` — attach a library image to a product
 * gallery (appended last; primary when first). Returns the unchanged ProductImage shape.
 * Callers insert in pick order, one request at a time, so the gallery order is the pick order.
 */
export function attachMediaToProduct<T = unknown>(productId: number, mediaId: number, alt?: { alt_text?: string; alt_text_bn?: string }): Promise<T> {
    return api<T>(`/products/${productId}/images`, { method: 'POST', body: JSON.stringify({ media_id: mediaId, ...alt }) });
}

/** The server's text for a refused file: the 422 on `file` (or `ids`), else the problem's detail. */
export function problemMessage(error: unknown, field = 'file'): string | null {
    if (!(error instanceof ApiError)) return null;
    return error.fieldError(field) ?? error.problem.errors?.[0]?.message ?? error.problem.detail ?? error.problem.title ?? null;
}
