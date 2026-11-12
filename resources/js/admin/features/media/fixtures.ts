/**
 * Test fixtures for the media library (Vitest only). Every shape mirrors an example in
 * docs/api/media-library.md exactly; Bangla in every fixture (rule 34). Demo filenames use
 * product types, not real brands (lead decision 5).
 */
import { mockApi, viewer } from '@/test/renderApp';
import type { Me } from '@/features/auth/api';
import type { BulkDeleteResult, Media, MediaCounts, MediaDetail, MediaFolder, MediaPage, MediaStorage, MediaUsage } from './api';

/** Manager: view, manage and delete (contract decision 7). */
export const mediaManager: Me = {
    ...viewer,
    roles: ['manager'],
    permissions: ['dashboard.view', 'catalog.view', 'catalog.update', 'content.view', 'media.manage', 'media.delete'],
};

/** Content editor: upload and edit, never delete or bulk download. */
export const contentEditor: Me = {
    ...viewer,
    roles: ['content_editor'],
    permissions: ['dashboard.view', 'catalog.view', 'content.view', 'media.manage'],
};

export const folders: MediaFolder[] = [
    { id: 1, name: 'Products', system_key: 'products', is_system: true, position: 0, created_at: '2026-11-12T08:30:00+00:00' },
    { id: 2, name: 'Category banners', system_key: 'category_banners', is_system: true, position: 1, created_at: '2026-11-12T08:30:00+00:00' },
    { id: 3, name: 'Blog', system_key: 'blog', is_system: true, position: 2, created_at: '2026-11-12T08:30:00+00:00' },
    { id: 4, name: 'Pages', system_key: 'pages', is_system: true, position: 3, created_at: '2026-11-12T08:30:00+00:00' },
    { id: 7, name: 'ঈদ ক্যাম্পেইন 2026', system_key: null, is_system: false, position: 100, created_at: '2026-11-12T09:00:00+00:00' },
];

const base = 'https://cdn.example.test/media/7f3a';

/** The contract's `Media` example: used in 3 places, both alts set. */
export const brickBox: Media = {
    id: 42,
    kind: 'image',
    filename: 'brick-box-front.jpg',
    caption: null,
    alt_text: 'Brick Box 500, front of the box',
    alt_text_bn: 'ব্রিক বক্স ৫০০, বাক্সের সামনে',
    alt_status: 'complete',
    folder_id: 1,
    mime: 'image/webp',
    original_mime: 'image/jpeg',
    width: 1600,
    height: 1600,
    size_bytes: 184320,
    original_size_bytes: 2411724,
    processing_status: 'ready',
    urls: { thumb: `${base}/320.webp`, medium: `${base}/480.webp`, large: `${base}/1200.webp` },
    srcset: { webp: `${base}/160.webp 160w, ${base}/320.webp 320w`, avif: `${base}/160.avif 160w, ${base}/320.avif 320w` },
    public_url: 'https://toymartbd.com/m/42/brick-box-front.webp',
    used_in_count: 3,
    origin: 'library',
    uploaded_by: { id: 7, name: 'Nusrat Jahan' },
    created_at: '2026-11-12T08:30:00+00:00',
    updated_at: '2026-11-12T08:30:00+00:00',
};

/** Unused, Bangla alt missing. */
export const crayonSet: Media = {
    ...brickBox,
    id: 43,
    filename: 'crayon-set-old-03.jpg',
    alt_text: 'Crayon set with 24 colours',
    alt_text_bn: null,
    alt_status: 'missing_bn',
    used_in_count: 0,
    public_url: 'https://toymartbd.com/m/43/crayon-set-old-03.webp',
    uploaded_by: { id: 8, name: 'রাকিব হাসান' },
    created_at: '2026-08-14T05:05:00+00:00',
};

/** A BSTI certificate PDF: no alt needed, no public URL (contract decision 8). */
export const certificate: Media = {
    ...brickBox,
    id: 44,
    kind: 'document',
    filename: 'bsti-cert-shape-sorter.pdf',
    alt_text: null,
    alt_text_bn: null,
    alt_status: 'not_applicable',
    mime: 'application/pdf',
    original_mime: 'application/pdf',
    width: null,
    height: null,
    size_bytes: 1887436,
    original_size_bytes: 1887436,
    urls: { thumb: null, medium: null, large: null },
    srcset: null,
    public_url: null,
    used_in_count: 1,
};

export const productUsage: MediaUsage = {
    type: 'product_image',
    subject_type: 'product',
    subject_id: 12,
    title: 'Brick Box 500 pcs',
    title_bn: 'ব্রিক বক্স ৫০০ পিস',
    position: 1,
    is_primary: true,
    admin_path: '/admin/products/12',
};

