import { cleanup, fireEvent, screen, waitFor, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { renderApp, viewer } from '@/test/renderApp';
import { printLabelsRequestSchema } from '@toymart/api-types/zod';
import type { Me } from '@/features/auth/api';
import { listItem, owner, product, productApi, variant } from './fixtures';
import type { LabelTemplateInfo, Product } from './api';

const templates: LabelTemplateInfo[] = [
    { value: 'roll_38x25', stock: 'roll', width_mm: '38', height_mm: '25', per_page: 1 },
    { value: 'roll_50x25', stock: 'roll', width_mm: '50', height_mm: '25', per_page: 1 },
    { value: 'a4_65', stock: 'a4', width_mm: '38.1', height_mm: '21.2', per_page: 65 },
    { value: 'a4_24', stock: 'a4', width_mm: '70', height_mm: '37', per_page: 24 },
];

/** Variant 100 has a barcode; 101–103 do not. */
const withOneBarcode: Product = { ...product, variants: [variant(100, [10, 20], { barcode: '2000000000015', name: 'Red / Small' }), ...product.variants.slice(1)] };

const readOnly: Me = { ...viewer, permissions: ['dashboard.view', 'catalog.view'] };

/** `labels`: what GET /settings/print answers for the Barcode labels document (else it is a 404). */
function labelApi(me: Me, doc: Product = withOneBarcode, labels?: Record<string, unknown>) {
    return productApi(me, (url, init) => {
        if (labels && url.endsWith('/settings/print')) return { status: 200, body: { invoice: {}, receipt: {}, labels, saved: ['labels'] } };
        if (/\/products\/42$/.test(url) && (!init?.method || init.method === 'GET')) return { status: 200, body: doc };
        if (url.endsWith('/products/labels/templates')) return { status: 200, body: { data: templates } };
        if (url.endsWith('/products/labels')) return { status: 200, body: {} };
        if (url.endsWith('/products/42/barcodes')) {
            const ids = (JSON.parse(String(init?.body ?? '{}')) as { variant_ids?: number[] }).variant_ids ?? [];
            return { status: 200, body: { data: ids.map((id, i) => ({ id, product_id: 42, sku: `TM-00042-${id}`, barcode: `20000000000${22 + i * 7}`.slice(0, 13) })) } };
        }
        return undefined;
    });
}

const posted = (calls: ReturnType<typeof labelApi>, path: string) =>
    calls.mock.calls.filter(([url, init]) => String(url).endsWith(path) && (init as RequestInit | undefined)?.method === 'POST').map(([, init]) => JSON.parse(String((init as RequestInit).body)) as Record<string, unknown>);

beforeEach(() => {
    Object.assign(URL, { createObjectURL: vi.fn(() => 'blob:labels'), revokeObjectURL: vi.fn() });
    vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => undefined);
});

afterEach(() => {
    cleanup();
    localStorage.clear();
    vi.restoreAllMocks();
});

describe('barcode generation on the product form', () => {
    it('generates the missing barcodes of saved variants and puts them in the matrix', async () => {
        const calls = labelApi(owner);
        renderApp('/products/42');

        const generate = await screen.findByRole('button', { name: 'Generate missing barcodes' });
        // Enabled once the attributes load and the matrix rows exist.
        await waitFor(() => expect((generate as HTMLButtonElement).disabled).toBe(false));
        fireEvent.click(generate);

        await waitFor(() => expect(posted(calls, '/products/42/barcodes')).toEqual([{ variant_ids: [101, 102, 103] }]));
        expect(await screen.findByDisplayValue('2000000000022')).toBeTruthy();
        expect(screen.getAllByRole('textbox', { name: /^Barcode — / }).map((input) => (input as HTMLInputElement).value)).toEqual(['2000000000015', '2000000000022', '2000000000029', '2000000000036']);
        expect(await screen.findByText('3 barcodes generated')).toBeTruthy();
    });

    it('is not offered without catalog.update', async () => {
        labelApi(readOnly);
        renderApp('/products/42');

        expect(await screen.findByRole('link', { name: 'Print labels' })).toBeTruthy();
        expect(screen.queryByRole('button', { name: 'Generate missing barcodes' })).toBeNull();
    });
});

describe('print labels from the product page', () => {
    it('opens the Print labels page with this product chosen', async () => {
        labelApi(owner);
        renderApp('/products/42');

        const link = await screen.findByRole('link', { name: 'Print labels' });
        expect(link.getAttribute('href')).toBe('/products/labels?ids=42');
        expect(link.getAttribute('target')).toBeNull();
        fireEvent.click(link);
        expect(await screen.findByRole('heading', { level: 1, name: 'Print barcode labels' })).toBeTruthy();
        expect(await screen.findByText('3 variants have no barcode')).toBeTruthy();
    });

    it('opens it in a new tab while the form has unsaved changes', async () => {
        labelApi(owner);
        renderApp('/products/42');

        await screen.findByRole('link', { name: 'Print labels' });
        fireEvent.change(await screen.findByLabelText(/^Name \(English\)/), { target: { value: 'Brick Box 600' } });
        await waitFor(() => expect(screen.getByRole('link', { name: 'Print labels' }).getAttribute('target')).toBe('_blank'));
    });
});

