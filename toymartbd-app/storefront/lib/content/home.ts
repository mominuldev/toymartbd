import 'server-only';

import { cached, noStore } from '@/lib/api';
import type { Locale } from '@/lib/i18n';

import { normalizeHome, type HomeLayout } from './home-blocks';

// Fetching the CMS homepage (Phase 7). The published layout is ISR-cached under `content:home`
// (sent on publish/revert and at every schedule boundary) plus the tags of the data the blocks
// show: `products` (rails), `categories` (tiles), `brands` (strip), `content:testimonials`,
// `content:blog`, `flash-sale` (docs/api/revalidation.md). The draft is never cached.

export const HOME_TAGS = ['content:home', 'products', 'categories', 'brands', 'content:testimonials', 'content:blog', 'flash-sale'] as const;

/** The live homepage, or null when the API cannot answer — the page then shows its built-in home. */
export async function fetchHome(locale: Locale): Promise<HomeLayout | null> {
    try {
        return normalizeHome(await cached<unknown>(`storefront/content/home?locale=${locale}`, [...HOME_TAGS], { locale }));
    } catch (error) {
        console.error('[home] CMS layout unavailable:', error instanceof Error ? error.message : error);
        return null;
    }
}

/** The draft behind a preview token, or null when the API refuses the token (expired, forged). */
export async function fetchHomePreview(locale: Locale, token: string): Promise<HomeLayout | null> {
    try {
        return normalizeHome(await noStore<unknown>(`storefront/content/home/preview?locale=${locale}`, { locale, headers: { 'X-Preview-Token': token } }));
    } catch {
        return null;
    }
}
