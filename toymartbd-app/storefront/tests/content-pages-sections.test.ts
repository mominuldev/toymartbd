import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { HomeBlocks } from '@/components/content/HomeBlocks';
import { normalizeHome } from '@/lib/content/home-blocks';
import { legacyLayout, normalizePageLayout, usesSections } from '@/lib/content/page-blocks';
import { getPageLayout, pageLayoutTags } from '@/lib/content/page-layout';
import { PAGE_PREVIEW_COOKIE, previewTokenLifetime } from '@/lib/content/preview';
import type { CmsPage } from '@/lib/content/types';

// Static pages built from sections (owner decision 2026-11-16): the layout guard, the legacy
// fallback, the cache tags, the page preview token and the block rendering a page shares with Home.

vi.mock('@/public/images/home/hero.jpg', () => ({ default: { src: '/_next/static/media/hero.jpg', width: 1216, height: 800, blurDataURL: '' } }));
vi.mock('next/headers', () => ({ headers: async () => new Headers(), cookies: async () => ({ get: () => undefined }), draftMode: async () => ({ isEnabled: false }) }));

const page: CmsPage = {
    slug: 'privacy-policy',
    title: 'Privacy policy',
    title_bn: 'গোপনীয়তা নীতি',
    template: 'default',
    content: '<p>Legacy.</p>',
    content_bn: null,
    faq_items: [],
    published_at: '2026-11-01T00:00:00Z',
    updated_at: '2026-11-05T00:00:00Z',
    seo: { title: null, title_bn: null, description: null, description_bn: null, canonical_url: null, robots: 'index,follow', og_title: null, og_description: null, og_image: null, twitter_card: 'summary_large_image' },
} as unknown as CmsPage;

const section = (id: string, type: string, content: Record<string, unknown>) => ({ id, type, show_mobile: true, show_desktop: true, content });
const body = {
    page,
    version: 4,
    published_at: '2026-11-16T00:00:00Z',
    preview: false,
    blocks: [
        section('t', 'rich_text', { html: '<h2>What we collect</h2><p>Name and phone.</p>' }),
        section('f1', 'faq', { title: 'FAQ', items: [{ question: 'COD?', answer: 'Yes.' }] }),
        section('f2', 'faq', { title: 'More', items: [{ question: 'Returns?', answer: 'Within 7 days.' }] }),
        { type: 'nope', content: {} },
    ],
};

describe('normalizePageLayout', () => {
    it('keeps the page document and normalises the blocks like the homepage', () => {
        const layout = normalizePageLayout(body)!;
        expect(layout.page.slug).toBe('privacy-policy');
        expect(layout.version).toBe(4);
        expect(layout.blocks.map((b) => b.type)).toEqual(['rich_text', 'faq', 'faq']);
        expect(usesSections(layout)).toBe(true);
    });

    it('unwraps a `data` envelope on the body or on the page', () => {
        expect(normalizePageLayout({ data: body })?.page.title).toBe('Privacy policy');
        expect(normalizePageLayout({ ...body, page: { data: page } })?.page.title).toBe('Privacy policy');
    });

    it('rejects a body without a usable page document', () => {
        expect(normalizePageLayout(null)).toBeNull();
        expect(normalizePageLayout({ ...body, page: { ...page, slug: '../x' } })).toBeNull();
        expect(normalizePageLayout({ blocks: [] })).toBeNull();
    });

    it('renders the legacy body when the page was never published from the builder', () => {
        const never = normalizePageLayout({ page, version: null, preview: false, blocks: [] })!;
        expect(usesSections(never)).toBe(false);
        expect(usesSections({ version: null, preview: true })).toBe(true); // a draft always shows its sections
        expect(usesSections(legacyLayout(page))).toBe(false);
    });
});

