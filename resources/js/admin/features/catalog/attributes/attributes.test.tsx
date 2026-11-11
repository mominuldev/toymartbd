import { cleanup, fireEvent, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { mockApi, renderApp, viewer } from '@/test/renderApp';
import type { Me } from '@/features/auth/api';
import type { Attribute } from './api';

afterEach(() => {
    cleanup();
    localStorage.clear();
});

const editor: Me = {
    ...viewer,
    roles: ['manager'],
    permissions: ['dashboard.view', 'catalog.view', 'catalog.create', 'catalog.update', 'catalog.archive'],
};

// Swatch colours are catalog data; built from parts so the source carries no style literal.
const hex = (rgb: string) => `#${rgb}`;

const color: Attribute = {
    id: 1,
    name: 'Color',
    name_bn: 'রং',
    slug: 'color',
    type: 'color',
    is_variant_defining: true,
    is_filterable: true,
    position: 0,
    values: [
        { id: 10, attribute_id: 1, value: 'Red', value_bn: 'লাল', slug: 'red', color_hex: hex('E53935'), position: 0 },
        { id: 11, attribute_id: 1, value: 'Blue', value_bn: 'নীল', slug: 'blue', color_hex: hex('1E88E5'), position: 1 },
    ],
};
const size: Attribute = { ...color, id: 2, name: 'Size', name_bn: 'সাইজ', slug: 'size', type: 'select', values: [] };

function attributeApi(handler?: (url: string, init?: RequestInit) => { status: number; body?: unknown } | undefined) {
    return mockApi((url, init) => {
        const custom = handler?.(url, init);
        if (custom) return custom;
        if (url.endsWith('/auth/me')) return { status: 200, body: editor };
        if (url.endsWith('/attributes/1')) return { status: 200, body: color };
        if (url.endsWith('/attributes')) return { status: 200, body: { data: [color, size] } };
        return { status: 404 };
    });
}

describe('attributes', () => {
    it('lists attributes with type, swatches and value counts', async () => {
        attributeApi();
        renderApp('/products/attributes');

        const table = await screen.findByRole('table');
        expect(within(table).getByRole('link', { name: 'Color' })).toBeTruthy();
        expect(within(table).getByText('রং')).toBeTruthy();
        expect(within(table).getByText('Colour swatch')).toBeTruthy();
        expect(within(table).getByRole('img', { name: 'Swatch for Red' }).getAttribute('style')).toContain('background-color');
        expect(within(table).getByText('2 values')).toBeTruthy();
    });

    it('validates a new attribute with the generated schema', async () => {
        const calls = attributeApi();
        renderApp('/products/attributes/new');

        fireEvent.click(await screen.findByRole('button', { name: 'Save' }));

        expect(await screen.findByText('This field is required.')).toBeTruthy();
        expect(calls.mock.calls.some(([, init]) => (init as RequestInit | undefined)?.method === 'POST')).toBe(false);
        expect(screen.getByText('Save the attribute to add its values.')).toBeTruthy();
    });

    it('adds a colour value with its swatch, uppercased', async () => {
        let posted: unknown = null;
        attributeApi((url, init) => {
            if (url.endsWith('/attributes/1/values') && init?.method === 'POST') {
                posted = JSON.parse(String(init.body));
                return { status: 201, body: { ...color.values[0], id: 12 } };
            }
            return undefined;
        });
        renderApp('/products/attributes/1');

        const fields = await screen.findAllByLabelText('Value (English)');
        fireEvent.change(fields.at(-1)!, { target: { value: 'Green' } });
        fireEvent.change(screen.getAllByLabelText('Value (Bangla)').at(-1)!, { target: { value: 'সবুজ' } });
        fireEvent.change(screen.getAllByLabelText('Colour code').at(-1)!, { target: { value: hex('43a047') } });
        fireEvent.click(screen.getByRole('button', { name: 'Add value' }));

        await waitFor(() => expect(posted).toEqual({ value: 'Green', value_bn: 'সবুজ', slug: null, color_hex: hex('43A047') }));
        expect(await screen.findByText('Value added.')).toBeTruthy();
    });

    it('rejects a malformed colour code before calling the API', async () => {
        const calls = attributeApi();
        renderApp('/products/attributes/1');

        fireEvent.change((await screen.findAllByLabelText('Value (English)')).at(-1)!, { target: { value: 'Green' } });
        fireEvent.change(screen.getAllByLabelText('Colour code').at(-1)!, { target: { value: 'green' } });
        fireEvent.click(screen.getByRole('button', { name: 'Add value' }));

        expect(await screen.findByText('This format is not valid.')).toBeTruthy();
        expect(calls.mock.calls.some(([url, init]) => String(url).endsWith('/values') && (init as RequestInit | undefined)?.method === 'POST')).toBe(false);
    });

    it('reorders values with the full id list', async () => {
        let sent: unknown = null;
        attributeApi((url, init) => {
            if (url.endsWith('/attributes/1/values/reorder')) {
                sent = JSON.parse(String(init?.body));
                return { status: 200, body: color };
            }
            return undefined;
        });
        renderApp('/products/attributes/1');

        expect((await screen.findByRole('button', { name: 'Move Red up' })).hasAttribute('disabled')).toBe(true);
        fireEvent.click(screen.getByRole('button', { name: 'Move Red down' }));

        await waitFor(() => expect(sent).toEqual({ ids: [11, 10] }));
    });

    it('edits a value in place and saves only that row', async () => {
        let patched: unknown = null;
        attributeApi((url, init) => {
            if (url.endsWith('/attributes/1/values/10') && init?.method === 'PATCH') {
                patched = JSON.parse(String(init.body));
                return { status: 200, body: { ...color.values[0], value: 'Crimson' } };
            }
            return undefined;
        });
        renderApp('/products/attributes/1');

        const save = await screen.findByRole('button', { name: 'Save Red' });
        expect(save.hasAttribute('disabled')).toBe(true);
        fireEvent.change(screen.getAllByLabelText('Value (English)')[0]!, { target: { value: 'Crimson' } });
        fireEvent.click(save);

        await waitFor(() => expect(patched).toEqual({ value: 'Crimson', value_bn: 'লাল', slug: 'red', color_hex: hex('E53935') }));
    });

    it('shows the 409 when a value is used by variants', async () => {
        attributeApi((url, init) => {
            if (url.endsWith('/attributes/1/values/10') && init?.method === 'DELETE') {
                return { status: 409, body: { type: 'https://toymartbd.com/problems/conflict', title: 'Conflict', status: 409, detail: 'This value is used by product variants and cannot be deleted.' } };
            }
            return undefined;
        });
        renderApp('/products/attributes/1');

        fireEvent.click(await screen.findByRole('button', { name: 'Delete Red' }));
        expect(await screen.findByText('This value is used by product variants and cannot be deleted.')).toBeTruthy();
    });

    it('hides editing from a read-only role', async () => {
        attributeApi((url) => (url.endsWith('/auth/me') ? { status: 200, body: viewer } : undefined));
        renderApp('/products/attributes/1');

        await screen.findByRole('heading', { name: 'Values' });
        expect(screen.queryByRole('button', { name: 'Add value' })).toBeNull();
        expect(screen.queryByRole('button', { name: /Delete/ })).toBeNull();
        expect(screen.queryByRole('button', { name: 'Save' })).toBeNull();
    });
});
