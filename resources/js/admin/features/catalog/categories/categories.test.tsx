import { cleanup, fireEvent, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { mockApi, renderApp, viewer } from '@/test/renderApp';
import type { Me } from '@/features/auth/api';
import type { Category } from './api';

afterEach(() => {
    cleanup();
    localStorage.clear();
});

const editor: Me = {
    ...viewer,
    roles: ['manager'],
    permissions: ['dashboard.view', 'catalog.view', 'catalog.create', 'catalog.update', 'catalog.archive'],
};

const node = (id: number, name: string, extra: Partial<Category> = {}): Category => ({
    id,
    parent_id: null,
    name,
    name_bn: null,
    slug: name.toLowerCase().replace(/\s+/g, '-'),
    description: null,
    description_bn: null,
    icon: null,
    image_url: null,
    is_featured: false,
    show_in_menu: true,
    is_active: true,
    position: 0,
    depth: 0,
    created_at: '2026-11-10T18:30:00Z',
    updated_at: '2026-11-10T18:30:00Z',
    children: [],
    ...extra,
});

const puzzles = node(2, 'Puzzles', { parent_id: 1, depth: 1, name_bn: 'পাজল' });
const toys = node(1, 'Toys', { name_bn: 'খেলনা', children: [puzzles] });
const books = node(3, 'Books', { position: 1 });
const tree = [toys, books];

function treeApi(onMove?: (id: string, body: unknown) => void) {
    return mockApi((url, init) => {
        if (url.endsWith('/auth/me')) return { status: 200, body: editor };
        const moved = url.match(/\/categories\/(\d+)\/move$/);
        if (moved && init?.method === 'POST') {
            onMove?.(moved[1]!, JSON.parse(String(init.body)));
            return { status: 200, body: books };
        }
        if (url.includes('/categories?tree=1')) return { status: 200, body: { data: tree } };
        return { status: 404 };
    });
}

describe('categories', () => {
    it('renders the tree with Bangla names and collapses a branch', async () => {
        treeApi();
        renderApp('/products/categories');

        const region = await screen.findByRole('region', { name: 'Category tree' });
        expect(within(region).getByRole('link', { name: 'Toys' })).toBeTruthy();
        expect(within(region).getByText('খেলনা')).toBeTruthy();
        expect(within(region).getByRole('link', { name: 'Puzzles' })).toBeTruthy();

        const collapse = screen.getByRole('button', { name: 'Collapse Toys' });
        expect(collapse.getAttribute('aria-expanded')).toBe('true');
        fireEvent.click(collapse);

        expect(screen.queryByRole('link', { name: 'Puzzles' })).toBeNull();
        expect(screen.getByRole('button', { name: 'Expand Toys' }).getAttribute('aria-expanded')).toBe('false');
    });

    it('moves with buttons and disables impossible moves', async () => {
        const moves: [string, unknown][] = [];
        treeApi((id, body) => moves.push([id, body]));
        renderApp('/products/categories');

        await screen.findByRole('region', { name: 'Category tree' });
        expect(screen.getByRole('button', { name: 'Move Toys up' }).hasAttribute('disabled')).toBe(true);
        expect(screen.getByRole('button', { name: 'Outdent Toys one level' }).hasAttribute('disabled')).toBe(true);
        expect(screen.getByRole('button', { name: 'Indent Toys under the category above' }).hasAttribute('disabled')).toBe(true);

        fireEvent.click(screen.getByRole('button', { name: 'Move Books up' }));
        await waitFor(() => expect(moves).toEqual([['3', { parent_id: null, position: 0 }]]));
        expect(await screen.findByText('Books moved.')).toBeTruthy();

        fireEvent.click(screen.getByRole('button', { name: 'Indent Books under the category above' }));
        await waitFor(() => expect(moves.at(-1)).toEqual(['3', { parent_id: 1, position: 1 }]));

        fireEvent.click(screen.getByRole('button', { name: 'Outdent Puzzles one level' }));
        await waitFor(() => expect(moves.at(-1)).toEqual(['2', { parent_id: null, position: 1 }]));
    });

    it('shows the server’s refusal of a move', async () => {
        mockApi((url, init) => {
            if (url.endsWith('/auth/me')) return { status: 200, body: editor };
            if (url.endsWith('/move') && init?.method === 'POST') {
                return { status: 409, body: { type: 'https://toymartbd.com/problems/conflict', title: 'Conflict', status: 409, detail: 'The parent category is archived.' } };
            }
            if (url.includes('/categories?tree=1')) return { status: 200, body: { data: tree } };
            return { status: 404 };
        });
        renderApp('/products/categories');

        fireEvent.click(await screen.findByRole('button', { name: 'Move Books up' }));
        expect(await screen.findByText('The parent category is archived.')).toBeTruthy();
    });

    it('hides move buttons and New from a read-only role', async () => {
        mockApi((url) => {
            if (url.endsWith('/auth/me')) return { status: 200, body: viewer };
            if (url.includes('/categories?tree=1')) return { status: 200, body: { data: tree } };
            return { status: 404 };
        });
        renderApp('/products/categories');

        await screen.findByRole('region', { name: 'Category tree' });
        expect(screen.queryByRole('button', { name: /Move/ })).toBeNull();
        expect(screen.queryByRole('link', { name: 'New category' })).toBeNull();
    });

    it('validates with the generated schema before calling the API', async () => {
        const calls = treeApi();
        renderApp('/products/categories/new');

        fireEvent.click(await screen.findByRole('button', { name: 'Save' }));

        expect(await screen.findByText('This field is required.')).toBeTruthy();
        expect(calls.mock.calls.some(([, init]) => (init as RequestInit | undefined)?.method === 'POST')).toBe(false);
    });

    it('creates a child category with the chosen parent and both languages', async () => {
        let posted: unknown = null;
        mockApi((url, init) => {
            if (url.endsWith('/auth/me')) return { status: 200, body: editor };
            if (url.endsWith('/categories') && init?.method === 'POST') {
                posted = JSON.parse(String(init.body));
                return { status: 201, body: node(9, 'Jigsaws', { parent_id: 2, depth: 2 }) };
            }
            if (url.endsWith('/categories/9')) return { status: 200, body: node(9, 'Jigsaws', { parent_id: 2, depth: 2 }) };
            if (url.includes('/categories?tree=1')) return { status: 200, body: { data: tree } };
            return { status: 404 };
        });
        renderApp('/products/categories/new');

        fireEvent.change(await screen.findByLabelText('Name (English)'), { target: { value: 'Jigsaws' } });
        fireEvent.change(screen.getByLabelText(/Name \(Bangla\)/), { target: { value: 'জিগস' } });
        fireEvent.change(screen.getByLabelText('Parent category'), { target: { value: '2' } });
        fireEvent.click(screen.getByLabelText('Show in the storefront menu'));
        fireEvent.click(screen.getByRole('button', { name: 'Save' }));

        // The split view keeps the tree and opens the saved category in the panel.
        expect(await screen.findByRole('heading', { name: 'Jigsaws' })).toBeTruthy();
        expect(screen.getByRole('region', { name: 'Category tree' })).toBeTruthy();
        expect(posted).toEqual({
            name: 'Jigsaws',
            name_bn: 'জিগস',
            slug: null,
            parent_id: 2,
            description: null,
            description_bn: null,
            icon: null,
            is_featured: false,
            show_in_menu: false,
            is_active: true,
        });
        expect(screen.getByText('Category created.')).toBeTruthy();
    });

    it('never offers a category or its descendants as its own parent', async () => {
        mockApi((url) => {
            if (url.endsWith('/auth/me')) return { status: 200, body: editor };
            if (url.endsWith('/categories/1')) return { status: 200, body: toys };
            if (url.includes('/categories?tree=1')) return { status: 200, body: { data: tree } };
            return { status: 404 };
        });
        renderApp('/products/categories/1');

        const select = await screen.findByLabelText('Parent category');
        const options = within(select).getAllByRole('option').map((option) => option.textContent);
        expect(options).toEqual(['None — top level', 'Books']);
    });

    it('moves on save when the parent changes, and puts a 422 on the field', async () => {
        let patched: unknown = null;
        let moved: unknown = null;
        mockApi((url, init) => {
            if (url.endsWith('/auth/me')) return { status: 200, body: editor };
            if (url.endsWith('/categories/3') && init?.method === 'PATCH') {
                patched = JSON.parse(String(init.body));
                return { status: 200, body: books };
            }
            if (url.endsWith('/categories/3/move')) {
                moved = JSON.parse(String(init?.body));
                return { status: 200, body: { ...books, parent_id: 1, depth: 1 } };
            }
            if (url.endsWith('/categories/3')) return { status: 200, body: books };
            if (url.includes('/categories?tree=1')) return { status: 200, body: { data: tree } };
            return { status: 404 };
        });
        renderApp('/products/categories/3');

        fireEvent.change(await screen.findByLabelText('Parent category'), { target: { value: '1' } });
        fireEvent.click(screen.getByRole('button', { name: 'Save' }));

        await waitFor(() => expect(moved).toEqual({ parent_id: 1, position: 1 }));
        expect(patched).not.toHaveProperty('parent_id');
        expect(await screen.findByText('Changes saved.')).toBeTruthy();
    });

    it('archives only after the name is typed', async () => {
        let deleted = false;
        mockApi((url, init) => {
            if (url.endsWith('/auth/me')) return { status: 200, body: editor };
            if (url.endsWith('/categories/3') && init?.method === 'DELETE') {
                deleted = true;
                return { status: 204 };
            }
            if (url.endsWith('/categories/3')) return { status: 200, body: books };
            if (url.includes('/categories?tree=1')) return { status: 200, body: { data: tree } };
            return { status: 404 };
        });
        renderApp('/products/categories/3');

        fireEvent.click(await screen.findByRole('button', { name: 'Archive category' }));
        const confirm = screen.getAllByRole('button', { name: 'Archive category' }).at(-1)!;
        expect(confirm.hasAttribute('disabled')).toBe(true);

        fireEvent.change(screen.getByLabelText('Type Books to confirm'), { target: { value: 'Books' } });
        fireEvent.click(confirm);

        await waitFor(() => expect(deleted).toBe(true));
        expect(await screen.findByText('Category archived.')).toBeTruthy();
    });

    it('opens a row in the side panel, focuses its heading, and returns focus to the row on close', async () => {
        mockApi((url) => {
            if (url.endsWith('/auth/me')) return { status: 200, body: editor };
            if (url.endsWith('/categories/3')) return { status: 200, body: books };
            if (url.includes('/categories?tree=1')) return { status: 200, body: { data: tree } };
            return { status: 404 };
        });
        renderApp('/products/categories');

        const region = await screen.findByRole('region', { name: 'Category tree' });
        fireEvent.click(within(region).getByRole('link', { name: 'Books' }));

        const heading = await screen.findByRole('heading', { name: 'Books' });
        await waitFor(() => expect(document.activeElement).toBe(heading));
        expect(within(region).getByRole('link', { name: 'Books' }).getAttribute('aria-current')).toBe('page');
        expect((screen.getByLabelText('Name (English)') as HTMLInputElement).value).toBe('Books');

        fireEvent.click(screen.getByRole('button', { name: 'Close' }));
        await waitFor(() => expect(screen.queryByRole('heading', { name: 'Books' })).toBeNull());
        await waitFor(() => expect(document.activeElement).toBe(within(region).getByRole('link', { name: 'Books' })));
    });

    it('warns before leaving a row with unsaved changes', async () => {
        mockApi((url) => {
            if (url.endsWith('/auth/me')) return { status: 200, body: editor };
            if (url.endsWith('/categories/3')) return { status: 200, body: books };
            if (url.endsWith('/categories/1')) return { status: 200, body: toys };
            if (url.includes('/categories?tree=1')) return { status: 200, body: { data: tree } };
            return { status: 404 };
        });
        renderApp('/products/categories/3');

        fireEvent.change(await screen.findByLabelText('Name (English)'), { target: { value: 'Story books' } });
        const region = screen.getByRole('region', { name: 'Category tree' });
        fireEvent.click(within(region).getByRole('link', { name: 'Toys' }));

        expect(await screen.findByRole('heading', { name: 'Discard unsaved changes?' })).toBeTruthy();
        fireEvent.click(screen.getByRole('button', { name: 'Keep editing' }));
        await waitFor(() => expect(screen.queryByRole('heading', { name: 'Discard unsaved changes?' })).toBeNull());
        expect((screen.getByLabelText('Name (English)') as HTMLInputElement).value).toBe('Story books');

        fireEvent.click(within(region).getByRole('link', { name: 'Toys' }));
        fireEvent.click(await screen.findByRole('button', { name: 'Discard changes' }));
        expect(await screen.findByRole('heading', { name: 'Toys' })).toBeTruthy();
    });

    it('starts a subcategory from a row with the parent filled in', async () => {
        treeApi();
        renderApp('/products/categories');

        fireEvent.click(await screen.findByRole('link', { name: 'Add a subcategory under Toys' }));

        expect(await screen.findByRole('heading', { name: 'New category' })).toBeTruthy();
        expect((screen.getByLabelText('Parent category') as HTMLSelectElement).value).toBe('1');
    });

    it('renders the tree in Bangla', async () => {
        treeApi();
        renderApp('/products/categories', { locale: 'bn' });

        expect(await screen.findByRole('heading', { name: 'ক্যাটাগরি' })).toBeTruthy();
        expect(await screen.findByRole('button', { name: 'Books উপরে সরান' })).toBeTruthy();
    });
});
