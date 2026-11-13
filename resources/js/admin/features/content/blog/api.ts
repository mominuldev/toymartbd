import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import type { Schema } from '@toymart/api-types';
import type { BlogPostRequestInput, BlogTermRequestInput } from '@toymart/api-types/zod';
import { api } from '@/lib/api';

export type BlogPost = Schema<'AdminBlogPost'>;
export type BlogPostRow = Schema<'AdminBlogPostRow'>;
export type BlogTerm = Schema<'AdminBlogTerm'>;
export type BlogPostInput = BlogPostRequestInput;
export type BlogTermInput = BlogTermRequestInput;
export type PostStatus = BlogPost['status'];
export type PostType = BlogPost['type'];
export type TermKind = 'categories' | 'tags' | 'authors';
export type StatusFilter = 'all' | PostStatus;
export type TypeFilter = 'all' | PostType;

export const postStatuses = ['draft', 'scheduled', 'published'] as const satisfies readonly PostStatus[];
export const postTypes = ['article', 'buying_guide'] as const satisfies readonly PostType[];
export const ageBands = ['0-12', '12-24', '24-48', '48-72', '72-96', '96-144', '144-'] as const;
export type AgeBand = (typeof ageBands)[number];

type Paged<T> = { data: T[]; meta: { current_page: number; last_page: number; total: number } };

export const blogKeys = {
    all: ['blog'] as const,
    posts: (search: string, status: StatusFilter, type: TypeFilter, page: number) => ['blog', 'posts', { search, status, type, page }] as const,
    post: (id: number) => ['blog', 'post', id] as const,
    terms: (kind: TermKind) => ['blog', 'terms', kind] as const,
};

export function postsPath(search: string, status: StatusFilter, type: TypeFilter, page: number): string {
    const params = new URLSearchParams();
    if (search) params.set('filter[search]', search);
    if (status !== 'all') params.set('filter[status]', status);
    if (type !== 'all') params.set('filter[type]', type);
    if (page > 1) params.set('page', String(page));
    const query = params.toString();
    return `/blog/posts${query ? `?${query}` : ''}`;
}

export function usePosts(search: string, status: StatusFilter, type: TypeFilter, page: number) {
    return useQuery({
        queryKey: blogKeys.posts(search, status, type, page),
        queryFn: () => api<Paged<BlogPostRow>>(postsPath(search, status, type, page)),
        placeholderData: keepPreviousData,
    });
}

export function usePost(id: number | null) {
    return useQuery({ queryKey: blogKeys.post(id ?? 0), queryFn: () => api<BlogPost>(`/blog/posts/${id}`), enabled: id !== null });
}

export function useSavePost(id: number | null) {
    const client = useQueryClient();
    return useMutation({
        mutationFn: (input: BlogPostInput) =>
            id === null
                ? api<BlogPost>('/blog/posts', { method: 'POST', body: JSON.stringify(input) })
                : api<BlogPost>(`/blog/posts/${id}`, { method: 'PUT', body: JSON.stringify(input) }),
        onSuccess: (post) => {
            client.setQueryData(blogKeys.post(post.id), post);
            void client.invalidateQueries({ queryKey: [...blogKeys.all, 'posts'] });
            void client.invalidateQueries({ queryKey: [...blogKeys.all, 'terms'] });
        },
    });
}

export function useDeletePost(id: number) {
    const client = useQueryClient();
    return useMutation({
        mutationFn: () => api<void>(`/blog/posts/${id}`, { method: 'DELETE' }),
        onSuccess: () => {
            client.removeQueries({ queryKey: blogKeys.post(id) });
            void client.invalidateQueries({ queryKey: [...blogKeys.all, 'posts'] });
        },
    });
}

export type PreviewLink = { token: string; path: string; path_bn: string; url: string; url_bn: string; expires_in: number };

export function usePreviewLink(id: number | null) {
    return useMutation({ mutationFn: () => api<PreviewLink>(`/blog/posts/${id}/preview-link`, { method: 'POST' }) });
}

export function useTerms(kind: TermKind) {
    return useQuery({ queryKey: blogKeys.terms(kind), queryFn: () => api<{ data: BlogTerm[] }>(`/blog/${kind}`).then((r) => r.data), staleTime: 60_000 });
}

export function useSaveTerm(kind: TermKind) {
    const client = useQueryClient();
    return useMutation({
        mutationFn: ({ id, input }: { id: number | null; input: BlogTermInput }) =>
            id === null
                ? api<BlogTerm>(`/blog/${kind}`, { method: 'POST', body: JSON.stringify(input) })
                : api<BlogTerm>(`/blog/${kind}/${id}`, { method: 'PATCH', body: JSON.stringify(input) }),
        onSuccess: () => void client.invalidateQueries({ queryKey: blogKeys.terms(kind) }),
    });
}

export function useDeleteTerm(kind: TermKind) {
    const client = useQueryClient();
    return useMutation({
        mutationFn: (id: number) => api<void>(`/blog/${kind}/${id}`, { method: 'DELETE' }),
        onSuccess: () => void client.invalidateQueries({ queryKey: blogKeys.terms(kind) }),
    });
}
