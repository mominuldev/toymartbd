import { cleanup, fireEvent, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { renderApp } from '@/test/renderApp';
import { formatMoney, signed } from './parts';
import { branchRows, branchSummary, inventoryApi, inventoryStaff, operator, sentBody } from './fixtures';

afterEach(() => {
    cleanup();
    localStorage.clear();
});

const urls = (calls: ReturnType<typeof inventoryApi>) => calls.mock.calls.map(([url]) => String(url));
const unprocessable = (errors: { field: string; message: string }[]) => ({
    status: 422,
    body: { type: 'https://toymartbd.com/problems/validation-error', title: 'Validation failed', status: 422, errors },
});

describe('inventory overview', () => {
    it('shows the server’s stats, location card, tab counts and rows', async () => {
        inventoryApi(inventoryStaff);
        renderApp('/inventory');

        const table = await screen.findByRole('table');
        expect(within(table).getByText('Wooden Alphabet Puzzle')).toBeTruthy();
        expect(within(table).getByText('TMB-WAP-BN')).toBeTruthy();
        // Low stock: the number plus the word, never colour alone.
        expect(within(table).getByText('low')).toBeTruthy();
        expect(within(table).getByText((_, el) => el?.tagName === 'TD' && el.textContent === 'Avg cost৳1,060')).toBeTruthy();
        expect(screen.getByText('Stock value at cost')).toBeTruthy();
        expect(screen.getAllByText((_, el) => el?.tagName === 'SPAN' && el.textContent === '৳28,980').length).toBeGreaterThan(0);
        expect(screen.getByRole('button', { name: /Dhaka HQ · Mirpur/ }).getAttribute('aria-pressed')).toBe('true');
        expect(screen.getByRole('button', { name: /^Low stock\s*1$/ })).toBeTruthy();
        expect(screen.getByRole('button', { name: /^Overstock\s*0$/ })).toBeTruthy();
        expect(screen.getByText('At Dhaka HQ · Mirpur · stock changes only through movements (sale, purchase, adjustment, transfer)')).toBeTruthy();
        // One location: no Other branches column, no Transfer stock; no search field (not in the Figma frame).
        expect(within(table).queryByRole('columnheader', { name: 'Other branches' })).toBeNull();
        expect(screen.queryByRole('button', { name: /Transfer stock/ })).toBeNull();
        expect(screen.queryByRole('searchbox', { name: 'Search stock' })).toBeNull();
    });

    it('filters by tab on the server, with the tab in the URL', async () => {
        const calls = inventoryApi(inventoryStaff);
        renderApp('/inventory');
        await screen.findByRole('table');

        fireEvent.click(screen.getByRole('button', { name: /^Low stock\s*1$/ }));
        await waitFor(() => expect(urls(calls).some((u) => u.includes('filter%5Bstate%5D=low'))).toBe(true));

        fireEvent.click(screen.getByRole('button', { name: /^Overstock\s*0$/ }));
        await waitFor(() => expect(urls(calls).some((u) => u.includes('filter%5Bstate%5D=overstock'))).toBe(true));
    });

    it('shows contextual row actions: short rows get Create PO (a link to the PO form with the SKU), healthy rows their Ledger', async () => {
        inventoryApi({ ...inventoryStaff, permissions: [...inventoryStaff.permissions, 'purchasing.create'] });
        renderApp('/inventory');
        const table = await screen.findByRole('table');

        const create = within(table).getByRole('link', { name: 'Create PO' });
        expect(create.getAttribute('href')).toBe('/purchasing/new?sku=TMB-WAP-BN');
        // No other branch holds stock: no Transfer N.
        expect(within(table).queryByRole('button', { name: /^Transfer/ })).toBeNull();
        expect(within(table).getAllByRole('link', { name: 'Ledger' })).toHaveLength(1);
        expect(within(table).queryByRole('button', { name: 'Adjust' })).toBeNull();
    });

    it('lays out one card per branch; selecting one scopes the page through the URL', async () => {
        const calls = inventoryApi(inventoryStaff, (url) => {
            if (url.includes('/inventory/summary')) return { status: 200, body: branchSummary };
            if (url.includes('/inventory/stock')) return { status: 200, body: { data: branchRows, links: { next: null }, meta: { next_cursor: null } } };
            return undefined;
        });
        renderApp('/inventory');
        const table = await screen.findByRole('table');

        expect(screen.getByText('Across 3 branches · stock changes only through movements (sale, purchase, adjustment, transfer)')).toBeTruthy();
        expect(screen.getByRole('button', { name: /Chattogram · Agrabad\s*Retail branch · POS \+ pickup\s*74 units/ })).toBeTruthy();
        expect(screen.getByRole('button', { name: /Sylhet pickup point/ }).getAttribute('aria-pressed')).toBe('false');
        expect(within(table).getByRole('columnheader', { name: 'Other branches' })).toBeTruthy();
        // Available elsewhere, server figures: CTG holds 1 but it is all reserved.
        expect(within(table).getByText('CTG 4 · SYL 0')).toBeTruthy();
        expect(within(table).getByText('CTG 0 · SYL 0')).toBeTruthy();
        // Without inventory.transfer there is no transfer action at all (inventoryOps.test covers it with the permission).
        expect(within(table).queryByRole('button', { name: 'Transfer 4' })).toBeNull();
        expect(screen.queryByRole('button', { name: /Transfer stock/ })).toBeNull();

        fireEvent.click(screen.getByRole('button', { name: /Chattogram · Agrabad/ }));
        await waitFor(() => expect(urls(calls).some((u) => u.includes('/inventory/summary?filter%5Blocation_id%5D=2'))).toBe(true));
        await waitFor(() => expect(urls(calls).some((u) => u.includes('/inventory/stock?filter%5Blocation_id%5D=2'))).toBe(true));
    });

    it('shows the server’s low-stock count on the Inventory nav item', async () => {
        inventoryApi(inventoryStaff);
        renderApp('/inventory');
        await screen.findByRole('table');

        const nav = screen.getAllByRole('navigation', { name: 'Main navigation' })[0]!;
        await waitFor(() => expect(within(nav).getByRole('link', { name: /Inventory.*1 low on stock/ })).toBeTruthy());
    });

    it('hides cost, value and adjusting from an order operator', async () => {
        inventoryApi(operator);
        renderApp('/inventory');
        const table = await screen.findByRole('table');

        expect(screen.queryByText('Stock value at cost')).toBeNull();
        expect(screen.getByText('Units on hand')).toBeTruthy();
        expect(within(table).queryByText('Avg cost')).toBeNull();
        expect(screen.queryByRole('button', { name: 'New adjustment' })).toBeNull();
        expect(screen.queryByRole('button', { name: 'Stock count' })).toBeNull();
        expect(within(table).getAllByRole('link', { name: 'Ledger' })).toHaveLength(1);
    });

    it('renders in Bangla with Western digits', async () => {
        inventoryApi(inventoryStaff);
        renderApp('/inventory', { locale: 'bn' });
        const table = await screen.findByRole('table');

        expect(within(table).getByText('কাঠের বর্ণমালা পাজল')).toBeTruthy();
        expect(screen.getByRole('button', { name: /^কম স্টক\s*1$/ })).toBeTruthy();
    });
});

/** New adjustment (or Stock count) → search → pick the puzzle, as staff do it. */
async function openAdjustment(button = 'New adjustment') {
    await screen.findByRole('table');
    fireEvent.click(screen.getByRole('button', { name: button }));
    const dialog = await screen.findByRole('dialog');
    fireEvent.change(within(dialog).getByRole('searchbox', { name: /Search by name or SKU/ }), { target: { value: 'puzzle' } });
    fireEvent.click(await within(dialog).findByRole('button', { name: /Wooden Alphabet Puzzle/ }));
    return dialog;
}

describe('stock adjustment', () => {
    it('records a damage of 2 as a −2 change with an Idempotency-Key', async () => {
        const calls = inventoryApi(inventoryStaff);
        renderApp('/inventory');
        const dialog = await openAdjustment();
        expect(within(dialog).getByText('On hand 8 · reserved 2 · available 6')).toBeTruthy();

        fireEvent.change(within(dialog).getByLabelText(/Change in units/), { target: { value: '2' } });
        fireEvent.change(within(dialog).getByLabelText(/Note/), { target: { value: 'Crushed box' } });
        fireEvent.click(within(dialog).getByRole('button', { name: 'Record adjustment' }));

        await waitFor(() => expect(sentBody(calls)).not.toBeNull());
        const sent = sentBody(calls)!;
        expect(sent.body).toEqual({ reason: 'damage', note: 'Crushed box', location_id: null, lines: [{ variant_id: 11, quantity: -2 }] });
        expect(sent.headers['Idempotency-Key']).toMatch(/^[0-9a-f-]{36}$/);
        expect(await screen.findByText('Adjustment ADJ-2026-0012 recorded')).toBeTruthy();
    });

    it('sends a stock count as `counted`, worked out by the server', async () => {
        const calls = inventoryApi(inventoryStaff);
        renderApp('/inventory');
        const dialog = await openAdjustment();
        fireEvent.change(within(dialog).getByLabelText('Reason'), { target: { value: 'recount' } });
        fireEvent.change(within(dialog).getByLabelText(/Counted on hand/), { target: { value: '5' } });
        fireEvent.click(within(dialog).getByRole('button', { name: 'Record adjustment' }));

        await waitFor(() => expect(sentBody(calls)?.body.lines).toEqual([{ variant_id: 11, counted: 5 }]));
    });

    it('validates with the generated schema before calling the API', async () => {
        const calls = inventoryApi(inventoryStaff);
        renderApp('/inventory');
        const dialog = await openAdjustment();
        fireEvent.click(within(dialog).getByRole('button', { name: 'Record adjustment' }));

        expect(await within(dialog).findByText('This field is required.')).toBeTruthy();
        expect(sentBody(calls)).toBeNull();
    });

    it('puts the server’s 422 on the quantity field', async () => {
        inventoryApi(inventoryStaff, (url, init) =>
            url.endsWith('/inventory/adjustments') && init?.method === 'POST'
                ? unprocessable([{ field: 'lines.0.quantity', message: 'Would leave less than the 2 units reserved for open orders (8 on hand).' }])
                : undefined,
        );
        renderApp('/inventory');
        const dialog = await openAdjustment();
        fireEvent.change(within(dialog).getByLabelText(/Change in units/), { target: { value: '7' } });
        fireEvent.click(within(dialog).getByRole('button', { name: 'Record adjustment' }));

        expect(await within(dialog).findByText('Would leave less than the 2 units reserved for open orders (8 on hand).')).toBeTruthy();
    });
});

describe('movement ledger', () => {
    it('shows the reconciliation strip and the append-only rows', async () => {
        inventoryApi(inventoryStaff);
        renderApp('/inventory/movements');

        const table = await screen.findByRole('table');
        expect(await screen.findByText('Opening 210 units · in +26 · out −20 · closing 216 units')).toBeTruthy();
        expect(screen.getByText('Ledger matches stock levels for all 10 variants')).toBeTruthy();
        expect(within(table).getByText('Damage out')).toBeTruthy();
        expect(within(table).getByText('−2')).toBeTruthy();
        expect(within(table).getByText('ADJ-2026-0012')).toBeTruthy();
        expect(within(table).getByText('Mominul')).toBeTruthy();
        // 06:20 UTC is 12:20 pm in Dhaka.
        expect(within(table).getByText('12:20 pm')).toBeTruthy();
    });

    it('asks the server for the date range, type and variant; the balance ignores the type', async () => {
        const calls = inventoryApi(inventoryStaff);
        renderApp('/inventory/movements?variant=12&sku=TMB-FBL-X2');
        await screen.findByRole('table');

        expect(screen.getByText('Variant TMB-FBL-X2')).toBeTruthy();
        expect(urls(calls).some((u) => u.includes('/inventory/movements?filter%5Bvariant_id%5D=12') && u.includes('filter%5Bfrom%5D='))).toBe(true);

        fireEvent.change(screen.getByLabelText('Movement type'), { target: { value: 'damage_out' } });
        await waitFor(() => expect(urls(calls).some((u) => u.includes('/inventory/movements?') && u.includes('filter%5Btype%5D=damage_out'))).toBe(true));
        expect(urls(calls).filter((u) => u.includes('/reconciliation')).every((u) => !u.includes('filter%5Btype%5D'))).toBe(true);

        fireEvent.change(screen.getByLabelText('Date range'), { target: { value: 'all' } });
        await waitFor(() => expect(urls(calls).some((u) => u.includes('/inventory/movements?') && !u.includes('filter%5Bfrom%5D'))).toBe(true));
    });

    it('flags drift instead of hiding it', async () => {
        inventoryApi(inventoryStaff, (url) =>
            url.includes('/reconciliation') ? { status: 200, body: { opening: 0, in: 0, out: 0, closing: 0, variants_checked: 3, drift_count: 1, drift_variant_ids: [12], balanced: false } } : undefined,
        );
        renderApp('/inventory/movements');

        expect(await screen.findByText('Ledger differs from stock levels for 1 variants — report this, nothing was corrected')).toBeTruthy();
    });

    it('formats money and signs without computing', () => {
        expect(formatMoney('1050.4000')).toBe('1,050.4');
        expect(formatMoney('136741.00')).toBe('136,741');
        expect(signed(3)).toBe('+3');
        expect(signed(-20)).toBe('−20');
        expect(signed(0)).toBe('0');
    });
});
