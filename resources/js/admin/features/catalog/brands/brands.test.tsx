import { cleanup, fireEvent, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { mockApi, renderApp, viewer } from '@/test/renderApp';
import type { Me } from '@/features/auth/api';
import type { Brand } from './api';

afterEach(() => {
    cleanup();
    localStorage.clear();
});

const editor: Me = {
    ...viewer,
    roles: ['manager'],
    permissions: ['dashboard.view', 'catalog.view', 'catalog.create', 'catalog.update', 'catalog.archive'],
};

const lego: Brand = {
    id: 1,
    name: 'LEGO',
    name_bn: 'লেগো',
    slug: 'lego',
    description: null,
    description_bn: null,
    is_active: true,
    logo_url: null,
    created_at: '2026-11-10T18:30:00Z',
    updated_at: '2026-11-10T18:30:00Z',
};

const page = (data: Brand[], next: string | null = null) => ({ data, links: { next: null }, meta: { next_cursor: next } });
const unprocessable = (field: string, message: string) => ({
    status: 422,
    body: { type: 'https://toymartbd.com/problems/validation-error', title: 'Validation failed', status: 422, errors: [{ field, message }] },
});

describe('brands', () => {
    it('lists brands and searches on the server, never in the browser', async () => {
        const calls = mockApi((url) => {
            if (url.endsWith('/auth/me')) return { status: 200, body: editor };
            if (url.includes('/brands')) return { status: 200, body: page(url.includes('filter%5Bsearch%5D=fun') ? [] : [lego]) };
            return { status: 404 };
        });

        renderApp('/products/brands');

        const table = await screen.findByRole('table');
        expect(within(table).getByRole('link', { name: 'LEGO' })).toBeTruthy();
        expect(within(table).getByText('লেগো')).toBeTruthy();

        fireEvent.change(screen.getByRole('searchbox', { name: 'Search brands' }), { target: { value: 'fun' } });

        expect(await screen.findByText('No brands match.')).toBeTruthy();
        expect(calls.mock.calls.some(([url]) => String(url).includes('filter%5Bsearch%5D=fun&filter%5Bstatus%5D=active'))).toBe(true);
    });

    it('validates with the generated schema before calling the API', async () => {
        const calls = mockApi((url) => (url.endsWith('/auth/me') ? { status: 200, body: editor } : { status: 404 }));

        renderApp('/products/brands/new');
        fireEvent.click(await screen.findByRole('button', { name: 'Save' }));

        expect(await screen.findByText('This field is required.')).toBeTruthy();
        expect(calls.mock.calls.some(([, init]) => (init as RequestInit | undefined)?.method === 'POST')).toBe(false);
    });

    it('shows validation in Bangla when the UI is Bangla', async () => {
        mockApi((url) => (url.endsWith('/auth/me') ? { status: 200, body: editor } : { status: 404 }));

        renderApp('/products/brands/new', { locale: 'bn' });
        fireEvent.click(await screen.findByRole('button', { name: 'সংরক্ষণ' }));

        expect(await screen.findByText('এই ঘরটি পূরণ করতে হবে।')).toBeTruthy();
    });

    it('puts the server’s 422 message on the matching field', async () => {
        mockApi((url, init) => {
            if (url.endsWith('/auth/me')) return { status: 200, body: editor };
            if (url.endsWith('/brands') && init?.method === 'POST') return unprocessable('slug', 'The slug has already been taken.');
            return { status: 404 };
        });

        renderApp('/products/brands/new');
        fireEvent.change(await screen.findByLabelText('Name (English)'), { target: { value: 'LEGO' } });
        fireEvent.change(screen.getByLabelText(/URL slug/), { target: { value: 'lego' } });
        fireEvent.click(screen.getByRole('button', { name: 'Save' }));

        expect(await screen.findByText('The slug has already been taken.')).toBeTruthy();
        expect(screen.getByLabelText(/URL slug/).getAttribute('aria-invalid')).toBe('true');
    });

    it('sends empty optional fields as null and lands on the edit page after create', async () => {
        let posted: unknown = null;
        mockApi((url, init) => {
            if (url.endsWith('/auth/me')) return { status: 200, body: editor };
            if (url.endsWith('/brands') && init?.method === 'POST') {
                posted = JSON.parse(String(init.body));
                return { status: 201, body: { ...lego, id: 9, name: 'Funskool', name_bn: null, slug: 'funskool' } };
            }
            if (url.endsWith('/brands/9')) return { status: 200, body: { ...lego, id: 9, name: 'Funskool', name_bn: null, slug: 'funskool' } };
            return { status: 404 };
        });

        renderApp('/products/brands/new');
        fireEvent.change(await screen.findByLabelText('Name (English)'), { target: { value: 'Funskool' } });
        fireEvent.click(screen.getByRole('button', { name: 'Save' }));

        expect(await screen.findByRole('heading', { name: 'Edit brand' })).toBeTruthy();
        expect(posted).toEqual({ name: 'Funskool', name_bn: null, slug: null, description: null, description_bn: null, is_active: true });
        expect(screen.getByText('Brand created.')).toBeTruthy();
    });

    it('archives only after the brand name is typed', async () => {
        let deleted = false;
        mockApi((url, init) => {
            if (url.endsWith('/auth/me')) return { status: 200, body: editor };
            if (url.endsWith('/brands/1') && init?.method === 'DELETE') {
                deleted = true;
                return { status: 204 };
            }
            if (url.endsWith('/brands/1')) return { status: 200, body: lego };
            if (url.includes('/brands')) return { status: 200, body: page([]) };
            return { status: 404 };
        });

        renderApp('/products/brands/1');
        fireEvent.click(await screen.findByRole('button', { name: 'Archive brand' }));

        const confirm = screen.getAllByRole('button', { name: 'Archive brand' }).at(-1)!;
        expect(confirm.hasAttribute('disabled')).toBe(true);

        fireEvent.change(screen.getByLabelText('Type LEGO to confirm'), { target: { value: 'LEGO' } });
        fireEvent.click(confirm);

        await waitFor(() => expect(deleted).toBe(true));
        expect(await screen.findByText('Brand archived.')).toBeTruthy();
    });

    it('hides create and edit from a read-only role', async () => {
        mockApi((url) => {
            if (url.endsWith('/auth/me')) return { status: 200, body: viewer };
            if (url.includes('/brands')) return { status: 200, body: page([lego]) };
            return { status: 404 };
        });

        renderApp('/products/brands');

        await screen.findByRole('table');
        expect(screen.queryByRole('link', { name: 'New brand' })).toBeNull();
        expect(screen.queryByRole('link', { name: /Edit/ })).toBeNull();
    });
});
