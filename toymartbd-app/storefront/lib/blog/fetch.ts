import 'server-only';

import { ApiError, cached, noStore } from '../api';
import type { Locale } from '../i18n/config';
import type { BlogCard, BlogPage, BlogPost, BlogTerm, BlogTermKind } from './types';

// Blog reads (Phase 7) from Server Components only (rule 28). ISR with tags (docs/api/revalidation.md):
// `content:blog` on every list, taxonomy page and the teaser; a post page adds `post:{slug}` (its own
// tag — Laravel also sends `content:post:{id}`) and `products`, because its guide picks and related
// products are product cards whose price and stock must follow the catalogue. Preview is uncached.

export const BLOG_TAG = 'content:blog';
export const PER_PAGE = 12;
const SLUG = /^[a-z0-9]+(?:-[a-z0-9]+)*$/;

export const blogPostTags = (slug: string): string[] => [BLOG_TAG, `post:${slug}`, 'products'];

interface Paginated<T> {
    data: T[];
    meta: { current_page: number; last_page: number; total: number };
}

export interface ListQuery {
    page: number;
    category?: string;
    tag?: string;
    author?: string;
    type?: 'article' | 'buying_guide';
}

/** One page of live posts, newest first. Null when the page number is past the end (→ 404). */
export async function listPosts(query: ListQuery, locale: Locale): Promise<BlogPage | null> {
    const params = new URLSearchParams({ page: String(query.page), per_page: String(PER_PAGE) });
    for (const key of ['category', 'tag', 'author', 'type'] as const) {
        const value = query[key];
        if (value) params.set(`filter[${key}]`, value);
    }
    const body = await cached<Paginated<BlogCard>>(`storefront/blog/posts?${params.toString()}`, [BLOG_TAG], { locale });
    const lastPage = Math.max(1, body.meta.last_page);
    if (query.page > lastPage) return null;
    return { posts: body.data, page: body.meta.current_page, lastPage, total: body.meta.total };
}

/** The home-page blog block (agent 1's homepage): featured first, then newest. Never throws. */
export async function getBlogTeaser(locale: Locale, limit = 3): Promise<BlogCard[]> {
    try {
        const body = await cached<{ data: BlogCard[] }>(`storefront/blog/teaser?limit=${Math.min(6, Math.max(1, limit))}`, [BLOG_TAG], { locale });
        return body.data;
    } catch {
        return [];
    }
}

/** One live post or guide, or null on 404. */
export async function getPost(slug: string, locale: Locale): Promise<BlogPost | null> {
    if (!SLUG.test(slug) || slug.length > 94) return null;
    try {
        return await cached<BlogPost>(`storefront/blog/posts/${encodeURIComponent(slug)}`, blogPostTags(slug), { locale });
    } catch (error) {
        if (error instanceof ApiError && error.isNotFound) return null;
        throw error;
    }
}

/** Any post by a signed preview token from the admin — never cached. Null when invalid or expired. */
export async function getPreview(token: string, locale: Locale): Promise<BlogPost | null> {
    if (!/^[a-z_]{1,32}\.\d{1,12}\.\d{1,12}\.[a-f0-9]{64}$/.test(token)) return null;
    try {
        return await noStore<BlogPost>(`storefront/blog/preview/${encodeURIComponent(token)}`, { locale });
    } catch (error) {
        if (error instanceof ApiError && error.isNotFound) return null;
        throw error;
    }
}

const termSegment: Record<BlogTermKind, string> = { category: 'categories', tag: 'tags', author: 'authors' };

/** A category, tag or author, or null on 404. */
export async function getTerm(kind: BlogTermKind, slug: string, locale: Locale): Promise<BlogTerm | null> {
    if (!SLUG.test(slug) || slug.length > 94) return null;
    try {
        return await cached<BlogTerm>(`storefront/blog/${termSegment[kind]}/${encodeURIComponent(slug)}`, [BLOG_TAG], { locale });
    } catch (error) {
        if (error instanceof ApiError && error.isNotFound) return null;
        throw error;
    }
}

/** Categories with at least one live post, for the blog index chips. Never throws. */
export async function listBlogCategories(locale: Locale): Promise<BlogTerm[]> {
    try {
        return (await cached<{ data: BlogTerm[] }>('storefront/blog/categories', [BLOG_TAG], { locale })).data;
    } catch {
        return [];
    }
}
