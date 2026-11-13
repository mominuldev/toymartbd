import { cleanup, fireEvent, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { mockApi, renderApp, viewer } from '@/test/renderApp';
import type { Me } from '@/features/auth/api';
import type { BlogPost, BlogPostRow, BlogTerm } from './api';
import { toPostForm, toPostPayload } from './form';

afterEach(() => {
    cleanup();
    localStorage.clear();
});

const editor: Me = { ...viewer, roles: ['content_editor'], permissions: ['dashboard.view', 'content.view', 'content.manage', 'catalog.view'] };
const reader: Me = { ...viewer, roles: ['viewer'], permissions: ['dashboard.view', 'content.view'] };

const category: BlogTerm = { id: 3, name: 'Buying guides', name_bn: 'কেনার গাইড', slug: 'buying-guides', description: null, description_bn: null, position: 0, user_id: null, avatar: null, posts_count: 2 };
const author: BlogTerm = { id: 5, name: 'Mominul Islam', name_bn: null, slug: 'mominul', description: null, description_bn: null, position: null, user_id: null, avatar: null, posts_count: 1 };
const tag: BlogTerm = { id: 9, name: 'educational', name_bn: 'শিক্ষামূলক', slug: 'educational', description: null, description_bn: null, position: null, user_id: null, avatar: null, posts_count: 4 };

const row: BlogPostRow = {
    id: 12,
    type: 'buying_guide',
    slug: 'best-educational-toys-3-5-years',
    title: '10 best educational toys for 3–5 year olds',
    title_bn: null,
    status: 'scheduled',
    is_live: false,
    is_featured: true,
    published_at: '2026-11-30T18:00:00+00:00',
    published_at_dhaka: '2026-12-01 00:00',
    reading_minutes: 6,
    category: { id: 3, name: 'Buying guides', name_bn: 'কেনার গাইড' },
    author: null,
    cover: null,
    updated_at: '2026-11-13T08:20:00+00:00',
};

const post: BlogPost = {
    id: 12,
    type: 'buying_guide',
    slug: 'best-educational-toys-3-5-years',
    title: '10 best educational toys for 3–5 year olds',
    title_bn: '৩–৫ বছরের সেরা শিক্ষামূলক খেলনা',
    excerpt: 'What Dhaka parents told us.',
    excerpt_bn: null,
    body: '<p>Choosing a toy is hard.</p>',
    body_bn: '<p>খেলনা বাছাই কঠিন।</p>',
    cover: { media_id: 4, url: 'https://cdn.test/m/4/800.webp', srcset_webp: null, srcset_avif: null, alt: 'Puzzle', alt_bn: null, width: 800, height: 450 },
    author_id: 5,
    author: { id: 5, name: 'Mominul Islam', name_bn: null },
    category_id: 3,
    category: { id: 3, name: 'Buying guides', name_bn: 'কেনার গাইড' },
    tags: [{ id: 9, name: 'educational', name_bn: 'শিক্ষামূলক', slug: 'educational' }],
    status: 'draft',
    is_live: false,
    published_at: null,
    published_at_dhaka: null,
    is_featured: false,
    reading_minutes: 6,
    reading_minutes_bn: 7,
    guide_age_band: '24-48',
    guide_budget_min: null,
    guide_budget_max: '1500.00',
    sections: [
        {
            id: 1,
            heading: 'Under ৳1,000',
            heading_bn: null,
            body: '<p>Small budgets.</p>',
            body_bn: null,
            age_band: null,
            budget_min: null,
            budget_max: '1000.00',
            picks: [{ product_id: 31, name: 'Wooden Alphabet Puzzle', name_bn: null, slug: 'wooden-puzzle', note: 'Thick pieces', note_bn: null, visible: true }],
        },
    ],
    related_products: [{ product_id: 32, name: 'Sold-out Blocks', name_bn: null, slug: 'blocks', note: null, note_bn: null, visible: false }],
    seo: { title: null, title_bn: null, description: null, description_bn: null, canonical_url: null, robots: 'index,follow', og_image: null, focus_keyword: null },
    checklist: { has_bangla: true, cover_alt: true, cover_alt_bn: false, product_links: 2 },
    updated_at: '2026-11-13T08:20:00+00:00',
};

function terms(url: string) {
    if (url.endsWith('/blog/categories')) return { status: 200, body: { data: [category] } };
    if (url.endsWith('/blog/authors')) return { status: 200, body: { data: [author] } };
    if (url.endsWith('/blog/tags')) return { status: 200, body: { data: [tag] } };
    if (url.includes('/products/seo-measure')) return { status: 200, body: { title: { px: 548, chars: 50, max: 580, fits: true }, description: { px: 100, chars: 20, max: 920, fits: true, fits_mobile: true } } };
    return null;
}

describe('blog form mapping', () => {
    it('sends the schedule as Dhaka time with +06:00 and drops guide sections from an article', () => {
        const form = { ...toPostForm(post), publish_at: '2026-12-01T00:00' };
        const scheduled = toPostPayload(form, 'scheduled');
        expect(scheduled.publish_at).toBe('2026-12-01T00:00:00+06:00');
        expect(scheduled.sections?.[0]?.picks?.[0]).toEqual({ product_id: 31, note: 'Thick pieces', note_bn: null });
        expect(scheduled.guide_budget_max).toBe('1500.00');
        expect(toPostPayload(form, 'draft').publish_at).toBeNull();

        const article = toPostPayload({ ...form, type: 'article' }, 'published');
        expect(article.sections).toEqual([]);
        expect(article.guide_age_band).toBeNull();
        expect(article.related_product_ids).toEqual([32]);
    });
});

describe('blog posts list', () => {
    it('lists posts with status chips and Dhaka dates, and filters by status', { timeout: 20_000 }, async () => {
        const calls = mockApi((url) => {
            if (url.endsWith('/auth/me')) return { status: 200, body: editor };
            if (url.includes('/blog/posts')) return { status: 200, body: { data: [row], meta: { current_page: 1, last_page: 1, total: 1 } } };
            return { status: 404 };
        });

        renderApp('/store/blog');

        expect(await screen.findByRole('link', { name: row.title }, { timeout: 5000 })).toBeTruthy();
        expect(within(screen.getByRole('table')).getByText('Scheduled')).toBeTruthy();
        expect(screen.getByText(/1 Dec 2026, 00:00/)).toBeTruthy();
        expect(screen.getByRole('link', { name: 'New post' })).toBeTruthy();

        fireEvent.click(screen.getByRole('button', { name: 'Draft' }));
        await waitFor(() => expect(calls.mock.calls.some(([url]) => String(url).includes('filter%5Bstatus%5D=draft'))).toBe(true));
    });

    it('hides write actions from a read-only role', { timeout: 20_000 }, async () => {
        mockApi((url) => {
            if (url.endsWith('/auth/me')) return { status: 200, body: reader };
            if (url.includes('/blog/posts')) return { status: 200, body: { data: [row], meta: { current_page: 1, last_page: 1, total: 1 } } };
            return { status: 404 };
        });

        renderApp('/store/blog');
        await screen.findByRole('link', { name: row.title }, { timeout: 5000 });
        expect(screen.queryByRole('link', { name: 'New post' })).toBeNull();
    });
});

describe('blog post editor', () => {
    it('shows the Figma editor: settings, language state, meta title measure and the SEO checks', { timeout: 20_000 }, async () => {
        mockApi((url) => {
            if (url.endsWith('/auth/me')) return { status: 200, body: editor };
            if (url.endsWith('/blog/posts/12')) return { status: 200, body: post };
            return terms(url) ?? { status: 404 };
        });

        renderApp('/store/blog/12');

        expect(await screen.findByRole('heading', { name: 'Edit post' }, { timeout: 5000 })).toBeTruthy();
        expect(screen.getByRole('button', { name: 'Preview' })).toBeTruthy();
        expect(screen.getByRole('button', { name: 'Schedule' })).toBeTruthy();
        expect(screen.getByRole('button', { name: 'Publish' })).toBeTruthy();
        expect(screen.getByText('English + বাংলা (both ready)')).toBeTruthy();
        expect(await screen.findByText('548 / 580 px')).toBeTruthy();
        expect(screen.getByText('Cover image missing Bangla alt')).toBeTruthy();
        expect(screen.getByText('2 internal links to products')).toBeTruthy();
        expect(screen.getByText('Hidden on the site — out of stock, archived or unpublished')).toBeTruthy();
        expect(screen.getByRole('heading', { name: 'Buying guide' })).toBeTruthy();
    });

    it('refuses to schedule without a time, then PUTs the scheduled document', { timeout: 20_000 }, async () => {
        const calls = mockApi((url, init) => {
            if (url.endsWith('/auth/me')) return { status: 200, body: editor };
            if (url.endsWith('/blog/posts/12') && init?.method === 'PUT') return { status: 200, body: { ...post, status: 'scheduled', published_at: '2026-11-30T18:00:00+00:00' } };
            if (url.endsWith('/blog/posts/12')) return { status: 200, body: post };
            return terms(url) ?? { status: 404 };
        });

        renderApp('/store/blog/12');
        fireEvent.click(await screen.findByRole('button', { name: 'Schedule' }, { timeout: 5000 }));

        const at = await screen.findByLabelText(/Publish at/);
        expect(calls.mock.calls.some(([, i]) => i?.method === 'PUT')).toBe(false);
        fireEvent.change(at, { target: { value: '2026-12-01T00:00' } });
        fireEvent.click(screen.getByRole('button', { name: 'Schedule' }));

        await waitFor(() => expect(calls.mock.calls.some(([, i]) => i?.method === 'PUT')).toBe(true));
        const body = JSON.parse(String(calls.mock.calls.find(([, i]) => i?.method === 'PUT')![1]!.body)) as { status: string; publish_at: string; tag_ids: number[] };
        expect(body.status).toBe('scheduled');
        expect(body.publish_at).toBe('2026-12-01T00:00:00+06:00');
        expect(body.tag_ids).toEqual([9]);
    });

    it('opens a signed preview link in a new tab', { timeout: 20_000 }, async () => {
        const opened: string[] = [];
        const original = window.open;
        window.open = ((url?: string | URL) => {
            opened.push(String(url));
            return null;
        }) as typeof window.open;
        mockApi((url, init) => {
            if (url.endsWith('/auth/me')) return { status: 200, body: editor };
            if (url.endsWith('/blog/posts/12/preview-link') && init?.method === 'POST')
                return { status: 200, body: { token: 'tok', path: '/blog/preview/tok', path_bn: '/bn/blog/preview/tok', url: 'https://shop.test/blog/preview/tok', url_bn: 'https://shop.test/bn/blog/preview/tok', expires_in: 86400 } };
            if (url.endsWith('/blog/posts/12')) return { status: 200, body: post };
            return terms(url) ?? { status: 404 };
        });

        renderApp('/store/blog/12');
        fireEvent.click(await screen.findByRole('button', { name: 'Preview' }, { timeout: 5000 }));
        await waitFor(() => expect(opened).toEqual(['https://shop.test/blog/preview/tok']));
        window.open = original;
    });
});

describe('blog terms', () => {
    it.each([
        ['categories', 'Buying guides', '/seo/meta/blog_category/3'],
        ['tags', 'educational', '/seo/meta/blog_tag/9'],
        ['authors', 'Mominul Islam', '/seo/meta/blog_author/5'],
    ])('opens the SEO panel for an existing %s archive', { timeout: 20_000 }, async (kind, name, seoUrl) => {
        const calls = mockApi((url) => {
            if (url.endsWith('/auth/me')) return { status: 200, body: { ...editor, permissions: [...editor.permissions, 'seo.view', 'seo.manage'] } };
            if (url.endsWith(seoUrl)) return { status: 404 };
            return terms(url) ?? { status: 404 };
        });

        renderApp(`/store/blog/${kind}`);
        fireEvent.click(await screen.findByRole('button', { name: `Edit ${name}` }, { timeout: 5000 }));
        await waitFor(() => expect(calls.mock.calls.some(([url]) => String(url).endsWith(seoUrl))).toBe(true));
    });

    it('shows no SEO panel without seo.view', { timeout: 20_000 }, async () => {
        const calls = mockApi((url) => {
            if (url.endsWith('/auth/me')) return { status: 200, body: editor };
            return terms(url) ?? { status: 404 };
        });
        renderApp('/store/blog/categories');
        fireEvent.click(await screen.findByRole('button', { name: 'Edit Buying guides' }, { timeout: 5000 }));
        await screen.findByRole('button', { name: 'Save' });
        expect(calls.mock.calls.some(([url]) => String(url).includes('/seo/meta/'))).toBe(false);
    });

    it('lists categories and creates one', { timeout: 20_000 }, async () => {
        const calls = mockApi((url, init) => {
            if (url.endsWith('/auth/me')) return { status: 200, body: editor };
            if (url.endsWith('/blog/categories') && init?.method === 'POST') return { status: 201, body: { ...category, id: 4, name: 'Gift guides' } };
            return terms(url) ?? { status: 404 };
        });

        renderApp('/store/blog/categories');
        const list = await screen.findByRole('list', { name: 'Blog categories' }, { timeout: 5000 });
        expect(within(list).getByText('Buying guides')).toBeTruthy();
        expect(within(list).getByText('2 posts')).toBeTruthy();

        fireEvent.click(screen.getByRole('button', { name: 'Add' }));
        fireEvent.change(screen.getByLabelText(/Name \(English\)/), { target: { value: 'Gift guides' } });
        fireEvent.click(screen.getByRole('button', { name: 'Save' }));

        await waitFor(() => expect(calls.mock.calls.some(([, i]) => i?.method === 'POST')).toBe(true));
        const body = JSON.parse(String(calls.mock.calls.find(([, i]) => i?.method === 'POST')![1]!.body)) as { name: string; slug: string | null };
        expect(body).toMatchObject({ name: 'Gift guides', slug: null });
    });
});
