import type { Schema } from '@toymart/api-types';

// Blog contract types (Phase 7), generated from the OpenAPI document (rule 27). Client-safe.

export type BlogCard = Schema<'StorefrontBlogPostCard'>;
export type BlogPost = Schema<'StorefrontBlogPost'>;
export type BlogTerm = Schema<'StorefrontBlogTerm'>;
export type BlogTermKind = 'category' | 'tag' | 'author';

export interface BlogPage {
    posts: BlogCard[];
    page: number;
    lastPage: number;
    total: number;
}

/** Locale-independent path of a post: guides live under /guides, articles under /blog. */
export function postPath(post: Pick<BlogCard, 'slug' | 'type'>): string {
    return `${post.type === 'buying_guide' ? '/guides' : '/blog'}/${post.slug}`;
}

export function termPath(kind: BlogTermKind, slug: string): string {
    return `/blog/${kind}/${slug}`;
}

/** `related_posts` items are blog cards; the contract types them loosely, so check before use. */
export function isBlogCard(value: unknown): value is BlogCard {
    if (typeof value !== 'object' || value === null) return false;
    const v = value as Partial<BlogCard>;
    return typeof v.id === 'number' && typeof v.slug === 'string' && typeof v.title === 'string' && typeof v.reading_minutes === 'number';
}
