import { cleanup, fireEvent, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { mockApi, renderApp, viewer } from '@/test/renderApp';
import type { Me } from '@/features/auth/api';
import type { CmsPage } from './api';
import type { CmsBlock, CmsHomeDraft } from '../home/api';
import { toPageForm, toPagePayload } from './form';

afterEach(() => {
    cleanup();
    localStorage.clear();
});

const editor: Me = { ...viewer, roles: ['content_editor'], permissions: ['dashboard.view', 'content.view', 'content.manage'] };

const about: CmsPage = {
    id: 3,
    slug: 'about',
    title: 'About us',
    title_bn: 'আমাদের কথা',
    template: 'default',
    status: 'published',
    state: 'live',
    published_at: '2026-11-01T04:00:00Z',
    path: '/about',
    has_bangla: true,
    content: '<p>Hello</p>',
    content_bn: null,
    faq_items: null,
    seo: {
        title: null,
        title_bn: null,
        description: null,
        description_bn: null,
        canonical_url: null,
        robots: 'index,follow',
        sitemap_exclude: false,
        og_title: null,
        og_description: null,
        og_image: null,
        twitter_card: 'summary_large_image',
        focus_keyword: null,
        defaults: { title: 'About us | Toy Mart BD', title_bn: 'আমাদের কথা | Toy Mart BD', description: 'Hello' },
    },
    updated_at: '2026-11-08T06:00:00Z',
    created_at: '2026-11-01T04:00:00Z',
};
const eid: CmsPage = { ...about, id: 4, slug: 'eid-collection', title: 'Eid collection 2026', path: '/eid-collection', state: 'scheduled', published_at: '2027-06-14T18:30:00Z' };
const faqItems = [{ question: 'COD?', question_bn: 'ক্যাশ অন ডেলিভারি?', answer: 'Yes.', answer_bn: 'হ্যাঁ।' }];
const faqPage: CmsPage = { ...about, id: 5, slug: 'faq', title: 'FAQ', path: '/faq', template: 'faq', content: '<p>Old body</p>', content_bn: '<p>পুরোনো লেখা</p>', faq_items: faqItems };
const homeDraft = (patch: Partial<CmsHomeDraft> = {}): CmsHomeDraft => ({
    placement: 'home',
    blocks: [],
    live: { id: 4, version: 4, block_count: 3, published_at: '2026-11-01T06:00:00Z', published_by: { id: 1, name: 'Owner' }, restored_from_version: null, note: null, is_live: true },
    has_unpublished_changes: true,
    block_types: [],
    media: [],
    ...patch,
});
const richText: CmsBlock = {
    id: 21,
    uuid: 'uuid-21',
    type: 'rich_text',
    label: null,
    content: { title: 'About Toy Mart BD', title_bn: 'টয় মার্ট বিডি সম্পর্কে', body: '<p>Hello</p>', body_bn: null },
    position: 0,
    is_enabled: true,
    show_en: true,
    show_bn: true,
    show_mobile: true,
    show_desktop: true,
    starts_at: null,
    ends_at: null,
    updated_at: '2026-11-16T08:00:00Z',
};
const emptyMenu = { location: 'header', exists: false, is_published: false, published_at: null, updated_at: null, max_depth: 3, max_items: 120, items: [] };

function api(extra: (url: string, init?: RequestInit) => { status: number; body?: unknown } | null = () => null) {
    return mockApi((url, init) => {
        const hit = extra(url, init);
        if (hit) return hit;
        if (url.endsWith('/auth/me')) return { status: 200, body: editor };
        if (url.includes('/content/menus/header')) return { status: 200, body: emptyMenu };
        if (url.includes('/content/pages?') || url.endsWith('/content/pages')) return { status: 200, body: { data: url.includes('filter%5Bstatus%5D=draft') ? [] : [about, eid], meta: { next_cursor: null } } };
        if (url.endsWith('/content/home')) return { status: 200, body: homeDraft() };
        if (url.endsWith('/content/pages/3')) return { status: 200, body: about };
        if (url.endsWith('/content/pages/5')) return { status: 200, body: faqPage };
        return { status: 404 };
    });
}

describe('pages', () => {
    it('lists pages with Figma state chips and filters by status on the server', { timeout: 20_000 }, async () => {
        const calls = api();
        renderApp('/store/pages');

        const list = await screen.findByRole('list', { name: 'Pages' }, { timeout: 5000 });
        expect(await within(list).findByText('toymartbd.com/about')).toBeTruthy();
        expect(within(list).getByText('Live · edited 08 Nov')).toBeTruthy();
        expect(within(list).getByText('Scheduled 15 Jun')).toBeTruthy();
        // A page's title opens its section builder; the row action opens its settings.
        expect(within(list).getByRole('link', { name: 'About us' }).getAttribute('href')).toBe('/store/pages/3/build');
        expect(within(list).getByRole('link', { name: 'Settings for About us' }).getAttribute('href')).toBe('/store/pages/3');
        // Scoped to the tab row: the sidebar's Online store submenu also has a "Pages" link.
        const tabs = screen.getByRole('navigation', { name: 'Online store sections' });
        expect(within(tabs).getByRole('link', { name: /Pages/ }).textContent).toContain('2');

        fireEvent.click(screen.getByRole('button', { name: 'Drafts' }));
        expect(await screen.findByText('No pages match.')).toBeTruthy();
        expect(calls.mock.calls.some(([url]) => String(url).includes('filter%5Bstatus%5D=draft'))).toBe(true);
    });

    it('sends the schedule as Dhaka time with +06:00 and the stored body and FAQ list back untouched', () => {
        const form = { ...toPageForm(), title: 'Delivery', status: 'published' as const, published_at: '2026-12-16T00:00' };
        const payload = toPagePayload(form);
        expect(payload.published_at).toBe('2026-12-16T00:00:00+06:00');
        expect(payload).toMatchObject({ content: null, content_bn: null, faq_items: null, slug: null });
        expect(toPagePayload(toPageForm(faqPage), faqPage)).toMatchObject({ template: 'faq', content: '<p>Old body</p>', content_bn: '<p>পুরোনো লেখা</p>', faq_items: faqItems });
        // An existing schedule reads back in Dhaka time.
        expect(toPageForm(about).published_at).toBe('2026-11-01T10:00');
    });

    it('requires the redirect acknowledgement before a live page’s slug changes', { timeout: 20_000 }, async () => {
        const calls = api((url, init) => (url.endsWith('/content/pages/3') && init?.method === 'PUT' ? { status: 200, body: { ...about, slug: 'our-story' } } : null));
        renderApp('/store/pages/3');

        const slug = await screen.findByLabelText('URL', {}, { timeout: 5000 });
        fireEvent.change(slug, { target: { value: 'our-story' } });
        expect(screen.getAllByText('Changing this URL creates a 301 redirect').length).toBeGreaterThan(0);

        fireEvent.click(screen.getByRole('button', { name: 'Save' }));
        expect(calls.mock.calls.some(([, init]) => init?.method === 'PUT')).toBe(false);

        fireEvent.click(screen.getByRole('checkbox', { name: 'I understand the old URL will redirect' }));
        fireEvent.click(screen.getByRole('button', { name: 'Save' }));

        await waitFor(() => expect(calls.mock.calls.some(([, init]) => init?.method === 'PUT')).toBe(true));
        const body = JSON.parse(String(calls.mock.calls.find(([, init]) => init?.method === 'PUT')![1]!.body)) as Record<string, unknown>;
        expect(body).toMatchObject({ slug: 'our-story', acknowledge_slug_redirect: true, title: 'About us', template: 'default' });
    });

    it('validates a new page with the generated schema before calling the API', { timeout: 20_000 }, async () => {
        const calls = api();
        renderApp('/store/pages/new');

        fireEvent.click(await screen.findByRole('button', { name: 'Save' }, { timeout: 5000 }));
        expect(await screen.findByText('This field is required.')).toBeTruthy();
        expect(calls.mock.calls.some(([, init]) => init?.method === 'POST')).toBe(false);
    });

    it('pins Home first: draft state from the home layout, links to the home builder, follows filter and search', { timeout: 20_000 }, async () => {
        api();
        renderApp('/store/pages');

        const list = await screen.findByRole('list', { name: 'Pages' }, { timeout: 5000 });
        const home = await within(list).findByRole('link', { name: 'Home' });
        expect(home.getAttribute('href')).toBe('/store/home');
        await within(list).findByText('toymartbd.com/about');
        await within(list).findByText('Live · edited 01 Nov');
        const rows = within(list).getAllByRole('listitem');
        expect(rows).toHaveLength(3);
        expect(rows[0]!.textContent).toContain('toymartbd.com/');
        expect(rows[0]!.textContent).toContain('Live · edited 01 Nov');
        expect(rows[0]!.textContent).toContain('Unpublished changes');
        // The tab still counts pages only.
        const tabs = screen.getByRole('navigation', { name: 'Online store sections' });
        expect(within(tabs).getByRole('link', { name: /Pages/ }).textContent).toContain('2');

        // Live home is not a draft; a search that does not match hides it.
        fireEvent.click(screen.getByRole('button', { name: 'Drafts' }));
        await waitFor(() => expect(screen.queryByRole('link', { name: 'Home' })).toBeNull());
        fireEvent.click(screen.getByRole('button', { name: 'All' }));
        fireEvent.change(screen.getByLabelText('Search pages'), { target: { value: 'about' } });
        await waitFor(() => expect(screen.queryByRole('link', { name: 'Home' })).toBeNull());
        fireEvent.change(screen.getByLabelText('Search pages'), { target: { value: 'hom' } });
        expect(await screen.findByRole('link', { name: 'Home' })).toBeTruthy();
    });

    it('shows a never-published Home as Draft under the Drafts filter', { timeout: 20_000 }, async () => {
        api((url) => (url.endsWith('/content/home') ? { status: 200, body: homeDraft({ live: null, has_unpublished_changes: false }) } : null));
        renderApp('/store/pages');

        fireEvent.click(await screen.findByRole('button', { name: 'Drafts' }, { timeout: 5000 }));
        const home = await screen.findByRole('link', { name: 'Home' });
        const row = home.closest('li')!;
        expect(row.textContent).toContain('Draft');
        expect(row.textContent).not.toContain('Unpublished changes');
        expect(screen.queryByText('No pages match.')).toBeNull();
    });

    it('page settings: no body or FAQ editors, Edit sections opens the builder, a save keeps the stored body and FAQ', { timeout: 20_000 }, async () => {
        const calls = api((url, init) => (url.endsWith('/content/pages/5') && init?.method === 'PUT' ? { status: 200, body: { ...faqPage, title: 'Questions' } } : null));
        renderApp('/store/pages/5');

        const title = await screen.findByLabelText('Title (English)', {}, { timeout: 5000 });
        expect(screen.queryByText('Add question')).toBeNull();
        expect(screen.getByRole('link', { name: 'Edit sections' }).getAttribute('href')).toBe('/store/pages/5/build');
        // The stored FAQ template is still offered, so the select tells the truth.
        expect((screen.getByLabelText('Template') as HTMLSelectElement).value).toBe('faq');

        fireEvent.change(title, { target: { value: 'Questions' } });
        fireEvent.click(screen.getByRole('button', { name: 'Save' }));
        await waitFor(() => expect(calls.mock.calls.some(([, init]) => init?.method === 'PUT')).toBe(true));
        const body = JSON.parse(String(calls.mock.calls.find(([, init]) => init?.method === 'PUT')![1]!.body)) as Record<string, unknown>;
        expect(body).toMatchObject({ title: 'Questions', template: 'faq', content: '<p>Old body</p>', content_bn: '<p>পুরোনো লেখা</p>', faq_items: faqItems });
    });

    it('offers only Default and With sidebar to a page without the FAQ template', { timeout: 20_000 }, async () => {
        api();
        renderApp('/store/pages/3');

        const select = (await screen.findByLabelText('Template', {}, { timeout: 5000 })) as HTMLSelectElement;
        expect(Array.from(select.options).map((o) => o.value)).toEqual(['default', 'sidebar']);
    });

    it('goes to the new page’s builder after creating it', { timeout: 20_000 }, async () => {
        const created: CmsPage = { ...about, id: 9, slug: 'returns', title: 'Returns', path: '/returns', state: 'draft', status: 'draft' };
        const calls = api((url, init) => {
            if (url.endsWith('/content/pages') && init?.method === 'POST') return { status: 201, body: created };
            if (url.endsWith('/content/pages/9')) return { status: 200, body: created };
            if (url.endsWith('/content/pages/9/layout')) return { status: 200, body: homeDraft({ placement: 'page:9', live: null, has_unpublished_changes: false }) };
            return null;
        });
        renderApp('/store/pages/new');

        fireEvent.change(await screen.findByLabelText('Title (English)', {}, { timeout: 5000 }), { target: { value: 'Returns' } });
        fireEvent.click(screen.getByRole('button', { name: 'Save' }));
        expect(await screen.findByRole('link', { name: 'Page settings' }, { timeout: 5000 })).toBeTruthy();
        // The builder's layout read can still be in flight when the link renders (slow CI runners).
        await waitFor(() => expect(calls.mock.calls.some(([url]) => String(url).endsWith('/content/pages/9/layout'))).toBe(true));
    });

    it('page builder works on the page’s own layout, never the homepage', { timeout: 20_000 }, async () => {
        const calls = api((url, init) => {
            if (url.endsWith('/content/pages/3/layout/preview-token')) return { status: 200, body: { token: 'page_draft.3.x', expires_at: '2026-11-16T10:10:00Z', url: 'https://shop.test/api/preview/page?token=t&locale=en' } };
            if (url.endsWith('/content/pages/3/layout/publish') && init?.method === 'POST') return { status: 201, body: { ...homeDraft().live, version: 2 } };
            if (url.endsWith('/content/pages/3/layout')) return { status: 200, body: homeDraft({ placement: 'page:3', blocks: [richText] }) };
            return null;
        });
        renderApp('/store/pages/3/build');

        const list = await screen.findByRole('list', { name: 'Sections · 1' }, { timeout: 5000 });
        expect(within(list).getAllByRole('listitem')).toHaveLength(1);
        expect(await screen.findByRole('heading', { level: 1, name: 'About us' })).toBeTruthy();
        expect(screen.getByText('Live · edited 08 Nov')).toBeTruthy();
        expect(screen.getByRole('link', { name: 'Page settings' }).getAttribute('href')).toBe('/store/pages/3');
        const crumbs = screen.getByRole('navigation', { name: 'Breadcrumb' });
        expect(within(crumbs).getByRole('link', { name: 'Pages' }).getAttribute('href')).toBe('/store/pages');
        const frame = await screen.findByTitle('Live preview of the draft page');
        expect(frame.getAttribute('src')).toBe('https://shop.test/api/preview/page?token=t&locale=en');

        fireEvent.click(screen.getByRole('button', { name: 'Publish' }));
        expect(await screen.findByText('Published version 2. The page on the storefront is refreshing.')).toBeTruthy();
        const urls = calls.mock.calls.map(([url]) => String(url));
        expect(urls.some((url) => url.endsWith('/content/pages/3/layout/publish'))).toBe(true);
        expect(urls.some((url) => url.includes('/content/home'))).toBe(false);
    });
});
