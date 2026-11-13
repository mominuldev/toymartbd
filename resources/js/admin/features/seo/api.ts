import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import type { Schema } from '@toymart/api-types';
import { ApiError, api, apiLocale, csrfToken, isProblem } from '@/lib/api';
import { useDebouncedValue } from './parts';

// SEO module client (docs/api/seo.md §2). Every figure — SERP truncation, sitemap counts,
// redirect targets after chain collapse — comes from the server (rule 1).

export type SeoDocument = Omit<Schema<'SeoDocument'>, 'in_sitemap'> & { in_sitemap: boolean };
export type SerpPreview = SeoDocument['serp']['en'];
export type Redirect = Schema<'Redirect'>;
export type SlugRedirect = Schema<'SlugRedirect'>;
export type SitemapStatus = Omit<Schema<'SitemapStatus'>, 'total_urls'> & { total_urls: number };
export type RobotsTxt = Schema<'RobotsTxt'>;
export type SeoLocale = 'en' | 'bn';

export const robotsDirectives = ['index,follow', 'noindex,follow', 'index,nofollow', 'noindex,nofollow'] as const;
export const twitterCards = ['summary_large_image', 'summary'] as const;

export type SeoInput = {
    title: string | null;
    title_bn: string | null;
    description: string | null;
    description_bn: string | null;
    og_title: string | null;
    og_title_bn: string | null;
    og_description: string | null;
    og_description_bn: string | null;
    og_image: string | null;
    canonical_url: string | null;
    robots: (typeof robotsDirectives)[number];
    sitemap_exclude: boolean;
    twitter_card: (typeof twitterCards)[number];
    focus_keyword: string | null;
};

export type RedirectInput = { from_path: string; to_path: string; status_code: '301' | '302'; is_active?: boolean; note?: string | null };
export type ImportResult = { created: number; updated: number; errors: { line: number; field: string; message: string }[] };

type Paged<T> = { data: T[]; meta: { current_page: number; last_page: number; total: number } };

export const seoKeys = {
    doc: (type: string, id: number) => ['seo', 'meta', type, id] as const,
    redirects: ['seo', 'redirects'] as const,
    slugs: ['seo', 'slug-history'] as const,
    sitemap: ['seo', 'sitemap'] as const,
    robots: ['seo', 'robots'] as const,
};

export function useSeoDocument(type: string, id: number) {
    return useQuery({ queryKey: seoKeys.doc(type, id), queryFn: () => api<SeoDocument>(`/seo/meta/${type}/${id}`) });
}

export function useSaveSeoDocument(type: string, id: number) {
    const client = useQueryClient();
    return useMutation({
        mutationFn: (input: SeoInput) => api<SeoDocument>(`/seo/meta/${type}/${id}`, { method: 'PUT', body: JSON.stringify(input) }),
        onSuccess: (doc) => client.setQueryData(seoKeys.doc(type, id), doc),
    });
}

/** The server's truncation of unsaved text (debounced). Undefined until the first answer. */
export function useSerpPreview(title: string, description: string, url: string, enabled: boolean) {
    const input = useDebouncedValue(JSON.stringify([title, description, url]));
    return useQuery({
        queryKey: ['seo', 'serp-preview', input],
        queryFn: () => {
            const [t, d, u] = JSON.parse(input) as [string, string, string];
            return api<SerpPreview>('/seo/serp-preview', { method: 'POST', body: JSON.stringify({ title: t.slice(0, 500), description: d.slice(0, 1000), url: u }) });
        },
        enabled,
        placeholderData: keepPreviousData,
        staleTime: 5 * 60_000,
        retry: false,
    });
}

export function useRedirects(page: number, q: string) {
    const params = new URLSearchParams({ page: String(page) });
    if (q) params.set('filter[q]', q);
    return useQuery({
        queryKey: [...seoKeys.redirects, page, q],
        queryFn: () => api<Paged<Redirect>>(`/seo/redirects?${params.toString()}`),
        placeholderData: keepPreviousData,
    });
}

export function useSlugRedirects(page: number) {
    return useQuery({
        queryKey: [...seoKeys.slugs, page],
        queryFn: () => api<Paged<SlugRedirect>>(`/seo/slug-history?page=${page}`),
        placeholderData: keepPreviousData,
    });
}

export function useSaveRedirect() {
    const client = useQueryClient();
    return useMutation({
        mutationFn: ({ id, input }: { id: number | null; input: Partial<RedirectInput> }) =>
            id === null
                ? api<Redirect>('/seo/redirects', { method: 'POST', body: JSON.stringify(input) })
                : api<Redirect>(`/seo/redirects/${id}`, { method: 'PATCH', body: JSON.stringify(input) }),
        onSuccess: () => void client.invalidateQueries({ queryKey: seoKeys.redirects }),
    });
}

export function useDeleteRedirect() {
    const client = useQueryClient();
    return useMutation({
        mutationFn: (id: number) => api<void>(`/seo/redirects/${id}`, { method: 'DELETE' }),
        onSuccess: () => void client.invalidateQueries({ queryKey: seoKeys.redirects }),
    });
}

/** Multipart CSV import. A 422 with `errors[]` of rows is a result, not a failure. */
export function useImportRedirects() {
    const client = useQueryClient();
    return useMutation({
        mutationFn: async (file: File): Promise<ImportResult> => {
            const body = new FormData();
            body.append('file', file);
            const response = await fetch('/api/v1/admin/seo/redirects/import', {
                method: 'POST',
                body,
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'Accept-Language': apiLocale(), 'X-XSRF-TOKEN': csrfToken() },
            });
            const payload: unknown = await response.json().catch(() => null);
            if (isImportResult(payload)) return payload;
            throw new ApiError(response.status, isProblem(payload) ? payload : { type: 'about:blank', title: response.statusText || 'Request failed', status: response.status });
        },
        onSuccess: (result) => {
            if (result.errors.length === 0) void client.invalidateQueries({ queryKey: seoKeys.redirects });
        },
    });
}

function isImportResult(value: unknown): value is ImportResult {
    return typeof value === 'object' && value !== null && 'created' in value && 'errors' in value && Array.isArray((value as { errors: unknown }).errors);
}

export function useSitemapStatus() {
    return useQuery({ queryKey: seoKeys.sitemap, queryFn: () => api<SitemapStatus>('/seo/sitemap') });
}

export function useRegenerateSitemap() {
    return useMutation({ mutationFn: () => api<{ queued: boolean }>('/seo/sitemap/regenerate', { method: 'POST' }) });
}

export function useRobots() {
    return useQuery({ queryKey: seoKeys.robots, queryFn: () => api<RobotsTxt>('/seo/robots') });
}

export function useSaveRobots() {
    const client = useQueryClient();
    return useMutation({
        mutationFn: (content: string) => api<RobotsTxt>('/seo/robots', { method: 'PUT', body: JSON.stringify({ content }) }),
        onSuccess: (robots) => client.setQueryData(seoKeys.robots, robots),
    });
}

/** Every 422 message for one field (robots.txt reports one per bad line). */
export function fieldErrors(error: unknown, field: string): string[] {
    if (!(error instanceof ApiError) || error.status !== 422) return [];
    return (error.problem.errors ?? []).filter((e) => e.field === field || e.field.startsWith(`${field}.`)).map((e) => e.message);
}
