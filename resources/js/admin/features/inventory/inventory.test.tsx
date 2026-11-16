import { cleanup, fireEvent, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { renderApp } from '@/test/renderApp';
import { exactMoney, formatMoney, signed } from './parts';
import { branchRows, branchSummary, inventoryApi, inventoryStaff, operator, puzzle, sentBody } from './fixtures';

afterEach(() => {
    cleanup();
    localStorage.clear();
});

const urls = (calls: ReturnType<typeof inventoryApi>) => calls.mock.calls.map(([url]) => String(url));
const unprocessable = (errors: { field: string; message: string }[]) => ({
    status: 422,
    body: { type: 'https://toymartbd.com/problems/validation-error', title: 'Validation failed', status: 422, errors },
});

/** The ⋯ menu of one row, opened: its items by accessible name. */
async function openRowMenu(name: RegExp | string) {
    fireEvent.click(await screen.findByRole('button', { name: typeof name === 'string' ? `Actions for ${name}` : name }));
    return screen.findByRole('menu');
}

describe('inventory overview', () => {
    it('shows the server’s stats, location card, tab counts and rows', async () => {
        inventoryApi(inventoryStaff);
        renderApp('/inventory');

        const table = await screen.findByRole('table');
        expect(within(table).getByText('Wooden Alphabet Puzzle')).toBeTruthy();
        expect(within(table).getByText('TMB-WAP-BN')).toBeTruthy();
        // One status chip per row: the state's word, never colour alone.
        expect(within(table).getByText('Low stock')).toBeTruthy();
        expect(within(table).getByText('In stock')).toBeTruthy();
        // Money in two decimals; the server's 4 dp figure stays the tooltip.
        expect(within(table).getByText((_, el) => el?.tagName === 'TD' && el.textContent === 'Avg cost৳1,060.00')).toBeTruthy();
        expect(screen.getByText('Stock value at cost')).toBeTruthy();
        expect(screen.getAllByText((_, el) => el?.tagName === 'SPAN' && el.textContent === '৳28,980.00').length).toBeGreaterThan(0);
        expect(screen.getByRole('button', { name: /^Low stock\s*1$/ })).toBeTruthy();
        expect(screen.getByRole('button', { name: /^Overstock\s*0$/ })).toBeTruthy();
        expect(screen.getByText('At Dhaka HQ · Mirpur · stock changes only through movements (sale, purchase, adjustment, transfer)')).toBeTruthy();
        // One location: no branch cards, no Other branches column, no Transfer stock.
        expect(screen.queryByRole('group', { name: 'Stock locations' })).toBeNull();
        expect(within(table).queryByRole('columnheader', { name: 'Other branches' })).toBeNull();
        expect(screen.queryByRole('button', { name: /Transfer stock/ })).toBeNull();
        expect(screen.getByRole('searchbox', { name: 'Search stock' })).toBeTruthy();
    });

    it('names the variant on its own, under the product', async () => {
        inventoryApi(inventoryStaff, (url) =>
            url.includes('/inventory/stock')
                ? {
                      status: 200,
                      body: {
                          data: [
                              { ...puzzle, variant_name: '90 pcs · Box', variant_name_bn: '90 পিস · বাক্স' },
                              { ...puzzle, variant_id: 13, sku: 'TMB-WAP-TUB', variant_name: '90 pcs · Tub', variant_name_bn: null, state: null },
                          ],
                          links: { next: null },
                          meta: { next_cursor: null },
                      },
                  }
                : undefined,
        );
        renderApp('/inventory');
        const table = await screen.findByRole('table');

        expect(within(table).getByText('90 pcs · Box')).toBeTruthy();
        expect(within(table).getByText('90 pcs · Tub')).toBeTruthy();
        // The full name is the tooltip of the (possibly cut) product line.
        expect(within(table).getByTitle('Wooden Alphabet Puzzle / 90 pcs · Box')).toBeTruthy();
        expect(screen.getByRole('button', { name: 'Actions for Wooden Alphabet Puzzle / 90 pcs · Tub' })).toBeTruthy();
    });

    it('formats an unrounded cost to two decimals with the full figure as tooltip', async () => {
        inventoryApi(inventoryStaff, (url) =>
            url.includes('/inventory/stock') ? { status: 200, body: { data: [{ ...puzzle, unit_cost: '2937.7371' }], links: { next: null }, meta: { next_cursor: null } } } : undefined,
        );
        renderApp('/inventory');
        const table = await screen.findByRole('table');

        const cost = within(table).getByTitle('৳2,937.7371');
        expect(cost.textContent).toBe('৳2,937.74');
    });

    it('filters by tab on the server, with the tab in the URL', async () => {
        const calls = inventoryApi(inventoryStaff);
        const { router } = renderApp('/inventory');
        await screen.findByRole('table');

        fireEvent.click(screen.getByRole('button', { name: /^Low stock\s*1$/ }));
        await waitFor(() => expect(urls(calls).some((u) => u.includes('filter%5Bstate%5D=low'))).toBe(true));
        expect(router.state.location.search).toBe('?state=low');

        fireEvent.click(screen.getByRole('button', { name: /^Overstock\s*0$/ }));
        await waitFor(() => expect(urls(calls).some((u) => u.includes('filter%5Bstate%5D=overstock'))).toBe(true));
    });

    it('makes the Low and Out of stock cards shortcuts to their tab', async () => {
        const calls = inventoryApi(inventoryStaff);
        const { router } = renderApp('/inventory');
        await screen.findByRole('table');

        const low = screen.getByRole('button', { name: /^Low stock\s*1\s*Available ≤ reorder point/ });
        fireEvent.click(low);
        await waitFor(() => expect(router.state.location.search).toBe('?state=low'));
        expect(low.getAttribute('aria-pressed')).toBe('true');
        await waitFor(() => expect(urls(calls).some((u) => u.includes('filter%5Bstate%5D=low'))).toBe(true));
        // Pressed again: back to every variant.
        fireEvent.click(low);
        await waitFor(() => expect(router.state.location.search).toBe(''));
        fireEvent.click(screen.getByRole('button', { name: /^Out of stock\s*0\s*—/ }));
        await waitFor(() => expect(router.state.location.search).toBe('?state=out'));
    });

    it('searches name, SKU or barcode on the server, debounced, with the term in the URL', async () => {
        const calls = inventoryApi(inventoryStaff);
        const { router } = renderApp('/inventory?q=blaster');
        await screen.findByRole('table');

        // From the URL: the field shows it and the API got it.
        const box = screen.getByRole('searchbox', { name: 'Search stock' }) as HTMLInputElement;
        expect(box.value).toBe('blaster');
        expect(urls(calls).some((u) => u.includes('/inventory/stock?') && u.includes('filter%5Bsearch%5D=blaster'))).toBe(true);
        expect(await screen.findByText('2 matching variants')).toBeTruthy();

        fireEvent.change(box, { target: { value: 'puzzle' } });
        await waitFor(() => expect(router.state.location.search).toBe('?q=puzzle'));
        await waitFor(() => expect(urls(calls).some((u) => u.includes('filter%5Bsearch%5D=puzzle'))).toBe(true));
    });

    it('commits a scanned barcode on Enter, at once', async () => {
        const calls = inventoryApi(inventoryStaff);
        const { router } = renderApp('/inventory');
        await screen.findByRole('table');

        const box = screen.getByRole('searchbox', { name: 'Search stock' });
        fireEvent.change(box, { target: { value: '2000000000015' } });
        fireEvent.keyDown(box, { key: 'Enter' });
        expect(router.state.location.search).toBe('?q=2000000000015');
        await waitFor(() => expect(urls(calls).some((u) => u.includes('filter%5Bsearch%5D=2000000000015'))).toBe(true));
    });

    it('focuses the search on "/" and says when nothing matches', async () => {
        inventoryApi(inventoryStaff, (url) => (url.includes('filter%5Bsearch%5D=zzz') ? { status: 200, body: { data: [], links: { next: null }, meta: { next_cursor: null } } } : undefined));
        const { router } = renderApp('/inventory?q=zzz');

        expect(await screen.findByText('Nothing matches “zzz”')).toBeTruthy();
        fireEvent.keyDown(document.body, { key: '/' });
        expect(document.activeElement).toBe(screen.getByRole('searchbox', { name: 'Search stock' }));

        fireEvent.click(screen.getByRole('button', { name: 'Clear search' }));
        await waitFor(() => expect(router.state.location.search).toBe(''));
        expect(await screen.findByRole('table')).toBeTruthy();
    });

    it('gathers row actions in one menu: Adjust stock, Create PO for a short row, View ledger', async () => {
        inventoryApi({ ...inventoryStaff, permissions: [...inventoryStaff.permissions, 'purchasing.create'] });
        renderApp('/inventory');
        await screen.findByRole('table');

        let menu = await openRowMenu('Wooden Alphabet Puzzle');
        expect(within(menu).getAllByRole('menuitem').map((item) => item.textContent)).toEqual(['Adjust stock', 'Create PO', 'View ledger']);
        expect(within(menu).getByRole('menuitem', { name: 'Create PO' }).getAttribute('href')).toBe('/purchasing/new?sku=TMB-WAP-BN');
        expect(within(menu).getByRole('menuitem', { name: 'View ledger' }).getAttribute('href')).toBe('/inventory/movements?variant=11&sku=TMB-WAP-BN');
        // The first item has focus; Escape closes and returns focus to the ⋯ button.
        expect(document.activeElement?.textContent).toBe('Adjust stock');
        fireEvent.keyDown(menu, { key: 'ArrowDown' });
        expect(document.activeElement?.textContent).toBe('Create PO');
        fireEvent.keyDown(menu, { key: 'Escape' });
        expect(screen.queryByRole('menu')).toBeNull();
        expect(document.activeElement).toBe(screen.getByRole('button', { name: 'Actions for Wooden Alphabet Puzzle' }));

        // A healthy row: no Create PO.
        menu = await openRowMenu('Foam Blaster X2');
        expect(within(menu).getAllByRole('menuitem').map((item) => item.textContent)).toEqual(['Adjust stock', 'View ledger']);
    });

    it('adjusts a row from its menu, then refreshes the row and the stats in place', async () => {
        const calls = inventoryApi(inventoryStaff);
        renderApp('/inventory');
        await screen.findByRole('table');
        const stockReads = () => urls(calls).filter((u) => u.includes('/inventory/stock')).length;
        const summaryReads = () => urls(calls).filter((u) => u.includes('/inventory/summary')).length;

        const menu = await openRowMenu('Wooden Alphabet Puzzle');
        fireEvent.click(within(menu).getByRole('menuitem', { name: 'Adjust stock' }));
        const dialog = await screen.findByRole('dialog');
        // The variant is already picked: no search step.
        expect(within(dialog).getByText('On hand 8 · reserved 2 · available 6')).toBeTruthy();
        const [stockBefore, summaryBefore] = [stockReads(), summaryReads()];

        fireEvent.change(within(dialog).getByLabelText(/Change in units/), { target: { value: '2' } });
        fireEvent.change(within(dialog).getByLabelText(/Note/), { target: { value: 'Crushed box' } });
        fireEvent.click(within(dialog).getByRole('button', { name: 'Record adjustment' }));

        expect(await screen.findByText('Adjustment ADJ-2026-0012 recorded')).toBeTruthy();
        expect(sentBody(calls)!.body).toMatchObject({ lines: [{ variant_id: 11, quantity: -2 }] });
        await waitFor(() => expect(stockReads()).toBeGreaterThan(stockBefore));
        await waitFor(() => expect(summaryReads()).toBeGreaterThan(summaryBefore));
    });

    it('rows do not expand: branch stock is in the chips, history behind View ledger (owner, 2026-11-16)', async () => {
        inventoryApi(inventoryStaff, (url) => {
            if (url.includes('/inventory/summary')) return { status: 200, body: branchSummary };
            if (url.includes('/inventory/stock')) return { status: 200, body: { data: branchRows, links: { next: null }, meta: { next_cursor: null } } };
            return undefined;
        });
        renderApp('/inventory');
        const table = await screen.findByRole('table');

        expect(screen.queryByRole('button', { name: /^Details for / })).toBeNull();
        fireEvent.click(within(table).getAllByText('Wooden Alphabet Puzzle')[0]!);
        expect(screen.queryByRole('region', { name: 'Stock by branch' })).toBeNull();
        expect(document.querySelector('[aria-expanded="true"]')).toBeNull();
    });

    it('lays out one card per branch; selecting one scopes the page through the URL', async () => {
        const calls = inventoryApi(inventoryStaff, (url) => {
            if (url.includes('/inventory/summary')) return { status: 200, body: branchSummary };
            if (url.includes('/inventory/stock')) return { status: 200, body: { data: branchRows, links: { next: null }, meta: { next_cursor: null } } };
            return undefined;
        });
        const { router } = renderApp('/inventory');
        const table = await screen.findByRole('table');

        expect(screen.getByText('Across 3 branches · stock changes only through movements (sale, purchase, adjustment, transfer)')).toBeTruthy();
        expect(screen.getByRole('button', { name: /Chattogram · Agrabad\s*Retail branch · POS \+ pickup\s*74 units/ })).toBeTruthy();
        expect(screen.getByRole('button', { name: /Sylhet pickup point/ }).getAttribute('aria-pressed')).toBe('false');
        expect(within(table).getByRole('columnheader', { name: 'Other branches' })).toBeTruthy();
        // Available elsewhere, server figures, with the branch's full name: CTG holds 1 but it is all reserved.
        expect(within(table).getByText('Chattogram · Agrabad: 4 available')).toBeTruthy();
        expect(within(table).getByText('Chattogram · Agrabad: 0 available')).toBeTruthy();
        expect(within(table).getAllByTitle('Sylhet pickup point: 0 available')).toHaveLength(2);
        // Without inventory.transfer there is no transfer action at all (inventoryOps.test covers it with the permission).
        const menu = await openRowMenu('Wooden Alphabet Puzzle');
        expect(within(menu).queryByRole('menuitem', { name: /Transfer/ })).toBeNull();
        fireEvent.keyDown(menu, { key: 'Escape' });
        expect(screen.queryByRole('button', { name: /Transfer stock/ })).toBeNull();

        fireEvent.click(screen.getByRole('button', { name: /Chattogram · Agrabad/ }));
        await waitFor(() => expect(router.state.location.search).toBe('?location=2'));
        await waitFor(() => expect(urls(calls).some((u) => u.includes('/inventory/summary?filter%5Blocation_id%5D=2'))).toBe(true));
        await waitFor(() => expect(urls(calls).some((u) => u.includes('/inventory/stock?filter%5Blocation_id%5D=2'))).toBe(true));
    });

    it('opens on the branch named in the URL; the default branch clears it', async () => {
        const calls = inventoryApi(inventoryStaff, (url) => {
            if (url.includes('/inventory/summary')) return { status: 200, body: url.includes('location_id%5D=3') ? { ...branchSummary, location_id: 3 } : branchSummary };
            if (url.includes('/inventory/stock')) return { status: 200, body: { data: branchRows, links: { next: null }, meta: { next_cursor: null } } };
            return undefined;
        });
        const { router } = renderApp('/inventory?location=3&state=low');
        await screen.findByRole('table');

        expect(urls(calls).some((u) => u.includes('/inventory/stock?filter%5Blocation_id%5D=3&filter%5Bstate%5D=low'))).toBe(true);
        await waitFor(() => expect(screen.getByRole('button', { name: /Sylhet pickup point/ }).getAttribute('aria-pressed')).toBe('true'));

        fireEvent.click(screen.getByRole('button', { name: /Dhaka HQ · Mirpur/ }));
        await waitFor(() => expect(router.state.location.search).toBe('?state=low'));
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
        const menu = await openRowMenu('Wooden Alphabet Puzzle');
        expect(within(menu).getAllByRole('menuitem').map((item) => item.textContent)).toEqual(['View ledger']);
    });

    it('refreshes on demand and says when the figures were read', async () => {
        const calls = inventoryApi(inventoryStaff);
        renderApp('/inventory');
        await screen.findByRole('table');
        expect(screen.getByText(/^Updated \d{1,2}:\d{2} [ap]m$/)).toBeTruthy();

        const before = urls(calls).filter((u) => u.includes('/inventory/stock')).length;
        fireEvent.click(screen.getByRole('button', { name: 'Refresh' }));
        await waitFor(() => expect(urls(calls).filter((u) => u.includes('/inventory/stock')).length).toBeGreaterThan(before));
    });

    it('renders in Bangla with Western digits', async () => {
        inventoryApi(inventoryStaff);
        renderApp('/inventory', { locale: 'bn' });
        const table = await screen.findByRole('table');

        expect(within(table).getByText('কাঠের বর্ণমালা পাজল')).toBeTruthy();
        expect(within(table).getByText('কম স্টক')).toBeTruthy();
        expect(screen.getByRole('button', { name: /^কম স্টক\s*1$/ })).toBeTruthy();
        expect(screen.getByRole('searchbox', { name: 'স্টক খুঁজুন' })).toBeTruthy();
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

    it('formats money to two decimals on the string, and signs, without computing', () => {
        expect(formatMoney('1050.4000')).toBe('1,050.40');
        expect(formatMoney('136741.00')).toBe('136,741.00');
        expect(formatMoney('2937.7371')).toBe('2,937.74');
        expect(formatMoney('2244.6306')).toBe('2,244.63');
        expect(formatMoney('999.995')).toBe('1,000.00');
        expect(formatMoney('0.0049')).toBe('0.00');
        expect(formatMoney('-0.004')).toBe('0.00');
        expect(formatMoney('-100.5')).toBe('-100.50');
        expect(formatMoney('1250')).toBe('1,250.00');
        expect(exactMoney('2937.7371')).toBe('2,937.7371');
        expect(exactMoney('1015.0000')).toBe('1,015.00');
        expect(signed(3)).toBe('+3');
        expect(signed(-20)).toBe('−20');
        expect(signed(0)).toBe('0');
    });
});

describe('the sidebar working branch', () => {
    const multiStaff = {
        ...inventoryStaff,
        branches: {
            multi_branch: true,
            locations: [
                { id: 1, name: 'Dhaka HQ · Mirpur', name_bn: null, area: 'Mirpur', type: 'warehouse' as const, is_default: true },
                { id: 2, name: 'Chattogram · Agrabad', name_bn: null, area: 'Agrabad', type: 'retail' as const, is_default: false },
                { id: 3, name: 'Sylhet pickup point', name_bn: null, area: 'Zindabazar', type: 'pickup' as const, is_default: false },
            ],
        },
    };

    it('is the default location of the page and so of a new adjustment', async () => {
        localStorage.setItem('tm-admin-working-branch', '2');
        const calls = inventoryApi(multiStaff, (url) => {
            if (url.includes('/inventory/summary')) return { status: 200, body: url.includes('location_id%5D=2') ? { ...branchSummary, location_id: 2 } : branchSummary };
            if (url.includes('/inventory/stock')) return { status: 200, body: { data: branchRows, links: { next: null }, meta: { next_cursor: null } } };
            return undefined;
        });
        renderApp('/inventory');
        await screen.findByRole('table');

        expect(urls(calls).some((u) => u.includes('/inventory/summary?filter%5Blocation_id%5D=2'))).toBe(true);
        await waitFor(() => expect(screen.getByRole('button', { name: /^Chattogram · Agrabad\s*Retail/ }).getAttribute('aria-pressed')).toBe('true'));

        fireEvent.click(screen.getByRole('button', { name: 'New adjustment' }));
        const dialog = await screen.findByRole('dialog');
        expect((within(dialog).getByLabelText('Location') as HTMLSelectElement).value).toBe('2');
    });

    it('never hides data: choosing the default branch card sets it explicitly in the URL', async () => {
        localStorage.setItem('tm-admin-working-branch', '2');
        inventoryApi(multiStaff, (url) => {
            if (url.includes('/inventory/summary')) return { status: 200, body: url.includes('location_id%5D=2') ? { ...branchSummary, location_id: 2 } : branchSummary };
            if (url.includes('/inventory/stock')) return { status: 200, body: { data: branchRows, links: { next: null }, meta: { next_cursor: null } } };
            return undefined;
        });
        const { router } = renderApp('/inventory');
        await screen.findByRole('table');

        fireEvent.click(screen.getByRole('button', { name: /^Dhaka HQ · Mirpur\s*Main/ }));
        await waitFor(() => expect(router.state.location.search).toBe('?location=1'));
    });
});
