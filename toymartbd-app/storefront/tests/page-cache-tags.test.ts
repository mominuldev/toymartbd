import { beforeEach, describe, expect, it, vi } from 'vitest';

import { getPage } from '@/lib/content/api';
import { getSeo } from '@/lib/seo/fetch';

// A static page is two cached fetches: its body (GET /storefront/pages/{slug}) and its SEO
// document (GET /storefront/seo/page/{slug}). Laravel's purge on a page save and on a page
// SEO-panel save must reach BOTH, or one of body / <head> outlives the write. The purge sets
// below mirror docs/api/revalidation.md and are pinned server-side by
// tests/Feature/Content/StoreContent/PagesTest.php (StoreContentTags::pageAll).

const fetchMock = vi.fn<typeof fetch>();

vi.mock('next/headers', () => ({
    headers: async () => new Headers(),
}));

const pageSavePurges = (slug: string): string[] => ['content:pages', `content:page:${slug}`, `page:${slug}`, 'content:menus'];
const pageSeoSavePurges = (slug: string): string[] => ['seo', `page:${slug}`, 'content:pages', `content:page:${slug}`];

function tagsOfCall(i: number): string[] {
    return (fetchMock.mock.calls[i]![1] as { next: { tags: string[] } }).next.tags;
}

describe('static page cache tags', () => {
    beforeEach(() => {
        fetchMock.mockReset();
        vi.stubGlobal('fetch', fetchMock);
        vi.stubEnv('API_URL', 'https://api.test/');
        vi.stubEnv('STOREFRONT_API_TOKEN', 'tok_123');
    });

    it('tags the body and the SEO document so both purges reach both fetches', async () => {
        fetchMock.mockResolvedValue(new Response(JSON.stringify({}), { status: 200, headers: { 'Content-Type': 'application/json' } }));

        await getPage('privacy').catch(() => null);
        await getSeo('page', 'privacy');

        const bodyTags = tagsOfCall(0);
        const seoTags = tagsOfCall(1);
        expect(bodyTags).toEqual(['content:pages', 'content:page:privacy']);
        expect(seoTags).toEqual(['seo', 'page:privacy']);

        for (const purge of [pageSavePurges('privacy'), pageSeoSavePurges('privacy')]) {
            // Every fetch must carry the page-specific tag the purge sends.
            expect(bodyTags.filter((t) => purge.includes(t) && t.endsWith(':privacy'))).not.toEqual([]);
            expect(seoTags.filter((t) => purge.includes(t) && t.endsWith(':privacy'))).not.toEqual([]);
        }
    });
});
