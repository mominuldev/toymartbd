import { cleanup, fireEvent, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { renderApp } from '@/test/renderApp';
import { formatMoney, signed } from './parts';
import { inventoryApi, inventoryStaff, operator, sentBody } from './fixtures';

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
    });

    it('filters by tab and search on the server, with both in the URL', async () => {
        const calls = inventoryApi(inventoryStaff);
        renderApp('/inventory');
        await screen.findByRole('table');

        fireEvent.click(screen.getByRole('button', { name: /^Low stock\s*1$/ }));
        await waitFor(() => expect(urls(calls).some((u) => u.includes('filter%5Bstate%5D=low'))).toBe(true));

        fireEvent.change(screen.getByRole('searchbox', { name: 'Search stock' }), { target: { value: 'puzzle' } });
        await waitFor(() => expect(urls(calls).some((u) => u.includes('filter%5Bsearch%5D=puzzle'))).toBe(true));
    });

    it('hides cost, value and adjusting from an order operator', async () => {
        inventoryApi(operator);
        renderApp('/inventory');
        const table = await screen.findByRole('table');

        expect(screen.queryByText('Stock value at cost')).toBeNull();
        expect(screen.getByText('Units on hand')).toBeTruthy();
        expect(within(table).queryByText('Avg cost')).toBeNull();
        expect(screen.queryByRole('button', { name: 'New adjustment' })).toBeNull();
        expect(within(table).queryByRole('button', { name: 'Adjust' })).toBeNull();
        expect(within(table).getAllByRole('link', { name: 'Ledger' })).toHaveLength(2);
    });

    it('renders in Bangla with Western digits', async () => {
        inventoryApi(inventoryStaff);
        renderApp('/inventory', { locale: 'bn' });
        const table = await screen.findByRole('table');

        expect(within(table).getByText('কাঠের বর্ণমালা পাজল')).toBeTruthy();
        expect(screen.getByRole('button', { name: /^কম স্টক\s*1$/ })).toBeTruthy();
    });
});

describe('stock adjustment', () => {
    it('records a damage of 2 as a −2 change with an Idempotency-Key', async () => {
        const calls = inventoryApi(inventoryStaff);
        renderApp('/inventory');
        const table = await screen.findByRole('table');

        fireEvent.click(within(table).getAllByRole('button', { name: 'Adjust' })[0]!);
        const dialog = await screen.findByRole('dialog');
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
        const table = await screen.findByRole('table');

        fireEvent.click(within(table).getAllByRole('button', { name: 'Adjust' })[0]!);
        const dialog = await screen.findByRole('dialog');
        fireEvent.change(within(dialog).getByLabelText('Reason'), { target: { value: 'recount' } });
        fireEvent.change(within(dialog).getByLabelText(/Counted on hand/), { target: { value: '5' } });
        fireEvent.click(within(dialog).getByRole('button', { name: 'Record adjustment' }));

        await waitFor(() => expect(sentBody(calls)?.body.lines).toEqual([{ variant_id: 11, counted: 5 }]));
    });

    it('validates with the generated schema before calling the API', async () => {
        const calls = inventoryApi(inventoryStaff);
        renderApp('/inventory');
        const table = await screen.findByRole('table');

        fireEvent.click(within(table).getAllByRole('button', { name: 'Adjust' })[0]!);
        const dialog = await screen.findByRole('dialog');
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
        const table = await screen.findByRole('table');

        fireEvent.click(within(table).getAllByRole('button', { name: 'Adjust' })[0]!);
        const dialog = await screen.findByRole('dialog');
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
