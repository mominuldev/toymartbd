import { cleanup, fireEvent, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { mockApi, renderApp, viewer } from '@/test/renderApp';
import type { Me } from '@/features/auth/api';
import type { Menu } from './api';

afterEach(() => {
    cleanup();
    localStorage.clear();
});

const editor: Me = { ...viewer, roles: ['content_editor'], permissions: ['dashboard.view', 'content.view', 'content.manage'] };

const item = (id: number, label: string, children: Menu['items'] = [], patch: Partial<Menu['items'][number]> = {}): Menu['items'][number] => ({
    id,
    label,
    label_bn: `${label} বাং`,
    link_type: 'custom',
    link_id: null,
    url: `/${label.toLowerCase()}`,
    is_visible: true,
    open_in_new_tab: false,
    target: null,
    children,
    ...patch,
});

const footer: Menu = {
    location: 'footer',
    exists: true,
    is_published: true,
    published_at: '2026-11-10T00:00:00Z',
    updated_at: '2026-11-10T00:00:00Z',
    max_depth: 2,
    max_items: 120,
    items: [item(1, 'Help', [item(2, 'Track'), item(3, 'Returns', [], { link_type: 'page', link_id: 9, url: null, target: { label: 'Returns', label_bn: null, path: '/pages/returns', live: false } })], { link_type: 'none', url: null }), item(4, 'Shop')],
};

describe('menu editor', () => {
    it('reorders with the keyboard, refuses a third footer level and PUTs the whole tree', { timeout: 20_000 }, async () => {
        const calls = mockApi((url, init) => {
            if (url.endsWith('/auth/me')) return { status: 200, body: editor };
            if (url.endsWith('/content/menus/footer') && init?.method === 'PUT') return { status: 200, body: footer };
            if (url.endsWith('/content/menus/footer')) return { status: 200, body: footer };
            return { status: 404 };
        });

        renderApp('/store/navigation/footer');

        const tree = await screen.findByRole('list', { name: 'Footer menu items' }, { timeout: 5000 });
        expect(within(tree).getByText('Not live — hidden on the storefront')).toBeTruthy();
        // Track is level 2 in a 2-level menu: it cannot go deeper.
        expect((screen.getByRole('button', { name: 'Move Returns under the item above' }) as HTMLButtonElement).disabled).toBe(true);

        fireEvent.click(screen.getByRole('button', { name: 'Move Shop up' }));
        fireEvent.click(screen.getByRole('button', { name: 'Move Track out one level' }));
        fireEvent.click(screen.getByRole('button', { name: 'Save' }));

        await waitFor(() => expect(calls.mock.calls.some(([, init]) => init?.method === 'PUT')).toBe(true));
        const put = calls.mock.calls.find(([, init]) => init?.method === 'PUT')!;
        const body = JSON.parse(String(put[1]!.body)) as { is_published: boolean; items: { label: string; children: { label: string }[] }[] };
        expect(body.is_published).toBe(true);
        expect(body.items.map((i) => i.label)).toEqual(['Shop', 'Help', 'Track']);
        expect(body.items[1]!.children.map((c) => c.label)).toEqual(['Returns']);
    });

    it('blocks Save while an item has no target and shows why', { timeout: 20_000 }, async () => {
        const calls = mockApi((url) => {
            if (url.endsWith('/auth/me')) return { status: 200, body: editor };
            if (url.includes('/content/menu-targets')) return { status: 200, body: { data: [] } };
            if (url.endsWith('/content/menus/header')) return { status: 200, body: { ...footer, location: 'header', max_depth: 3, items: [] } };
            return { status: 404 };
        });

        renderApp('/store/navigation/header');

        fireEvent.click(await screen.findByRole('button', { name: 'Add item' }, { timeout: 5000 }));
        fireEvent.change(screen.getByLabelText('Label (English)'), { target: { value: 'Toys' } });
        fireEvent.change(screen.getByLabelText('Links to'), { target: { value: 'page' } });
        fireEvent.click(screen.getByRole('button', { name: 'Save' }));

        expect((await screen.findAllByText('Choose what this item links to.')).length).toBeGreaterThan(0);
        expect(calls.mock.calls.some(([, init]) => init?.method === 'PUT')).toBe(false);
    });
});
