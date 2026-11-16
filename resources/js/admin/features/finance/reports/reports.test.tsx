import { cleanup, fireEvent, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { mockApi, renderApp, viewer } from '@/test/renderApp';
import type { Me } from '@/features/auth/api';
import type { InventoryValuation } from '@/features/reports/api';
import type { PnlSeries, PnlStatement, ProfitabilityReport } from './api';
import { formatAmount, presetRange } from './parts';

afterEach(() => {
    cleanup();
    localStorage.clear();
});

const accountant: Me = {
    ...viewer,
    roles: ['accountant'],
    permissions: ['dashboard.view', 'catalog.view', 'catalog.view_cost', 'inventory.view', 'finance.view', 'finance.export', 'reports.view'],
};

const totals = { gross_revenue: '9610.00', net_revenue: '9510.00', gross_profit: '3710.00', profit_after_delivery: '2878.00', profit_after_losses: '1298.00', net_profit: '-602.00' };

const line = (key: string, amount: string | null, types: string[], percent: string | null, occurrences: number | null = 1) => ({ kind: 'line' as const, key, types, amount, percent_of_revenue: percent, occurrences });
const sub = (key: keyof typeof totals, percent: string | null) => ({ kind: 'subtotal' as const, key, amount: totals[key], percent_of_revenue: percent });

/** The server's hand-checked week (tests/Feature/Reports/ProfitAndLossReportTest.php), premium fields included. */
export const statement: PnlStatement = {
    from: '2026-11-02',
    to: '2026-11-08',
    days: 7,
    generated_at: '2026-11-16T08:30:00+00:00',
    item_costs_visible: true,
    blocks: [
        {
            key: 'revenue',
            number: 1,
            total: '9510.00',
            percent_of_revenue: '100.00',
            rows: [line('product_revenue', '9250.00', ['sale_revenue'], '97.27', 3), line('delivery_revenue', '360.00', ['shipping_revenue'], '3.79', 2), sub('gross_revenue', '101.05'), line('discounts', '-100.00', ['discount_given'], '-1.05'), sub('net_revenue', '100.00')],
        },
        { key: 'goods', number: 2, total: '-5800.00', percent_of_revenue: '-60.99', rows: [line('cogs', '-5800.00', ['cogs'], '-60.99', 3), sub('gross_profit', '39.01')] },
        {
            key: 'delivery',
            number: 3,
            total: '-832.00',
            percent_of_revenue: '-8.75',
            rows: [
                line('courier_charges', '-540.00', ['courier_charge'], '-5.68', 3),
                line('cod_fees', '-97.00', ['courier_cod_fee'], '-1.02', 2),
                line('return_charges', '-70.00', ['courier_return_charge'], '-0.74'),
                line('gateway_fees', '0.00', ['gateway_fee'], '0.00', 0),
                line('packaging', '-125.00', ['packaging'], '-1.31', 3),
                sub('profit_after_delivery', '30.26'),
            ],
        },
        { key: 'losses', number: 4, total: '-1580.00', percent_of_revenue: '-16.61', rows: [line('refunds', '-300.00', ['refund'], '-3.15'), line('write_offs', '-1280.00', ['inventory_write_off'], '-13.46'), sub('profit_after_losses', '13.65')] },
        {
            key: 'running',
            number: 5,
            total: '-1900.00',
            percent_of_revenue: '-19.98',
            rows: [
                line('marketing', '-1400.00', ['marketing'], '-14.72'),
                line('salaries', '0.00', ['salary'], '0.00', 0),
                line('rent', '0.00', ['rent'], '0.00', 0),
                line('utilities', '0.00', ['utility'], '0.00', 0),
                line('other_expenses', '-500.00', ['other_expense'], '-5.26'),
                line('other_income', '0.00', ['other_income'], '0.00', 0),
                sub('net_profit', '-6.33'),
            ],
        },
    ],
    biggest_costs: [
        { key: 'marketing', block: 'running', cost: '1400.00', relative_percent: '100.00' },
        { key: 'write_offs', block: 'losses', cost: '1280.00', relative_percent: '91.43' },
        { key: 'courier_charges', block: 'delivery', cost: '540.00', relative_percent: '38.57' },
        { key: 'other_expenses', block: 'running', cost: '500.00', relative_percent: '35.71' },
    ],
    totals,
    gross_margin_percent: '39.01',
    net_margin_percent: '-6.33',
    unmapped: [],
    expenses_by_category: [{ category_id: 1, name: 'Facebook ads', name_bn: 'ফেসবুক বিজ্ঞাপন', type: 'marketing', parent_id: null, amount: '-1400.00' }],
    cash: { in: '9153.00', out: '7200.00', net: '1953.00', by_type: [{ type: 'cod_received', in: '9153.00', out: '0.00' }] },
    reconciliation: { orders: '3710.00', order_count: 3, other: '-4312.00', total: '-602.00', difference: '0.00', reconciled: true },
    open_orders: {
        count: 4,
        value: '18000.00',
        by_status: [
            { status: 'pending', count: 2, value: '8170.00' },
            { status: 'shipped', count: 2, value: '9830.00' },
        ],
    },
    inventory_at_cost: '136741.00',
    comparison: { kind: 'previous_period', from: '2026-10-26', to: '2026-11-01', totals: { ...totals, net_profit: '512.00' }, deltas: { gross_revenue: null, net_revenue: '36.60', gross_profit: null, profit_after_delivery: null, profit_after_losses: null, net_profit: '-217.58' } },
    source: 'ledger',
};

/**
 * The same week for a reader without catalog.view_cost (owner decision 2026-11-16): the COGS
 * line, block 2's total, gross margin and every subtotal it could be backed out of withheld.
 */
const withheld = (row: PnlStatement['blocks'][number]['rows'][number]) =>
    row.kind === 'subtotal' && ['gross_profit', 'profit_after_delivery', 'profit_after_losses'].includes(row.key) ? { ...row, amount: null, percent_of_revenue: null } : row;
const lockedStatement: PnlStatement = {
    ...statement,
    item_costs_visible: false,
    gross_margin_percent: null,
    totals: { ...totals, gross_profit: null, profit_after_delivery: null, profit_after_losses: null },
    comparison: null,
    blocks: statement.blocks.map((b) =>
        b.key === 'goods' ? { ...b, total: null, percent_of_revenue: null, rows: [line('cogs', null, ['cogs'], null, null), withheld(sub('gross_profit', null))] } : { ...b, rows: b.rows.map(withheld) },
    ),
};

/** The statement and the order-by-order rebuild disagree by ৳120 (state B). */
const mismatchStatement: PnlStatement = {
    ...statement,
    reconciliation: { ...statement.reconciliation, total: '-722.00', other: '-4432.00', difference: '120.00', reconciled: false },
};

const series: PnlSeries = {
    granularity: 'week',
    item_costs_visible: true,
    from: '2026-11-02',
    to: '2026-11-15',
    source: 'ledger',
    buckets: [
        { key: '2026-11-02', from: '2026-11-02', to: '2026-11-06', days: 5, totals: { ...totals, net_profit: '100.00' }, lines: {}, net_margin_percent: '1.05' },
        { key: '2026-11-07', from: '2026-11-07', to: '2026-11-13', days: 7, totals: { ...totals, net_profit: '-702.00' }, lines: {}, net_margin_percent: '-7.38' },
    ],
    totals,
};

const profitability: ProfitabilityReport = {
    by: 'product',
    from: '2026-11-02',
    to: '2026-11-08',
    sort: '-gross_profit',
    data: [
        { name: 'Wooden Puzzle', name_bn: 'কাঠের পাজল', product_id: 1, sku: 'TMB-WP-01', category_id: 2, category_name: 'Puzzles', category_name_bn: 'পাজল', orders: 2, units: 4, units_sold: 5, units_returned: 1, gross_revenue: '6000.00', reversals: '1500.00', net_revenue: '4500.00', cogs: '3221.00', gross_profit: '1279.00', margin_percent: '28.42' },
    ],
    totals: { orders: 3, rows: 1, units: 4, units_sold: 5, units_returned: 1, gross_revenue: '6000.00', reversals: '1500.00', net_revenue: '4500.00', cogs: '3221.00', gross_profit: '1279.00', margin_percent: '28.42' },
    truncated: false,
};

const valuation: InventoryValuation = {
    as_at: null,
    sort: '-value',
    data: [
        { variant_id: 11, product_id: 1, sku: 'TMB-WP-01', name: 'Wooden Puzzle', name_bn: 'কাঠের পাজল', variant_name: null, category_id: 2, category_name: 'Puzzles', category_name_bn: 'পাজল', location_id: 1, location_code: 'main', location_name: 'Dhaka HQ', location_name_bn: 'ঢাকা', on_hand: 16, unit_cost: '355.0000', value: '5680.00', estimated: false },
    ],
    totals: { on_hand: 16, value: '5680.00', rows: 1, estimated_rows: 0 },
    by_location: [{ location_id: 1, code: 'main', name: 'Dhaka HQ', name_bn: 'ঢাকা', on_hand: 16, value: '5680.00', variants: 1 }],
    by_category: [{ category_id: 2, name: 'Puzzles', name_bn: 'পাজল', on_hand: 16, value: '5680.00', variants: 1 }],
    truncated: false,
};

function reportsApi(me: Me = accountant, pnl: PnlStatement = statement) {
    return mockApi((url) => {
        if (url.endsWith('/auth/me')) return { status: 200, body: me };
        if (url.includes('/finance/reports/pnl/series')) return { status: 200, body: series };
        if (url.includes('/finance/reports/pnl')) return { status: 200, body: pnl };
        if (url.includes('/finance/reports/profitability')) return { status: 200, body: profitability };
        if (url.includes('/reports/inventory-valuation')) return { status: 200, body: valuation };
        if (url.includes('/finance/receivables?as_of=2026-11-08'))
            return { status: 200, body: { as_of: '2026-11-08', recognised: '9540.00', received: '8753.00', outstanding: '787.00', open_orders: 1, aging: { '0_7': '787.00', '8_15': '0.00', '16_30': '0.00', '31_plus': '0.00' } } };
        if (url.includes('/finance/reports/refunds-owed?as_of=2026-11-08'))
            return { status: 200, body: { as_of: '2026-11-08', total: '200.00', count: 1, oldest_approved_at: '2026-11-07T04:00:00Z', refunds: [] } };
        if (url.includes('/inventory/low-stock-count')) return { status: 200, body: { location_id: 1, count: 0 } };
        return { status: 404 };
    });
}

const urls = (calls: ReturnType<typeof mockApi>) => calls.mock.calls.map(([url]) => decodeURIComponent(String(url)));
const text = (value: string) => (_: string, el: Element | null) => el?.tagName === 'SPAN' && el.textContent === value;

describe('profit & loss', () => {
    it('lays out the KPI row, the journey, the five blocks with shares, and the rail from the server’s figures', async () => {
        reportsApi();
        renderApp('/finance/pnl');

        expect(await screen.findByText('What customers owed us')).toBeTruthy();
        for (const heading of ['What the toys cost us', 'What it cost to deliver and get paid', 'What went wrong', 'What it cost to run the shop']) {
            expect(screen.getByRole('heading', { name: heading })).toBeTruthy();
        }
        expect(screen.getByText('Step 2 of 5 · Cost of goods')).toBeTruthy();
        // Header chip from the server's reconciliation.
        expect(screen.getByText('Ledger reconciled')).toBeTruthy();

        // KPI row: server figures, server counts, server margins (display-rounded to one place).
        const kpis = screen.getByRole('group', { name: 'Headline figures' });
        expect(within(kpis).getByText('Net profit')).toBeTruthy();
        expect(within(kpis).getByText('−6.3% margin · down 217.6% on ৳512 the 7 days before')).toBeTruthy();
        expect(within(kpis).getByText('Delivered orders only · 3 orders · after ৳100 discounts')).toBeTruthy();
        expect(within(kpis).getByText('39.0% gross margin · after the cost of the toys')).toBeTruthy();
        expect(within(kpis).getAllByText(text('৳1,580')).length).toBeGreaterThan(0); // losses block total, as a cost

        // Each line's share of revenue and its occurrence caption.
        expect(screen.getByText('97.3%')).toBeTruthy();
        expect(screen.getAllByText('−61.0%').length).toBeGreaterThan(0);
        expect(screen.getByText('3 orders delivered')).toBeTruthy();
        expect(screen.getByText('3 parcels, including any that came back')).toBeTruthy();
        expect(screen.getByText('= Gross profit')).toBeTruthy();
        expect(screen.queryByText('= Gross revenue')).toBeNull();
        expect(screen.getAllByText(text('−৳5,800')).length).toBeGreaterThan(0);
        // Zero quiet lines are hidden; a zero core line is shown.
        expect(screen.queryByText('Salaries')).toBeNull();
        expect(screen.getAllByText('Payment gateway fees').length).toBeGreaterThan(0);
        // A loss closes the statement in the danger tone with the server's margin.
        expect(screen.getByText('= Net profit')).toBeTruthy();
        expect(screen.getAllByText('−6.3% margin').length).toBeGreaterThan(0);
        expect(screen.getAllByText(text('−৳602')).length).toBeGreaterThan(0);

        // Drill-down: each line opens the ledger for its type and the exact period.
        expect(screen.getByRole('link', { name: 'Open Cost of goods sold in the ledger' }).getAttribute('href')).toBe('/finance/ledger?type=cogs&from=2026-11-02&to=2026-11-08');
        // ⓘ explains the line.
        expect(screen.getByRole('tooltip', { name: /What you paid your supplier/ })).toBeTruthy();

        // The journey: seven steps for screen readers, each with the server's share.
        const journey = screen.getByRole('table', { name: 'From revenue to net profit, step by step' });
        expect(within(journey).getByText('Cost of the toys')).toBeTruthy();
        expect(within(journey).getByText('−৳832')).toBeTruthy();
        expect(within(journey).getByText('−8.8% of revenue')).toBeTruthy();
        expect(screen.getByText('Out of every ৳100, you lost ৳6')).toBeTruthy();

        // Cash kept apart from profit.
        expect(screen.getByRole('heading', { name: 'Where the money actually is' })).toBeTruthy();
        expect(screen.getAllByText(text('+৳1,953')).length).toBeGreaterThan(0);
        expect(await screen.findByText('Still with the courier')).toBeTruthy();
        expect(screen.getAllByText(text('৳787')).length).toBeGreaterThan(0);
        expect(await screen.findByText('Owed back to a customer')).toBeTruthy();
        expect(screen.getByText('1 refunds approved, not yet paid')).toBeTruthy();
        expect(screen.getAllByText(text('−৳200')).length).toBeGreaterThan(0);
        // Tie-up.
        expect(screen.getByRole('heading', { name: 'Does it tie up?' })).toBeTruthy();
        expect(screen.getByText('3 orders with ledger activity')).toBeTruthy();
        expect(screen.getByText(/^Reconciled with the ledger · /)).toBeTruthy();
        // Biggest costs, in the server's order, linking to the ledger.
        expect(screen.getByRole('link', { name: /^Advertising/ }).getAttribute('href')).toBe('/finance/ledger?type=marketing&from=2026-11-02&to=2026-11-08');
        // Not in this statement.
        expect(screen.getByText('2 pending ৳8,170 · 2 shipped ৳9,830')).toBeTruthy();
        expect(screen.getByRole('link', { name: 'Stock on hand at cost' }).getAttribute('href')).toBe('/inventory/valuation');
        // Export offers the server-built Excel and CSV files as plain links.
        expect(screen.getByRole('link', { name: 'Excel (.xlsx)' }).getAttribute('href')).toBe('/api/v1/admin/finance/reports/pnl/export?format=xlsx&locale=en');
        expect(screen.getByRole('link', { name: 'CSV' }).getAttribute('href')).toBe('/api/v1/admin/finance/reports/pnl/export?format=csv&locale=en');
    });

    it('folds a block to its subtotal and opens it again', async () => {
        reportsApi();
        renderApp('/finance/pnl');
        const toggle = await screen.findByRole('button', { name: 'Show or hide the lines of step 3' });
        expect(toggle.getAttribute('aria-expanded')).toBe('true');
        fireEvent.click(toggle);
        expect(toggle.getAttribute('aria-expanded')).toBe('false');
        expect(screen.getByText('5 lines inside · tap to open')).toBeTruthy();
        expect(document.getElementById(toggle.getAttribute('aria-controls') ?? '')?.hidden).toBe(true);
        fireEvent.click(toggle);
        expect(screen.getByText('What is left after step 3')).toBeTruthy();
    });

    it('withholds cost of goods and every figure it could be backed out of without catalog.view_cost (state A)', async () => {
        reportsApi({ ...accountant, permissions: accountant.permissions.filter((p) => p !== 'catalog.view_cost') }, lockedStatement);
        renderApp('/finance/pnl');
        expect(await screen.findByText('Item costs are restricted to accountants and the owner')).toBeTruthy();
        expect(screen.queryByText('Cost of goods sold')).toBeNull();
        expect(screen.queryByText(text('−৳5,800'))).toBeNull();
        // (৳3,710 still appears once: the tie-up's order-by-order sum, which in this fixture equals gross profit.)
        expect(within(screen.getByRole('group', { name: 'Headline figures' })).queryAllByText(text('৳3,710'))).toHaveLength(0);
        expect(screen.queryByText(text('৳2,878'))).toBeNull();
        expect(screen.getByText('Restricted · needs permission to see item costs')).toBeTruthy();
        // Gross profit, profit after delivery and after losses are drawn as restricted; net profit stays.
        expect(screen.getAllByText('Restricted').length).toBeGreaterThanOrEqual(4);
        expect(screen.getAllByText(text('−৳602')).length).toBeGreaterThan(0);
        // No waterfall can be placed honestly: the step list is drawn instead, cost steps restricted.
        const journey = screen.getByRole('table', { name: 'From revenue to net profit, step by step' });
        expect(within(journey).getAllByText('Restricted').length).toBe(2);
        expect(screen.queryByText('The toys')).toBeNull();
    });

    it('drops the withheld subtotal columns from the daily reports without catalog.view_cost', async () => {
        const restricted: PnlSeries = {
            ...series,
            item_costs_visible: false,
            buckets: series.buckets.map((b) => ({ ...b, totals: { ...b.totals, gross_profit: null, profit_after_delivery: null, profit_after_losses: null } })),
            totals: { ...totals, gross_profit: null, profit_after_delivery: null, profit_after_losses: null },
        };
        mockApi((url) => {
            if (url.endsWith('/auth/me')) return { status: 200, body: { ...accountant, permissions: accountant.permissions.filter((p) => p !== 'catalog.view_cost') } };
            if (url.includes('/finance/reports/pnl/series')) return { status: 200, body: restricted };
            if (url.includes('/inventory/low-stock-count')) return { status: 200, body: { location_id: 1, count: 0 } };
            return { status: 404 };
        });
        renderApp('/finance/reports?group=week');
        const table = await screen.findByRole('table');
        expect(within(table).getByText('Net sales')).toBeTruthy();
        expect(within(table).queryByText('Gross profit')).toBeNull();
        expect(within(table).queryByText('After delivery')).toBeNull();
        expect(within(table).getByText('Net profit')).toBeTruthy();
    });

    it('turns the header chip and tie-up card red and links to the ledger when it does not tie up (state B)', async () => {
        reportsApi(accountant, mismatchStatement);
        renderApp('/finance/pnl');
        expect(await screen.findByText('Ledger mismatch · ৳120')).toBeTruthy();
        expect(screen.getByRole('heading', { name: 'It does not tie up' })).toBeTruthy();
        expect(screen.getByText('The ledger, order by order, gives −৳722. The statement shows −৳602. Something wrote one without the other — usually an edit that skipped an Action.')).toBeTruthy();
        expect(screen.getByText('৳120 difference')).toBeTruthy();
        expect(screen.getByRole('link', { name: 'Open the ledger for this period' }).getAttribute('href')).toBe('/finance/ledger?from=2026-11-02&to=2026-11-08&kind=recognised');
    });

    it('sends the period and comparison to the server from the URL', async () => {
        const calls = reportsApi();
        renderApp('/finance/pnl?preset=custom&from=2026-11-02&to=2026-11-08&compare=previous_year');
        await screen.findByText('What customers owed us');
        expect(urls(calls).some((u) => u.includes('/finance/reports/pnl?filter[from]=2026-11-02&filter[to]=2026-11-08&compare=previous_year'))).toBe(true);

        fireEvent.change(screen.getByLabelText('Compare with'), { target: { value: 'none' } });
        await waitFor(() => expect(urls(calls).some((u) => u.includes('compare=none'))).toBe(true));

        fireEvent.click(screen.getByRole('button', { name: 'This month' }));
        await waitFor(() => expect(urls(calls).some((u) => /filter\[from\]=\d{4}-\d{2}-01/.test(u))).toBe(true));
    });

    it('renders in Bangla with Western digits', async () => {
        reportsApi();
        renderApp('/finance/pnl', { locale: 'bn' });
        expect(await screen.findByText('গ্রাহকদের কাছে আমাদের পাওনা')).toBeTruthy();
        expect(screen.getAllByText(text('−৳5,800')).length).toBeGreaterThan(0);
        expect(screen.getByText('3টি অর্ডার ডেলিভারি হয়েছে')).toBeTruthy();
        // The file's labels and sheet name follow the admin's language.
        expect(screen.getByText('এক্সপোর্ট')).toBeTruthy();
        expect(screen.getByRole('link', { name: 'এক্সেল (.xlsx)' }).getAttribute('href')).toBe('/api/v1/admin/finance/reports/pnl/export?format=xlsx&locale=bn');
    });
});

describe('daily reports', () => {
    it('shows one row per bucket with the server totals and groups through the URL', async () => {
        const calls = reportsApi();
        renderApp('/finance/reports?group=week');
        const table = await screen.findByRole('table');
        expect(within(table).getByText('02 Nov 2026 – 06 Nov 2026')).toBeTruthy();
        expect(within(table).getAllByText(text('−৳702.00')).length).toBeGreaterThan(0);
        expect(within(table).getByText('Total · 02 Nov 2026 – 15 Nov 2026')).toBeTruthy();
        // Newest first (Figma B · 32 Daily reports 427:73672): the server sends the buckets oldest first.
        const rows = within(table).getAllByRole('rowheader').map((th) => th.textContent);
        expect(rows.indexOf('07 Nov 2026 – 13 Nov 2026')).toBeLessThan(rows.indexOf('02 Nov 2026 – 06 Nov 2026'));

        fireEvent.click(screen.getByRole('button', { name: 'Month' }));
        await waitFor(() => expect(urls(calls).some((u) => u.includes('group=month'))).toBe(true));
        expect(screen.getByRole('link', { name: 'Excel (.xlsx)' }).getAttribute('href')).toBe('/api/v1/admin/finance/reports/pnl/series/export?group=month&format=xlsx&locale=en');
        expect(screen.getByRole('link', { name: 'CSV' }).getAttribute('href')).toBe('/api/v1/admin/finance/reports/pnl/series/export?group=month&format=csv&locale=en');
    });
});

describe('profitability', () => {
    it('lists the server rows and sorts on the server', async () => {
        const calls = reportsApi();
        renderApp('/finance/profitability');
        const table = await screen.findByRole('table');
        expect(within(table).getByText('Wooden Puzzle')).toBeTruthy();
        expect(within(table).getAllByText('28.42%').length).toBe(2);

        fireEvent.click(within(table).getByRole('button', { name: 'Sort by Margin' }));
        await waitFor(() => expect(urls(calls).some((u) => u.includes('sort=-margin_percent'))).toBe(true));

        fireEvent.click(screen.getByRole('button', { name: 'Recommendations' }));
        await waitFor(() => expect(urls(calls).some((u) => u.includes('by=source'))).toBe(true));
        const xlsx = decodeURIComponent(screen.getByRole('link', { name: 'Excel (.xlsx)' }).getAttribute('href') ?? '');
        expect(xlsx).toContain('/api/v1/admin/finance/reports/profitability/export?by=source&sort=-margin_percent');
        expect(xlsx).toContain('&format=xlsx&locale=en');
        expect(screen.getByRole('link', { name: 'CSV' }).getAttribute('href')).toContain('format=csv');
    });
});

describe('inventory valuation', () => {
    it('shows the total, location card, categories and rows; as-at goes to the server', async () => {
        const calls = reportsApi();
        renderApp('/inventory/valuation');
        const table = await screen.findByRole('table');
        expect(within(table).getByText('Wooden Puzzle')).toBeTruthy();
        expect(screen.getByText('Total value at cost')).toBeTruthy();
        expect(screen.getByRole('button', { name: /Dhaka HQ/ })).toBeTruthy();

        fireEvent.change(screen.getByLabelText('As at'), { target: { value: '2026-11-06' } });
        await waitFor(() => expect(urls(calls).some((u) => u.includes('filter[as_at]=2026-11-06'))).toBe(true));
        expect(decodeURIComponent(screen.getByRole('link', { name: 'Excel (.xlsx)' }).getAttribute('href') ?? '')).toBe(
            '/api/v1/admin/reports/inventory-valuation/export?filter[as_at]=2026-11-06&sort=-value&format=xlsx&locale=en',
        );
        expect(screen.getByRole('link', { name: 'CSV' }).getAttribute('href')).toContain('format=csv&locale=en');
    });
});

describe('helpers', () => {
    it('groups decimal strings without parsing money', () => {
        expect(formatAmount('136741.00')).toBe('1,36,741');
        expect(formatAmount('-1280.50')).toBe('−1,280.50');
        expect(formatAmount('8957.70')).toBe('8,957.70');
    });

    it('computes preset ranges from the Dhaka date', () => {
        expect(presetRange('last7', '2026-11-12')).toEqual({ from: '2026-11-06', to: '2026-11-12' });
        expect(presetRange('lastMonth', '2026-03-12')).toEqual({ from: '2026-02-01', to: '2026-02-28' });
        expect(presetRange('thisYear', '2026-11-12')).toEqual({ from: '2026-01-01', to: '2026-11-12' });
    });
});
