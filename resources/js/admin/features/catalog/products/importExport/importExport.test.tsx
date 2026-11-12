import { cleanup, fireEvent, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { mockApi, renderApp, viewer } from '@/test/renderApp';
import type { Me } from '@/features/auth/api';
import type { ProductImport } from './api';

afterEach(() => {
    cleanup();
    localStorage.clear();
});

const manager: Me = { ...viewer, roles: ['manager'], permissions: ['dashboard.view', 'catalog.view', 'catalog.create', 'catalog.import'] };

const previewed: ProductImport = {
    id: 12,
    original_name: 'products.csv',
    format: 'csv',
    status: 'previewed',
    total_rows: 3,
    processed_rows: 3,
    create_count: 1,
    update_count: 1,
    error_count: 1,
    failure_message: null,
    errors: [{ row: 4, sku: 'DL-2', column: 'price', message: 'The price field format is invalid.' }],
    previewed_at: '2026-11-12T08:00:00Z',
    committed_at: null,
    completed_at: null,
    created_at: '2026-11-12T08:00:00Z',
};

const emptyList = { data: [], links: { next: null }, meta: { next_cursor: null } };

describe('product import / export dialog', () => {
    it('exports the filtered list with the same allowlisted filters', async () => {
        mockApi((url) => {
            if (url.endsWith('/auth/me')) return { status: 200, body: manager };
            if (url.includes('/products')) return { status: 200, body: emptyList };
            return { status: 200, body: { data: [] } };
        });

        renderApp('/products?status=draft&q=lego');
        fireEvent.click(await screen.findByRole('button', { name: 'Import / export' }));

        const dialog = await screen.findByRole('dialog', { name: 'Import / export products' });
        fireEvent.click(within(dialog).getByRole('button', { name: 'Excel (.xlsx)' }));
        const link = within(dialog).getByRole('link', { name: 'Download' });
        expect(link.getAttribute('href')).toBe('/api/v1/admin/products/export?format=xlsx&filter%5Bsearch%5D=lego&filter%5Bstatus%5D=draft');
    });

    it('uploads a file, shows the server preview with row-level problems, and commits', async () => {
        const calls = mockApi((url, init) => {
            if (url.endsWith('/auth/me')) return { status: 200, body: manager };
            if (url.endsWith('/product-imports') && init?.method === 'POST') return { status: 202, body: { ...previewed, status: 'queued', errors: [] } };
            if (url.endsWith('/product-imports/12/commit')) return { status: 202, body: { ...previewed, status: 'completed', completed_at: '2026-11-12T08:01:00Z' } };
            if (url.endsWith('/product-imports/12')) return { status: 200, body: previewed };
            if (url.includes('/products')) return { status: 200, body: emptyList };
            return { status: 200, body: { data: [] } };
        });

        renderApp('/products');
        fireEvent.click(await screen.findByRole('button', { name: 'Import / export' }));
        const dialog = await screen.findByRole('dialog', { name: 'Import / export products' });
        fireEvent.click(within(dialog).getByRole('button', { name: 'Import' }));

        const input = dialog.querySelector('input[type="file"]') as HTMLInputElement;
        fireEvent.change(input, { target: { files: [new File(['sku,name\n'], 'products.csv', { type: 'text/csv' })] } });
        fireEvent.click(within(dialog).getByRole('button', { name: 'Check file' }));

        expect(await within(dialog).findByText('The price field format is invalid.')).toBeTruthy();
        expect(within(dialog).getByText('1 with problems')).toBeTruthy();
        expect(within(dialog).getByRole('link', { name: 'Download error report' }).getAttribute('href')).toBe('/api/v1/admin/product-imports/12/errors');

        fireEvent.click(within(dialog).getByRole('button', { name: 'Import 2 rows' }));
        expect(await within(dialog).findByText('Import finished: 1 created, 1 updated.')).toBeTruthy();
        expect(calls.mock.calls.some(([url, init]) => String(url).endsWith('/product-imports/12/commit') && (init as RequestInit).method === 'POST')).toBe(true);
    });

    it('offers only the export to a role without catalog.import', async () => {
        mockApi((url) => {
            if (url.endsWith('/auth/me')) return { status: 200, body: viewer };
            if (url.includes('/products')) return { status: 200, body: emptyList };
            return { status: 200, body: { data: [] } };
        });

        renderApp('/products');
        fireEvent.click(await screen.findByRole('button', { name: 'Import / export' }));
        const dialog = await screen.findByRole('dialog', { name: 'Import / export products' });
        expect(within(dialog).queryByRole('button', { name: 'Import' })).toBeNull();
    });
});