describe('getPageLayout', () => {
    const fetchMock = vi.fn<typeof fetch>();
    const json = (status: number, payload: unknown) => new Response(JSON.stringify(payload), { status, headers: { 'Content-Type': status >= 400 ? 'application/problem+json' : 'application/json' } });

    beforeEach(() => {
        fetchMock.mockReset();
        vi.stubGlobal('fetch', fetchMock);
        vi.stubEnv('API_URL', 'https://api.test');
        vi.stubEnv('STOREFRONT_API_TOKEN', 'tok_123');
    });

    it('caches under the page tags plus the data tags the sections read', async () => {
        fetchMock.mockResolvedValue(json(200, body));
        const layout = await getPageLayout('privacy-policy', 'bn');
        expect(layout?.blocks).toHaveLength(3);
        const [url, init] = fetchMock.mock.calls[0]!;
        expect(String(url)).toBe('https://api.test/api/v1/storefront/content/pages/privacy-policy?locale=bn');
        const tags = (init as { next: { tags: string[] } }).next.tags;
        expect(tags).toEqual(pageLayoutTags('privacy-policy'));
        for (const tag of ['content:pages', 'content:page:privacy-policy', 'products', 'categories', 'brands', 'content:testimonials', 'content:blog', 'flash-sale']) expect(tags).toContain(tag);
    });

    it('is null for a page that is not live', async () => {
        fetchMock.mockResolvedValue(json(404, { type: 'about:blank', title: 'Not Found', status: 404 }));
        expect(await getPageLayout('terms', 'en')).toBeNull();
    });

    it('falls back to the page document when the sections endpoint fails', async () => {
        fetchMock.mockResolvedValueOnce(json(500, { type: 'about:blank', title: 'Server Error', status: 500 })).mockResolvedValueOnce(json(200, page));
        vi.spyOn(console, 'error').mockImplementation(() => undefined);
        const layout = await getPageLayout('refund-policy', 'en');
        expect(layout?.page.title).toBe('Privacy policy');
        expect(layout && usesSections(layout)).toBe(false);
        expect(String(fetchMock.mock.calls[1]![0])).toContain('/storefront/pages/refund-policy');
    });
});

describe('page preview token pre-check', () => {
    const sig = 'b'.repeat(64);
    it('accepts only a well-formed, unexpired page token on the page preview', () => {
        expect(previewTokenLifetime(`page_draft.9.1000.${sig}`, 400, 'page_draft')).toBe(600);
        expect(previewTokenLifetime(`home_draft.9.1000.${sig}`, 400, 'page_draft')).toBeNull(); // the homepage's token
        expect(previewTokenLifetime(`page_draft.9.1000.${sig}`, 400)).toBeNull(); // not on the homepage preview
        expect(previewTokenLifetime(`page_draft.9.1000.${sig}`, 1000, 'page_draft')).toBeNull(); // expired
        expect(PAGE_PREVIEW_COOKIE).toBe('tm_page_preview');
    });
});

describe('sections on a static page', () => {
    const blocks = normalizeHome({
        blocks: [
            ...body.blocks.slice(0, 3),
            section('h', 'hero', { show_age_pills: false, slides: [{ overline: null, headline: 'Our story', sub: null, cta_label: null, cta_href: null, image: null }] }),
        ],
    }).blocks;

    it('emits one FAQPage for every FAQ section, never one per section', () => {
        const html = renderToStaticMarkup(createElement(HomeBlocks, { blocks, locale: 'en', hotline: '09666-700800' }));
        expect(html.match(/"@type":"FAQPage"/g)).toHaveLength(1);
        expect(html).toContain('COD?');
        expect(html).toContain('Returns?');
    });

    it('drops a hero headline to h2 so the page title stays the only H1', () => {
        const onPage = renderToStaticMarkup(createElement(HomeBlocks, { blocks, locale: 'en', hotline: '', pageHeading: false }));
        expect(onPage).not.toContain('<h1');
        expect(onPage).toContain('Our story</h2>');
        const onHome = renderToStaticMarkup(createElement(HomeBlocks, { blocks, locale: 'en', hotline: '' }));
        expect(onHome).toContain('Our story</h1>');
    });
});
