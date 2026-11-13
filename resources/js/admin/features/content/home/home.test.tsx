import { act, cleanup, fireEvent, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { mockApi, renderApp, viewer } from '@/test/renderApp';
import type { Me } from '@/features/auth/api';
import type { CmsBlock, CmsHomeDraft } from './api';
import { blockSummary } from './summary';
import { translate } from '@/i18n/I18nProvider';

afterEach(() => {
    cleanup();
    localStorage.clear();
    vi.useRealTimers();
});

const editor: Me = { ...viewer, roles: ['content_editor'], permissions: ['dashboard.view', 'content.view', 'content.manage'] };
const reader: Me = { ...viewer, roles: ['viewer'], permissions: ['dashboard.view', 'content.view'] };

const block = (id: number, type: CmsBlock['type'], content: Record<string, unknown>, patch: Partial<CmsBlock> = {}): CmsBlock => ({
    id,
    uuid: `uuid-${id}`,
    type,
    label: null,
    content,
    position: id - 1,
    is_enabled: true,
    show_en: true,
    show_bn: true,
    show_mobile: true,
    show_desktop: true,
    starts_at: null,
    ends_at: null,
    updated_at: '2026-11-13T08:00:00Z',
    ...patch,
});

const blocks = [
    block(1, 'hero', { show_age_pills: true, slides: [{ headline: 'Eid collection', headline_bn: 'ঈদ কালেকশন', cta_href: '/toys', media_id: null }] }, { starts_at: '2099-06-14T18:00:00Z' }),
    block(2, 'product_rail', { title: 'Best sellers', title_bn: 'সবচেয়ে বেশি বিক্রি', rule: 'best_sellers', window_days: 30, limit: 8, min_items: 1, hide_sold_out: true, show_see_all: true, product_ids: [] }),
    block(3, 'faq', { title: 'FAQ', items: [{ question: 'COD?', answer: 'Yes.' }] }, { is_enabled: false }),
];

const draft = (patch: Partial<CmsHomeDraft> = {}): CmsHomeDraft => ({
    placement: 'home',
    blocks,
    live: { id: 4, version: 4, block_count: 3, published_at: '2026-11-01T06:00:00Z', published_by: { id: 1, name: 'Owner' }, restored_from_version: null, note: null, is_live: true },
    has_unpublished_changes: true,
    block_types: [],
    media: [],
    ...patch,
});

function api(me: Me, extra: (url: string, init?: RequestInit) => { status: number; body?: unknown } | null = () => null) {
    return mockApi((url, init) => {
        const custom = extra(url, init);
        if (custom) return custom;
        if (url.endsWith('/auth/me')) return { status: 200, body: me };
        if (url.endsWith('/content/home/preview-token')) return { status: 200, body: { token: 'home_draft.1.1.x', expires_at: '2026-11-13T10:10:00Z', url: 'https://shop.test/api/preview/home?token=t&locale=en' } };
        if (url.endsWith('/content/home')) return { status: 200, body: draft() };
        return { status: 404 };
    });
}

const t = (key: Parameters<typeof translate>[1], vars?: Record<string, string | number>) => translate('en', key, vars);

describe('homepage builder', () => {
    it('lists the sections with their summaries and shows the live preview iframe', { timeout: 20_000 }, async () => {
        api(editor);
        renderApp('/store/home');

        const list = await screen.findByRole('list', { name: 'Sections · 3' }, { timeout: 5000 });
        const rows = within(list).getAllByRole('listitem');
        expect(rows).toHaveLength(3);
        expect(rows[0]!.textContent).toContain('Eid collection');
        expect(rows[0]!.textContent).toMatch(/scheduled 14 Jun|scheduled 15 Jun/);
        expect(rows[1]!.textContent).toContain('Automatic · best sellers, 30 days · 8 products');
        expect(rows[2]!.textContent).toContain('Hidden');
        expect(screen.getByText(/live version 4 from 01 Nov/)).toBeTruthy();

        const frame = await screen.findByTitle('Live preview of the draft homepage');
        expect(frame.getAttribute('src')).toBe('https://shop.test/api/preview/home?token=t&locale=en');
        // The settings panel opens on the first section, Figma's Content · Style · Visibility tabs.
        expect(screen.getByRole('tab', { name: 'Content' }).getAttribute('aria-selected')).toBe('true');
        expect(screen.getByLabelText('শিরোনাম (বাংলা)', { exact: false })).toBeTruthy();
    });

    it('reorders with the keyboard: Space lifts, arrow moves, Space drops and saves', { timeout: 20_000 }, async () => {
        const calls = api(editor, (url, init) => (url.endsWith('/content/home/order') && init?.method === 'PUT' ? { status: 200, body: draft({ blocks: [blocks[1]!, blocks[0]!, blocks[2]!] }) } : null));
        renderApp('/store/home');

        const handle = await screen.findByRole('button', { name: 'Reorder Best sellers' }, { timeout: 5000 });
        fireEvent.keyDown(handle, { key: ' ' });
        expect(await screen.findByText('Best sellers lifted, position 2 of 3.')).toBeTruthy();
        fireEvent.keyDown(handle, { key: 'ArrowUp' });
        expect(await screen.findByText('Best sellers moved to position 1 of 3.')).toBeTruthy();
        fireEvent.keyDown(handle, { key: ' ' });

        await waitFor(() => expect(calls.mock.calls.some(([url]) => String(url).endsWith('/content/home/order'))).toBe(true));
        const call = calls.mock.calls.find(([url]) => String(url).endsWith('/content/home/order'))!;
        expect(JSON.parse(String(call[1]!.body))).toEqual({ ids: [2, 1, 3] });
    });

    it('autosaves a content edit to the draft and reports server validation against the field', { timeout: 20_000 }, async () => {
        let attempts = 0;
        const calls = api(editor, (url, init) => {
            if (url.endsWith('/content/home/blocks/2') && init?.method === 'PATCH') {
                attempts += 1;
                return attempts === 1
                    ? { status: 422, body: { type: 'https://toymartbd.com/problems/validation-error', title: 'Invalid', status: 422, errors: [{ field: 'content.title', message: 'The heading is required.' }] } }
                    : { status: 200, body: { ...blocks[1]!, content: { ...blocks[1]!.content, title: 'Eid best sellers' } } };
            }
            return null;
        });
        renderApp('/store/home');

        fireEvent.click(await screen.findByRole('button', { name: /^Best sellers/ }, { timeout: 5000 }));
        const heading = await screen.findByLabelText('Heading (EN)');
        fireEvent.change(heading, { target: { value: '' } });
        expect(await screen.findByText('The heading is required.', {}, { timeout: 3000 })).toBeTruthy();

        fireEvent.change(heading, { target: { value: 'Eid best sellers' } });
        await waitFor(() => expect(attempts).toBe(2), { timeout: 3000 });
        const patch = calls.mock.calls.filter(([url, init]) => String(url).endsWith('/content/home/blocks/2') && init?.method === 'PATCH').pop()!;
        expect(JSON.parse(String(patch[1]!.body)).content.title).toBe('Eid best sellers');
        expect(await screen.findByText(/autosaved/)).toBeTruthy();
    });

    it('adds a section below the selected one and publishes', { timeout: 20_000 }, async () => {
        const calls = api(editor, (url, init) => {
            if (url.endsWith('/content/home/blocks') && init?.method === 'POST') return { status: 201, body: block(9, 'testimonials', { title: 'What parents say', limit: 6 }) };
            if (url.endsWith('/content/home/publish')) return { status: 201, body: { ...draft().live, version: 5 } };
            return null;
        });
        renderApp('/store/home');

        await screen.findByRole('list', { name: 'Sections · 3' }, { timeout: 5000 });
        fireEvent.click(screen.getByRole('button', { name: 'Add Testimonials' }));
        await waitFor(() => expect(calls.mock.calls.some(([url, init]) => String(url).endsWith('/content/home/blocks') && init?.method === 'POST')).toBe(true));
        const post = calls.mock.calls.find(([url, init]) => String(url).endsWith('/content/home/blocks') && init?.method === 'POST')!;
        expect(JSON.parse(String(post[1]!.body))).toEqual({ type: 'testimonials', position: 1 });

        fireEvent.click(screen.getByRole('button', { name: 'Publish' }));
        expect(await screen.findByText('Published version 5. The storefront home is refreshing.')).toBeTruthy();
    });

    it('is read-only without content.manage', { timeout: 20_000 }, async () => {
        api(reader);
        renderApp('/store/home');

        expect(await screen.findByText('You can view the homepage but not change it.', {}, { timeout: 5000 })).toBeTruthy();
        expect(screen.queryByText('Add section')).toBeNull();
        expect((screen.getByRole('button', { name: 'Publish' }) as HTMLButtonElement).disabled).toBe(true);
        expect((screen.getByLabelText('Headline (EN)') as HTMLInputElement).disabled).toBe(true);
        expect((screen.getByRole('button', { name: 'Reorder Eid collection' }) as HTMLButtonElement).disabled).toBe(true);
    });

    it('summarises schedules in Dhaka time', () => {
        const now = Date.parse('2026-06-20T00:00:00Z');
        expect(blockSummary(block(1, 'faq', { items: [{}, {}] }, { starts_at: '2026-06-24T18:00:00Z' }), t, 'en', now)).toBe('2 items · scheduled 25 Jun');
        expect(blockSummary(block(1, 'faq', { items: [{}] }, { ends_at: '2026-06-01T00:00:00Z' }), t, 'en', now)).toBe('1 items · ended 01 Jun');
        expect(blockSummary(block(1, 'hero', { slides: [{}] }, { is_enabled: false }), t, 'en', now)).toBe('1 slides · Hidden');
        act(() => undefined);
    });
});