export const brickBoxDetail: MediaDetail = {
    ...brickBox,
    used_in: [
        productUsage,
        { ...productUsage, subject_id: 15, title: 'Brick Box 1000 pcs', title_bn: 'ব্রিক বক্স ১০০০ পিস', position: 3, is_primary: false, admin_path: '/admin/products/15' },
        { type: 'seo_og_image', subject_type: 'product', subject_id: 12, title: 'Brick Box 500 pcs', title_bn: 'ব্রিক বক্স ৫০০ পিস', position: null, is_primary: null, admin_path: '/admin/products/12' },
    ],
    renditions: [
        { format: 'webp', width: 160, bytes: 5120, url: `${base}/160.webp` },
        { format: 'avif', width: 160, bytes: 4096, url: `${base}/160.avif` },
        { format: 'webp', width: 1200, bytes: 120832, url: `${base}/1200.webp` },
        { format: 'avif', width: 1200, bytes: 90112, url: `${base}/1200.avif` },
    ],
    master_bytes: 402113,
    renditions_bytes: 512000,
    download_path: '/api/v1/admin/media/42/download',
};

export const crayonDetail: MediaDetail = { ...crayonSet, used_in: [], renditions: brickBoxDetail.renditions, master_bytes: 300000, renditions_bytes: 200000, download_path: '/api/v1/admin/media/43/download' };

export const counts: MediaCounts = {
    all: 1284,
    unused: 61,
    unfiled: 4,
    folders: [
        { id: 1, count: 846 },
        { id: 2, count: 37 },
        { id: 3, count: 198 },
        { id: 4, count: 64 },
        { id: 7, count: 0 },
    ],
    kinds: { image: 1270, document: 14 },
    missing_alt: { bn: 37, en: 5 },
};

export const storage: MediaStorage = {
    used_bytes: 3435973837,
    originals_bytes: 1288490188,
    renditions_bytes: 2147483649,
    quota_bytes: null,
    file_count: 1284,
};

export const pageOf = (data: Media[], { total = data.length, next = null as string | null } = {}): MediaPage & { links: object } => ({
    data,
    links: { first: null, last: null, prev: null, next: next ? `/api/v1/admin/media?cursor=${next}` : null },
    meta: { per_page: 30, next_cursor: next, prev_cursor: null, total },
});

export const bulkDeleteResult: BulkDeleteResult = {
    deleted: 1,
    skipped: 1,
    results: [
        { id: 43, status: 'deleted', used_in_count: 0 },
        { id: 42, status: 'in_use', used_in_count: 3 },
    ],
};

export const inUseProblem = {
    type: 'https://toymartbd.com/problems/media-in-use',
    title: 'Media in use',
    status: 409,
    detail: 'This file is used in 3 places. Remove it from them, or replace the file instead.',
    usages: brickBoxDetail.used_in,
};

export const validationProblem = (field: string, message: string) => ({
    status: 422,
    body: { type: 'https://toymartbd.com/problems/validation-error', title: 'Validation failed', status: 422, errors: [{ field, message }] },
});

type Reply = { status: number; body?: unknown };

/** The media API with sensible defaults; `handler` answers first. */
export function mediaApi(me: Me, handler?: (url: string, init?: RequestInit) => Reply | undefined, list: Media[] = [brickBox, crayonSet, certificate]) {
    return mockApi((url, init) => {
        const custom = handler?.(url, init);
        if (custom) return custom;
        const method = (init?.method ?? 'GET').toUpperCase();
        if (url.endsWith('/auth/me')) return { status: 200, body: me };
        if (url.endsWith('/media/counts')) return { status: 200, body: { data: counts } };
        if (url.endsWith('/media/storage')) return { status: 200, body: { data: storage } };
        if (url.endsWith('/media/folders') && method === 'GET') return { status: 200, body: { data: folders } };
        if (/\/media\/42$/.test(url) && method === 'GET') return { status: 200, body: brickBoxDetail };
        if (/\/media\/43$/.test(url) && method === 'GET') return { status: 200, body: crayonDetail };
        if (/\/media(\?|$)/.test(url) && method === 'GET') return { status: 200, body: pageOf(list, { total: 846 }) };
        return { status: 404 };
    });
}

/** The JSON body of the first call matching `method` and `path`. */
export function bodyOf(calls: ReturnType<typeof mockApi>, method: string, path: RegExp): Record<string, unknown> | null {
    const call = calls.mock.calls.find(([url, init]) => path.test(String(url)) && ((init as RequestInit | undefined)?.method ?? 'GET').toUpperCase() === method);
    if (!call) return null;
    return JSON.parse(String((call[1] as RequestInit).body)) as Record<string, unknown>;
}
