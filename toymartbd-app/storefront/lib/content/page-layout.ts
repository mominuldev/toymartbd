import 'server-only';

import { cookies, draftMode } from 'next/headers';
import { cache } from 'react';

import { ApiError, cached, noStore } from '@/lib/api';
import type { Locale } from '@/lib/i18n';

import { getPage } from './api';
import { BLOCK_DATA_TAGS } from './home';
import { legacyLayout, normalizePageLayout, type PageLayout } from './page-blocks';
import { PAGE_PREVIEW_COOKIE } from './preview';
import { PAGE_SLUG } from './types';

// Fetching a static page built from sections (owner decision 2026-11-16). The live layout is
// ISR-cached under the page's tags — `content:pages` + `content:page:{slug}`, which Laravel sends on
// a page save and on a sections publish — plus the tags of the data the blocks show, exactly as
// the homepage's (./home BLOCK_DATA_TAGS). The draft (preview) is never cached.

export const pageLayoutTags = (slug: string): string[] => ['content:pages', `content:page:${slug}`, ...BLOCK_DATA_TAGS];

/**
 * The live page and its sections, or null when the page is not live (404). Any other failure
 * falls back to the page document alone (GET /storefront/pages/{slug}, rendered on the legacy
 * template), so a sections outage costs the sections, not the page.
 */
export const getPageLayout = cache(async (slug: string, locale: Locale): Promise<PageLayout | null> => {
    if (!PAGE_SLUG.test(slug)) return null;
    try {
        const layout = normalizePageLayout(await cached<unknown>(`storefront/content/pages/${encodeURIComponent(slug)}?locale=${locale}`, pageLayoutTags(slug), { locale }));
        if (layout) return layout;
        console.error(`[content] page ${slug} layout is malformed; using the page body.`);
    } catch (error) {
        if (error instanceof ApiError && error.isNotFound) return null;
        console.error(`[content] page ${slug} layout unavailable; using the page body:`, error instanceof Error ? error.message : error);
    }
    const page = await getPage(slug);
    return page ? legacyLayout(page) : null;
});

/** Same, but never throws (the About page falls back to its built-in copy). */
export async function findPageLayout(slug: string, locale: Locale): Promise<PageLayout | null> {
    try {
        return await getPageLayout(slug, locale);
    } catch (error) {
        console.error(`[content] page ${slug} unavailable:`, error instanceof Error ? error.message : error);
        return null;
    }
}

/** The draft behind a page preview token, or null when the API refuses the token (expired, forged). */
export async function fetchPagePreview(locale: Locale, token: string): Promise<PageLayout | null> {
    try {
        const layout = normalizePageLayout(await noStore<unknown>(`storefront/content/pages/preview?locale=${locale}`, { locale, headers: { 'X-Preview-Token': token } }));
        return layout ? { ...layout, preview: true } : null;
    } catch {
        return null;
    }
}

/**
 * In Next.js draft mode (opened by /api/preview/page), the draft of the page the preview cookie's
 * token names — when that is this page. Otherwise null, and the cookie is never read, so the live
 * page stays statically cached. The draft renders whatever the page's status (a page being built
 * before it goes live).
 */
export const draftPageLayout = cache(async (slug: string, locale: Locale): Promise<PageLayout | null> => {
    if (!(await draftMode()).isEnabled) return null;
    const token = (await cookies()).get(PAGE_PREVIEW_COOKIE)?.value;
    const draft = token ? await fetchPagePreview(locale, token) : null;
    return draft && draft.page.slug === slug ? draft : null;
});
