import { cleanup, fireEvent, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { mockApi, renderApp, viewer } from '@/test/renderApp';
import type { Me } from '@/features/auth/api';
import type { CmsPage } from './api';
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
    path: '/pages/about',
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
const eid: CmsPage = { ...about, id: 4, slug: 'eid-collection', title: 'Eid collection 2026', path: '/pages/eid-collection', state: 'scheduled', published_at: '2027-06-14T18:30:00Z' };
const emptyMenu = { location: 'header', exists: false, is_published: false, published_at: null, updated_at: null, max_depth: 3, max_items: 120, items: [] };

function api(extra: (url: string, init?: RequestInit) => { status: number; body?: unknown } | null = () => null) {
    return mockApi((url, init) => {
        const hit = extra(url, init);
        if (hit) return hit;
        if (url.endsWith('/auth/me')) return { status: 200, body: editor };
        if (url.includes('/content/menus/header')) return { status: 200, body: emptyMenu };
        if (url.includes('/content/pages?') || url.endsWith('/content/pages')) return { status: 200, body: { data: url.includes('filter%5Bstatus%5D=draft') ? [] : [about, eid], meta: { next_cursor: null } } };
        if (url.endsWith('/content/pages/3')) return { status: 200, body: about };
        return { status: 404 };
    });
}

describe('pages', () => {
    it('lists pages with Figma state chips and filters by status on the server', { timeout: 20_000 }, async () => {
        const calls = api();
        renderApp('/store/pages');

        const list = await screen.findByRole('list', { name: 'Pages' }, { timeout: 5000 });
        expect(within(list).getByText('toymartbd.com/pages/about')).toBeTruthy();
        expect(within(list).getByText('Live · edited 08 Nov')).toBeTruthy();
        expect(within(list).getByText('Scheduled 15 Jun')).toBeTruthy();
        // Scoped to the tab row: the sidebar's Online store submenu also has a "Pages" link.
        const tabs = screen.getByRole('navigation', { name: 'Online store sections' });
        expect(within(tabs).getByRole('link', { name: /Pages/ }).textContent).toContain('2');

        fireEvent.click(screen.getByRole('button', { name: 'Drafts' }));
        expect(await screen.findByText('No pages match.')).toBeTruthy();
        expect(calls.mock.calls.some(([url]) => String(url).includes('filter%5Bstatus%5D=draft'))).toBe(true);
    });

    it('sends the schedule as Dhaka time with +06:00 and only the FAQ list for the FAQ template', () => {
        const form = { ...toPageForm(), title: 'Delivery', status: 'published' as const, published_at: '2026-12-16T00:00' };
        const payload = toPagePayload(form);
        expect(payload.published_at).toBe('2026-12-16T00:00:00+06:00');
        expect(payload.faq_items).toBeNull();
        expect(payload.slug).toBeNull();
        expect(toPagePayload({ ...form, template: 'faq', faq: [{ question: ' Q ', question_bn: '', answer: 'A', answer_bn: 'উ' }] }).faq_items).toEqual([{ question: 'Q', question_bn: null, answer: 'A', answer_bn: 'উ' }]);
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
});