describe('print labels page', () => {
    it('opens from the list selection and prints its variants with generate_missing', async () => {
        const calls = labelApi(owner);
        renderApp('/products');

        fireEvent.click(await screen.findByRole('checkbox', { name: 'Select Brick Box 500' }));
        const bar = screen.getByRole('region', { name: 'Selected products' });
        expect(within(bar).getByText('1 selected')).toBeTruthy();
        fireEvent.click(within(bar).getByRole('button', { name: 'Print labels' }));

        expect(await screen.findByRole('heading', { level: 1, name: 'Print barcode labels' })).toBeTruthy();
        expect(await screen.findByText('3 variants have no barcode')).toBeTruthy();
        expect(screen.getByText('2000000000015')).toBeTruthy();
        fireEvent.click(screen.getByRole('button', { name: 'Print 4 labels' }));

        await waitFor(() => expect(posted(calls, '/products/labels')).toHaveLength(1));
        const body = posted(calls, '/products/labels')[0]!;
        expect(body.generate_missing).toBe(true);
        expect((body.items as unknown[]).length).toBe(4);
        expect(posted(calls, '/products/42/barcodes')).toEqual([]);
    });

    it('opens for one product from its row ⋯ menu', async () => {
        labelApi(owner);
        renderApp('/products');

        fireEvent.click(await screen.findByRole('button', { name: 'More actions for Brick Box 500' }));
        fireEvent.click(within(await screen.findByRole('menu')).getByRole('menuitem', { name: 'Print labels' }));

        expect(await screen.findByRole('heading', { level: 1, name: 'Print barcode labels' })).toBeTruthy();
        expect(await screen.findByRole('link', { name: 'Brick Box 500' })).toBeTruthy();
    });

    it('sends copies, paper, language and every label option as shown', async () => {
        const reader: Me = { ...owner, permissions: [...owner.permissions, 'settings.view'] };
        const calls = labelApi(reader, withOneBarcode, { template: 'roll_50x25', show_name: true, show_options: true, show_price: false, show_store: true, show_digits: true, generate_missing: true });
        renderApp('/products/labels?ids=42');

        await screen.findByText('3 variants have no barcode');
        // Starts from the saved Barcode labels settings.
        await waitFor(() => expect(screen.getByRole('switch', { name: 'Price' }).getAttribute('aria-checked')).toBe('false'));
        expect(screen.queryByText('Anything you leave unchanged follows the store’s print settings.')).toBeNull();
        fireEvent.change(screen.getByLabelText('Set every variant to'), { target: { value: '3' } });
        fireEvent.click(screen.getByRole('button', { name: 'Apply' }));
        fireEvent.click(screen.getByRole('radio', { name: /A4 sheet · 24 labels/ }));
        fireEvent.click(screen.getByRole('button', { name: 'বাংলা' }));
        fireEvent.click(screen.getByRole('switch', { name: 'Store name' }));
        // 12 labels on a 24-label sheet is one sheet.
        expect(screen.getByText('A4 sheets').nextElementSibling?.textContent).toBe('1');
        fireEvent.click(screen.getByRole('button', { name: 'Print 12 labels' }));

        await waitFor(() => expect(posted(calls, '/products/labels')).toHaveLength(1));
        const body = posted(calls, '/products/labels')[0]!;
        expect(printLabelsRequestSchema.safeParse(body).success).toBe(true);
        expect(body).toMatchObject({ template: 'a4_24', locale: 'bn', show_store: false, show_name: true, show_options: true, show_price: false, show_digits: true, generate_missing: true });
        expect(body.items).toEqual([100, 101, 102, 103].map((id) => ({ variant_id: id, copies: 3 })));
    });

    it('leaves out variants without a barcode for a role that cannot generate them, and removed rows', async () => {
        const calls = labelApi(readOnly);
        renderApp('/products/labels?ids=42');

        expect(await screen.findByText('They are left out. Someone who can edit products can generate their barcodes.')).toBeTruthy();
        fireEvent.click(screen.getByRole('button', { name: 'Print 1 label' }));

        await waitFor(() => expect(posted(calls, '/products/labels')).toHaveLength(1));
        const body = posted(calls, '/products/labels')[0]!;
        expect(body.items).toEqual([{ variant_id: 100, copies: 1 }]);
        expect(body.generate_missing).toBe(false);
        // Settings unreadable: nothing changed here, so no option is sent and the saved ones apply.
        expect(body).not.toHaveProperty('template');
        expect(body).not.toHaveProperty('show_price');
    });

    it('previews the picked variant’s own name, SKU and price', async () => {
        labelApi(owner);
        renderApp('/products/labels?ids=42');

        const label = await screen.findByRole('img', { name: 'Sample label' });
        await waitFor(() => expect(label.textContent).toContain('TM-00042-100'));
        fireEvent.click(screen.getAllByRole('button', { name: /^Preview the label for/ })[1]!);
        expect(label.textContent).toContain('TM-00042-101');
        expect(label.textContent).toContain('Brick Box 500');
        expect(label.textContent).toContain('1,250');
    });

    it('fills copies from stock on hand, capped, leaving 0 on hand out', async () => {
        const stocked: Product = { ...withOneBarcode, variants: withOneBarcode.variants.map((v, i) => ({ ...v, barcode: `21000000000${i}`, stock: { on_hand: i * 600, reserved: 0, available: i * 600 } })) };
        const calls = labelApi(owner, stocked);
        renderApp('/products/labels?ids=42');

        fireEvent.click(await screen.findByRole('button', { name: 'Use stock on hand' }));
        fireEvent.click(screen.getByRole('button', { name: 'Print 1500 labels' }));

        await waitFor(() => expect(posted(calls, '/products/labels')).toHaveLength(1));
        expect(posted(calls, '/products/labels')[0]!.items).toEqual([
            { variant_id: 101, copies: 500 },
            { variant_id: 102, copies: 500 },
            { variant_id: 103, copies: 500 },
        ]);
    });

    it('shows the server’s 422 against the variant', async () => {
        productApi(owner, (url, init) => {
            if (/\/products\/42$/.test(url) && (!init?.method || init.method === 'GET')) return { status: 200, body: withOneBarcode };
            if (url.endsWith('/products/labels')) return { status: 422, body: { type: 'https://toymartbd.com/problems/validation-error', title: 'Validation failed', status: 422, errors: [{ field: 'items.0.variant_id', message: 'This variant has no barcode. Generate one first.' }] } };
            return undefined;
        });
        renderApp('/products/labels?ids=42');

        fireEvent.click(await screen.findByRole('switch', { name: 'Generate in-store barcodes for them first' }));
        fireEvent.click(screen.getByRole('button', { name: 'Print 1 label' }));
        expect((await screen.findByRole('alert')).textContent).toContain('TM-00042-100: This variant has no barcode. Generate one first.');
    });

    it('adds several more products at once from the picker', async () => {
        const second: Product = { ...withOneBarcode, id: 43, name: 'Puzzle 100', name_bn: null, variants: [variant(200, [10, 20], { barcode: '2000000000022', sku: 'TM-00043-200' })] };
        productApi(owner, (url, init) => {
            if (/\/products\/42$/.test(url) && (!init?.method || init.method === 'GET')) return { status: 200, body: withOneBarcode };
            if (/\/products\/43$/.test(url) && (!init?.method || init.method === 'GET')) return { status: 200, body: second };
            if (url.includes('/products?') || url.endsWith('/products'))
                return { status: 200, body: { data: [listItem, { ...listItem, id: 43, name: 'Puzzle 100', name_bn: null, sku: 'TM-00043' }], meta: { next_cursor: null } } };
            return undefined;
        });
        renderApp('/products/labels?ids=42');

        fireEvent.click(await screen.findByRole('button', { name: 'Add products' }));
        const picker = await screen.findByRole('region', { name: 'Add more products' });
        await within(picker).findByText('Puzzle 100');
        // Already in the job: ticked, disabled, marked Added.
        expect((within(picker).getByRole('checkbox', { name: 'Select Brick Box 500' }) as HTMLInputElement).disabled).toBe(true);
        expect(within(picker).getByText('Added')).toBeTruthy();
        fireEvent.click(within(picker).getByRole('checkbox', { name: 'Select Puzzle 100' }));
        fireEvent.click(within(picker).getByRole('button', { name: 'Add 1 selected' }));

        expect(await screen.findByRole('link', { name: 'Puzzle 100' })).toBeTruthy();
        expect(screen.getByText('TM-00043-200')).toBeTruthy();
    });

    it('previews at actual size by default, with an enlarged view', async () => {
        labelApi(owner);
        renderApp('/products/labels?ids=42');

        const label = await screen.findByRole('img', { name: 'Sample label' });
        expect(label.getAttribute('data-zoom')).toBe('actual');
        // roll_50x25, the server's default paper: real millimetres.
        expect(label.style.width).toBe('50mm');
        fireEvent.click(screen.getByRole('button', { name: 'Enlarged' }));
        expect(screen.getByRole('img', { name: 'Sample label' }).getAttribute('data-zoom')).toBe('enlarged');
    });

    it('says what to do when no products are chosen', async () => {
        labelApi(owner);
        renderApp('/products/labels');
        // Nothing chosen: the picker is open straight away and Print says why it is unavailable.
        expect(await screen.findByRole('region', { name: 'Add more products' })).toBeTruthy();
        expect(screen.getByText('Add at least one product to print.')).toBeTruthy();
        expect(screen.getByRole('button', { name: 'Print 0 labels' }).hasAttribute('disabled')).toBe(true);
    });
});
