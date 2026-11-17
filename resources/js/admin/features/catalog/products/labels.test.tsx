import { cleanup, fireEvent, screen, waitFor, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { renderApp, viewer } from '@/test/renderApp';
import { printLabelsRequestSchema } from '@toymart/api-types/zod';
import type { Me } from '@/features/auth/api';
import { owner, product, productApi, variant } from './fixtures';
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

        expect(await screen.findByRole('button', { name: 'Print labels' })).toBeTruthy();
        expect(screen.queryByRole('button', { name: 'Generate missing barcodes' })).toBeNull();
    });
});

describe('print labels dialog', () => {
    it('downloads a PDF for the product’s variants with the chosen template, copies and language', async () => {
        const calls = labelApi(owner, { ...product, variants: product.variants.map((v, i) => ({ ...v, barcode: `200000000${String(1000 + i)}` })) });
        renderApp('/products/42');

        fireEvent.click(await screen.findByRole('button', { name: 'Print labels' }));
        const dialog = await screen.findByRole('dialog', { name: 'Print barcode labels' });
        const template = within(dialog).getByRole('combobox', { name: 'Label size' });
        await within(dialog).findByRole('option', { name: 'A4 sheet, 65 labels · 38.1 × 21.2 mm' });
        expect(document.activeElement).toBe(template);

        fireEvent.change(template, { target: { value: 'a4_65' } });
        fireEvent.change(within(dialog).getAllByRole('spinbutton')[0]!, { target: { value: '3' } });
        fireEvent.change(within(dialog).getAllByRole('spinbutton')[1]!, { target: { value: '0' } });
        fireEvent.click(within(dialog).getByRole('checkbox', { name: 'Show price' }));
        fireEvent.click(within(dialog).getByRole('button', { name: 'বাং' }));
        expect(within(dialog).getByText('5 labels')).toBeTruthy();
        fireEvent.click(within(dialog).getByRole('button', { name: 'Download PDF' }));

        await waitFor(() => expect(posted(calls, '/products/labels')).toHaveLength(1));
        const body = posted(calls, '/products/labels')[0]!;
        expect(printLabelsRequestSchema.safeParse(body).success).toBe(true);
        expect(body).toEqual({
            template: 'a4_65',
            items: [
                { variant_id: 100, copies: 3 },
                { variant_id: 102, copies: 1 },
                { variant_id: 103, copies: 1 },
            ],
            // show_name was left alone and print settings are not readable: the server's saved value applies.
            show_price: false,
            locale: 'bn',
            generate_missing: false,
        });
        expect(URL.createObjectURL).toHaveBeenCalled();
        await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
    });

    it('generates missing barcodes through the form first, then prints with generate_missing false', async () => {
        const calls = labelApi(owner);
        renderApp('/products/42');

        fireEvent.click(await screen.findByRole('button', { name: 'Print labels' }));
        const dialog = await screen.findByRole('dialog', { name: 'Print barcode labels' });
        expect(within(dialog).getByText('3 variants have no barcode')).toBeTruthy();
        expect((within(dialog).getByRole('checkbox', { name: 'Generate in-store barcodes for them first' }) as HTMLInputElement).checked).toBe(true);
        fireEvent.click(within(dialog).getByRole('button', { name: 'Download PDF' }));

        await waitFor(() => expect(posted(calls, '/products/labels')).toHaveLength(1));
        expect(posted(calls, '/products/42/barcodes')).toEqual([{ variant_ids: [101, 102, 103] }]);
        // Sent false, not omitted: omitted, the server would apply the saved "create missing barcodes".
        expect(posted(calls, '/products/labels')[0]!.generate_missing).toBe(false);
        expect((posted(calls, '/products/labels')[0]!.items as unknown[]).length).toBe(4);
    });

    it('starts from the saved Barcode labels settings when the user can read them', async () => {
        const reader: Me = { ...owner, permissions: [...owner.permissions, 'settings.view'] };
        const labels = { template: 'a4_24', show_name: false, show_options: true, show_price: false, show_store: true, show_digits: false, generate_missing: false } as const;
        const api = labelApi(reader, withOneBarcode, labels);
        renderApp('/products/42');

        fireEvent.click(await screen.findByRole('button', { name: 'Print labels' }));
        const dialog = await screen.findByRole('dialog', { name: 'Print barcode labels' });
        await waitFor(() => expect((within(dialog).getByRole('combobox', { name: 'Label size' }) as HTMLSelectElement).value).toBe('a4_24'));
        expect((within(dialog).getByRole('checkbox', { name: 'Show product name' }) as HTMLInputElement).checked).toBe(false);
        expect((within(dialog).getByRole('checkbox', { name: 'Show price' }) as HTMLInputElement).checked).toBe(false);
        expect((within(dialog).getByRole('checkbox', { name: 'Generate in-store barcodes for them first' }) as HTMLInputElement).checked).toBe(false);
        expect(within(dialog).queryByText('Anything you leave unchanged follows the store’s print settings.')).toBeNull();
        fireEvent.click(within(dialog).getByRole('button', { name: 'Download PDF' }));

        await waitFor(() => expect(posted(api, '/products/labels')).toHaveLength(1));
        expect(posted(api, '/products/labels')[0]).toEqual({ template: 'a4_24', items: [{ variant_id: 100, copies: 1 }], show_price: false, show_name: false, locale: 'en', generate_missing: false });
    });

    it('sends only what changed when print settings are not readable', async () => {
        const calls = labelApi(owner);
        renderApp('/products/42');

        fireEvent.click(await screen.findByRole('button', { name: 'Print labels' }));
        const dialog = await screen.findByRole('dialog', { name: 'Print barcode labels' });
        await within(dialog).findByRole('option', { name: 'As in print settings' });
        expect(within(dialog).getByText('Anything you leave unchanged follows the store’s print settings.')).toBeTruthy();
        fireEvent.click(within(dialog).getByRole('checkbox', { name: 'Generate in-store barcodes for them first' }));
        fireEvent.click(within(dialog).getByRole('button', { name: 'Download PDF' }));

        await waitFor(() => expect(posted(calls, '/products/labels')).toHaveLength(1));
        expect(posted(calls, '/products/labels')[0]).toEqual({ items: [{ variant_id: 100, copies: 1 }], locale: 'en', generate_missing: false });
    });

    it('leaves variants without a barcode out for a role that cannot generate them', async () => {
        const calls = labelApi(readOnly);
        renderApp('/products/42');

        fireEvent.click(await screen.findByRole('button', { name: 'Print labels' }));
        const dialog = await screen.findByRole('dialog', { name: 'Print barcode labels' });
        expect(within(dialog).queryByRole('checkbox', { name: 'Generate in-store barcodes for them first' })).toBeNull();
        expect(within(dialog).getByText(/They are left out/)).toBeTruthy();
        fireEvent.click(within(dialog).getByRole('button', { name: 'Download PDF' }));

        await waitFor(() => expect(posted(calls, '/products/labels')).toHaveLength(1));
        expect(posted(calls, '/products/labels')[0]!.items).toEqual([{ variant_id: 100, copies: 1 }]);
        expect(posted(calls, '/products/42/barcodes')).toEqual([]);
    });

    it('fills copies from stock on hand when the variants carry it', async () => {
        const stocked: Product = { ...withOneBarcode, variants: withOneBarcode.variants.map((v, i) => ({ ...v, barcode: `21000000000${i}`, stock: { on_hand: i * 600, reserved: 0, available: i * 600 } })) };
        const calls = labelApi(owner, stocked);
        renderApp('/products/42');

        fireEvent.click(await screen.findByRole('button', { name: 'Print labels' }));
        const dialog = await screen.findByRole('dialog', { name: 'Print barcode labels' });
        fireEvent.click(within(dialog).getByRole('button', { name: 'Use stock on hand' }));
        fireEvent.click(within(dialog).getByRole('button', { name: 'Download PDF' }));

        await waitFor(() => expect(posted(calls, '/products/labels')).toHaveLength(1));
        // 0 on hand is left out; above the 500-copy cap is capped.
        expect(posted(calls, '/products/labels')[0]!.items).toEqual([
            { variant_id: 101, copies: 500 },
            { variant_id: 102, copies: 500 },
            { variant_id: 103, copies: 500 },
        ]);
    });

    it('shows the server’s 422 against the variant', async () => {
        productApi(owner, (url, init) => {
            if (/\/products\/42$/.test(url) && (!init?.method || init.method === 'GET')) return { status: 200, body: withOneBarcode };
            if (url.endsWith('/products/labels/templates')) return { status: 200, body: { data: templates } };
            if (url.endsWith('/products/labels')) return { status: 422, body: { type: 'https://toymartbd.com/problems/validation-error', title: 'Validation failed', status: 422, errors: [{ field: 'items.0.variant_id', message: 'This variant has no barcode. Generate one first.' }] } };
            return undefined;
        });
        renderApp('/products/42');

        fireEvent.click(await screen.findByRole('button', { name: 'Print labels' }));
        const dialog = await screen.findByRole('dialog', { name: 'Print barcode labels' });
        fireEvent.click(within(dialog).getByRole('checkbox', { name: 'Generate in-store barcodes for them first' }));
        fireEvent.click(within(dialog).getByRole('button', { name: 'Download PDF' }));

        expect((await within(dialog).findByRole('alert')).textContent).toContain('TM-00042-100: This variant has no barcode. Generate one first.');
    });
});

describe('bulk print from the products list', () => {
    it('prints the selected products’ variants with generate_missing', async () => {
        const calls = labelApi(owner);
        renderApp('/products');

        fireEvent.click(await screen.findByRole('checkbox', { name: 'Select Brick Box 500' }));
        const bar = screen.getByRole('region', { name: 'Selected products' });
        expect(within(bar).getByText('1 selected')).toBeTruthy();
        fireEvent.click(within(bar).getByRole('button', { name: 'Print labels' }));

        const dialog = await screen.findByRole('dialog', { name: 'Print barcode labels' });
        await within(dialog).findByText('3 variants have no barcode');
        fireEvent.click(within(dialog).getByRole('button', { name: 'Download PDF' }));

        await waitFor(() => expect(posted(calls, '/products/labels')).toHaveLength(1));
        const body = posted(calls, '/products/labels')[0]!;
        expect(body.generate_missing).toBe(true);
        expect((body.items as unknown[]).length).toBe(4);
        expect(posted(calls, '/products/42/barcodes')).toEqual([]);
    });
});
