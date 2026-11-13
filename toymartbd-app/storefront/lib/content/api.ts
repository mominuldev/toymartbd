import 'server-only';

import { cache } from 'react';

import { ApiError, cached } from '../api';
import type { Locale } from '../i18n/config';
import { normaliseMenus, EMPTY_MENUS, PAGE_SLUG, type Banner, type BannerContext, type BannerPlacement, type CmsMenus, type CmsPage, type CmsPageSummary, type Testimonial } from './types';

// Server-only reads of CMS content (CLAUDE.md rule 28), ISR-cached by tag (docs/api/revalidation.md):
// `content:pages`, `content:page:{slug}`, `content:menus`, `content:banners`, `content:testimonials`.
// Laravel purges them on every admin save and when a schedule boundary passes. The chrome
// (menus, banners) never throws: a content failure costs the content, never the page.

export const contentTags = {
    pages: 'content:pages',
    page: (slug: string) => `content:page:${slug}`,
    menus: 'content:menus',
    banners: 'content:banners',
    testimonials: 'content:testimonials',
} as const;

/** Banners carry schedules: a short ceiling as a safety net under the scheduled purge. */
export const BANNER_REVALIDATE_SECONDS = 300;

/** One live page, or null when the API answers 404 (draft, scheduled, archived, unknown). */
export const getPage = cache(async (slug: string): Promise<CmsPage | null> => {
    if (!PAGE_SLUG.test(slug)) return null;
    try {
        const body = await cached<CmsPage | { data: CmsPage }>(`storefront/pages/${encodeURIComponent(slug)}`, [contentTags.pages, contentTags.page(slug)]);
        return 'data' in body ? body.data : body;
    } catch (error) {
        if (error instanceof ApiError && error.isNotFound) return null;
        throw error;
    }
});

/** Same, but never throws (the About page falls back to its built-in copy). */
export async function findPage(slug: string): Promise<CmsPage | null> {
    try {
        return await getPage(slug);
    } catch (error) {
        console.error(`[content] page ${slug} unavailable:`, error instanceof Error ? error.message : error);
        return null;
    }
}

/** Every live page (sidebar template, sitemaps). Never throws. */
export const listPages = cache(async (): Promise<CmsPageSummary[]> => {
    try {
        return (await cached<{ data: CmsPageSummary[] }>('storefront/pages', [contentTags.pages])).data;
    } catch (error) {
        console.error('[content] page list unavailable:', error instanceof Error ? error.message : error);
        return [];
    }
});

/** Published menus by location; every location null (→ fallback menus) when the API fails. */
export const getMenus = cache(async (): Promise<CmsMenus> => {
    try {
        return normaliseMenus(await cached<unknown>('storefront/menus', [contentTags.menus]));
    } catch (error) {
        console.error('[content] menus unavailable; using the fallback menus:', error instanceof Error ? error.message : error);
        return EMPTY_MENUS;
    }
});

/** Live banners for one slot, highest priority first. Never throws (no banner on failure). */
export const getBanners = cache(async (placement: BannerPlacement, context: BannerContext = 'general', category?: string): Promise<Banner[]> => {
    const query = new URLSearchParams({ placement, context });
    if (category) query.set('category', category);
    try {
        const body = await cached<{ data: Banner[] }>(`storefront/banners?${query.toString()}`, [contentTags.banners], { revalidate: BANNER_REVALIDATE_SECONDS });
        return Array.isArray(body.data) ? body.data : [];
    } catch (error) {
        console.error(`[content] ${placement} banners unavailable:`, error instanceof Error ? error.message : error);
        return [];
    }
});

/**
 * Published testimonials in display order (the homepage testimonials block). Never throws.
 * Both locales come back in one body, so the locale argument only keeps the call sites uniform.
 */
export const getTestimonials = cache(async (_locale?: Locale, limit = 12): Promise<Testimonial[]> => {
    try {
        const body = await cached<{ data: Testimonial[] }>(`storefront/testimonials?limit=${Math.max(1, Math.min(24, Math.floor(limit)))}`, [contentTags.testimonials]);
        return Array.isArray(body.data) ? body.data : [];
    } catch (error) {
        console.error('[content] testimonials unavailable:', error instanceof Error ? error.message : error);
        return [];
    }
});
