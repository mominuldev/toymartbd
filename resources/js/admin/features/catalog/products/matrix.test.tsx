import { cleanup, fireEvent, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { renderApp } from '@/test/renderApp';
import { combinations, comboKey } from './document';
import { color, contentEditor, owner, product, productApi, sentBody, size, unprocessable } from './fixtures';

afterEach(() => {
    cleanup();
    localStorage.clear();
});

/** The matrix "On" switches (the rail's Featured switch is a switch too). */
const sellSwitches = () => screen.getAllByRole('switch', { name: /^Sell / });
const findSellSwitches = () => screen.findAllByRole('switch', { name: /^Sell / });

/** Category on a new product: the select is the primary category (Figma "Category"). */
async function chooseCategory() {
    await screen.findByRole('option', { name: /Building & Construction/ });
    fireEvent.change(screen.getByLabelText('Category'), { target: { value: '5' } });
}

const addValue = (attribute: string, valueId: number) => fireEvent.change(screen.getByLabelText(`Add a ${attribute} value`), { target: { value: String(valueId) } });

/** On /products/new: switch to a variable product and build Color (3) × Size (4). */
async function buildTwelve() {
    fireEvent.change(await screen.findByLabelText('Product type'), { target: { value: 'variable' } });
    const addAttribute = await screen.findByLabelText('Add attribute');
    fireEvent.change(addAttribute, { target: { value: '1' } });
    for (const id of [10, 11, 12]) addValue('Color', id);
    fireEvent.change(screen.getByLabelText('Add attribute'), { target: { value: '2' } });
    for (const id of [20, 21, 22, 23]) addValue('Size', id);
}

describe('combinations', () => {
    it('is the cartesian product in attribute then value order', () => {
        const combos = combinations(
            [
                { attribute_id: 2, value_ids: [23, 20, 22, 21] },
                { attribute_id: 1, value_ids: [12, 10, 11] },
            ],
            [size, color],
        );
        expect(combos).toHaveLength(12);
        expect(combos[0]).toMatchObject({ label: 'Red / Small', labelBn: 'লাল / ছোট', valueIds: [10, 20] });
        expect(combos[3]!.label).toBe('Red / Extra Large');
        expect(combos[4]!.label).toBe('Blue / Small');
        expect(combos.at(-1)!.label).toBe('Green / Extra Large');
        expect(comboKey([23, 12])).toBe('12,23');
    });
});

describe('VariantMatrixEditor', () => {
    it('3 colours × 4 sizes makes 12 rows, and bulk apply fills every selected row', async () => {
        productApi(owner);
        renderApp('/products/new');
        await buildTwelve();

        expect(screen.getByText(/2 attributes = 12 combinations/)).toBeTruthy();
        expect(sellSwitches()).toHaveLength(12);
        expect(screen.getByLabelText('Sell Green / Extra Large')).toBeTruthy();

        // The bulk bar appears once rows are selected (A9).
        expect(screen.queryByLabelText('Value to apply')).toBeNull();
        fireEvent.click(screen.getByLabelText('Select all variants'));
        fireEvent.change(screen.getByLabelText('Value to apply'), { target: { value: '499' } });
        fireEvent.click(screen.getByRole('button', { name: 'Apply' }));

        const prices = screen.getAllByLabelText(/^Price — /);
        expect(prices).toHaveLength(12);
        expect(prices.every((input) => (input as HTMLInputElement).value === '499')).toBe(true);
    });

    it('bulk apply to the selected rows only', async () => {
        productApi(owner);
        renderApp('/products/new');
        await buildTwelve();

        fireEvent.click(screen.getByLabelText('Select Red / Small'));
        fireEvent.click(screen.getByLabelText('Select Blue / Large'));
        expect(screen.getByText('for 2 selected')).toBeTruthy();
        fireEvent.change(screen.getByLabelText('Set'), { target: { value: 'weight_grams' } });
        fireEvent.change(screen.getByLabelText('Value to apply'), { target: { value: '350' } });
        fireEvent.click(screen.getByRole('button', { name: 'Apply' }));

        expect((screen.getByLabelText('Weight (g) — Red / Small') as HTMLInputElement).value).toBe('350');
        expect((screen.getByLabelText('Weight (g) — Blue / Large') as HTMLInputElement).value).toBe('350');
        expect((screen.getByLabelText('Weight (g) — Red / Medium') as HTMLInputElement).value).toBe('');
    });

    it('sends one row per combination and maps variants.N errors back to the row', async () => {
        const calls = productApi(owner, (url, init) => {
            if (url.endsWith('/products') && init?.method === 'POST')
                return unprocessable([
                    { field: 'variants.5.price', message: 'A price is required for "Blue / Medium".' },
                    { field: 'variants.0.sku', message: 'This SKU is already in use.' },
                ]);
            return undefined;
        });
        renderApp('/products/new');

        fireEvent.change(await screen.findByLabelText(/^Name \(English\)/), { target: { value: 'Racing Car' } });
        await chooseCategory();
        await buildTwelve();
        fireEvent.change(screen.getByLabelText('SKU — Red / Small'), { target: { value: 'RC-RED-S' } });
        fireEvent.click(screen.getByRole('button', { name: 'Create product' }));

        await screen.findByText(/A price is required for "Blue \/ Medium"\./);
        const body = sentBody(calls, 'POST')!;
        const variants = body.variants as { attribute_value_ids: number[]; sku: string | null; status: string }[];
        expect(body.options).toEqual([
            { attribute_id: 1, value_ids: [10, 11, 12] },
            { attribute_id: 2, value_ids: [20, 21, 22, 23] },
        ]);
        expect(variants).toHaveLength(12);
        expect(variants[0]).toMatchObject({ attribute_value_ids: [10, 20], sku: 'RC-RED-S', status: 'active' });
        expect(variants[5]).toMatchObject({ attribute_value_ids: [11, 21], sku: null });

        // variants.5 is Blue / Medium (row 6); variants.0 is Red / Small.
        expect(screen.getByLabelText('Price — Blue / Medium').getAttribute('aria-invalid')).toBe('true');
        expect(screen.getByLabelText('SKU — Red / Small').getAttribute('aria-invalid')).toBe('true');
        expect(screen.getByText('SKU: This SKU is already in use.')).toBeTruthy();
        expect(screen.getByLabelText('Price — Red / Small').getAttribute('aria-invalid')).toBeNull();
        expect(screen.getByRole('link', { name: /Variants.*has errors/ })).toBeTruthy();
    });

    it('edits an existing matrix: disabling a row and removing a value archive, re-adding restores', async () => {
        const calls = productApi(owner, (url, init) => (url.endsWith('/products/42') && init?.method === 'PUT' ? { status: 200, body: product } : undefined));
        renderApp('/products/42?tab=variants');

        expect(await findSellSwitches()).toHaveLength(4);
        expect(screen.getByText(/2 attributes × 2 values = 4 combinations/)).toBeTruthy();
        expect((screen.getByLabelText('SKU — Red / Small') as HTMLInputElement).value).toBe('TM-00042-100');
        expect((screen.getByLabelText('Price — Red / Small') as HTMLInputElement).value).toBe('1250.00');

        fireEvent.click(screen.getByRole('switch', { name: 'Sell Blue / Medium' }));
        expect(screen.getByRole('switch', { name: 'Sell Blue / Medium' }).getAttribute('aria-checked')).toBe('false');

        // Removing Small leaves 2 rows; its variants are listed as archived, with their SKUs.
        fireEvent.click(screen.getByRole('button', { name: 'Remove Small' }));
        expect(sellSwitches()).toHaveLength(2);
        const archived = screen.getByRole('heading', { name: 'Archived variants' }).parentElement!;
        expect(within(archived).getByText('TM-00042-100')).toBeTruthy();

        // Adding it back restores them, prices intact.
        addValue('Size', 20);
        expect(sellSwitches()).toHaveLength(4);
        expect((screen.getByLabelText('Price — Red / Small') as HTMLInputElement).value).toBe('1250.00');

        fireEvent.change(screen.getByLabelText('Price — Red / Small'), { target: { value: '1300' } });
        fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));
        await waitFor(() => expect(sentBody(calls, 'PUT')).not.toBeNull());
        const variants = sentBody(calls, 'PUT')!.variants as Record<string, unknown>[];
        expect(variants).toHaveLength(4);
        expect(variants[0]).toMatchObject({ sku: 'TM-00042-100', price: '1300', cost_price: '800.00' });
        expect(variants.find((v) => JSON.stringify(v.attribute_value_ids) === '[11,21]')).toMatchObject({ status: 'archived' });
    });

    it('without catalog.view_cost the Cost column does not exist and cost is never sent', async () => {
        const calls = productApi({ ...owner, permissions: owner.permissions.filter((p) => p !== 'catalog.view_cost') }, (url, init) =>
            url.endsWith('/products/42') && init?.method === 'PUT' ? { status: 200, body: product } : undefined,
        );
        renderApp('/products/42?tab=variants');

        await findSellSwitches();
        expect(screen.queryByRole('columnheader', { name: 'Cost' })).toBeNull();
        expect(screen.queryByLabelText(/^Cost — /)).toBeNull();
        expect(screen.getByRole('columnheader', { name: 'Price' })).toBeTruthy();

        fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));
        await waitFor(() => expect(sentBody(calls, 'PUT')).not.toBeNull());
        const variants = sentBody(calls, 'PUT')!.variants as Record<string, unknown>[];
        expect(variants.every((v) => !('cost_price' in v))).toBe(true);
    });

    it('without catalog.update_price prices are read-only and values cannot be added', async () => {
        const calls = productApi(contentEditor, (url, init) => (url.endsWith('/products/42') && init?.method === 'PUT' ? { status: 200, body: product } : undefined));
        renderApp('/products/42?tab=variants');

        const price = (await screen.findByLabelText('Price — Red / Small')) as HTMLInputElement;
        expect(price.readOnly).toBe(true);
        expect((screen.getByLabelText('Compare-at price — Red / Small') as HTMLInputElement).readOnly).toBe(true);
        expect((screen.getByLabelText('Weight (g) — Red / Small') as HTMLInputElement).readOnly).toBe(false);
        expect(screen.getByLabelText('Add a Color value').hasAttribute('disabled')).toBe(true);
        expect(screen.queryByRole('option', { name: 'Price' })).toBeNull();

        fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));
        await waitFor(() => expect(sentBody(calls, 'PUT')).not.toBeNull());
        // Prices go back exactly as loaded, so the server sees no price change.
        const variants = sentBody(calls, 'PUT')!.variants as Record<string, unknown>[];
        expect(variants.map((v) => v.price)).toEqual(['1250.00', '1250.00', '1250.00', '1250.00']);
    });

    it('takes opening stock per row on create (inventory.adjust) and sends it with the row', async () => {
        const calls = productApi({ ...owner, permissions: [...owner.permissions, 'inventory.adjust'] });
        renderApp('/products/new');

        fireEvent.change(await screen.findByLabelText(/^Name \(English\)/), { target: { value: 'Racing Car' } });
        await chooseCategory();
        await buildTwelve();
        expect(screen.getByRole('columnheader', { name: 'Opening stock' })).toBeTruthy();
        fireEvent.change(screen.getByLabelText('Opening stock — Blue / Medium'), { target: { value: '7' } });
        fireEvent.click(screen.getByRole('button', { name: 'Create product' }));

        await waitFor(() => expect(sentBody(calls, 'POST')).not.toBeNull());
        const variants = sentBody(calls, 'POST')!.variants as Record<string, unknown>[];
        expect(variants[5]).toMatchObject({ attribute_value_ids: [11, 21], opening_stock: 7 });
        expect(variants[0]).toMatchObject({ opening_stock: null });
    });

    it('without inventory.adjust there is no opening-stock column and nothing is sent', async () => {
        const calls = productApi(owner);
        renderApp('/products/new');

        fireEvent.change(await screen.findByLabelText(/^Name \(English\)/), { target: { value: 'Racing Car' } });
        await chooseCategory();
        await buildTwelve();
        expect(screen.queryByRole('columnheader', { name: 'Opening stock' })).toBeNull();
        fireEvent.click(screen.getByRole('button', { name: 'Create product' }));

        await waitFor(() => expect(sentBody(calls, 'POST')).not.toBeNull());
        const variants = sentBody(calls, 'POST')!.variants as Record<string, unknown>[];
        expect(variants.every((v) => !('opening_stock' in v))).toBe(true);
    });

    it('shows saved stock read-only on edit and never sends opening stock', async () => {
        const stocked = { ...product, variants: product.variants.map((v, i) => ({ ...v, stock: { on_hand: 10 + i, reserved: i, available: 10 } })) };
        const calls = productApi({ ...owner, permissions: [...owner.permissions, 'inventory.view', 'inventory.adjust'] }, (url, init) => {
            if (url.endsWith('/products/42') && (!init?.method || init.method === 'GET')) return { status: 200, body: stocked };
            if (url.endsWith('/products/42') && init?.method === 'PUT') return { status: 200, body: stocked };
            return undefined;
        });
        renderApp('/products/42?tab=variants');

        await findSellSwitches();
        expect(screen.getByRole('columnheader', { name: 'Stock' })).toBeTruthy();
        expect(screen.queryByLabelText(/^Opening stock — /)).toBeNull();
        expect(screen.getByText('10 available · 11 on hand · 1 reserved')).toBeTruthy();
        expect(screen.getByText(/total stock 40/)).toBeTruthy();

        fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));
        await waitFor(() => expect(sentBody(calls, 'PUT')).not.toBeNull());
        const variants = sentBody(calls, 'PUT')!.variants as Record<string, unknown>[];
        expect(variants.every((v) => !('opening_stock' in v))).toBe(true);
    });
});
