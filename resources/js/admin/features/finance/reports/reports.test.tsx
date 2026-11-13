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

const line = (key: string, amount: string, types: string[]) => ({ kind: 'line' as const, key, types, amount });
const sub = (key: keyof typeof totals) => ({ kind: 'subtotal' as const, key, amount: totals[key] });

/** The server's hand-checked week (tests/Feature/Reports/ProfitAndLossReportTest.php). */
export const statement: PnlStatement = {
    from: '2026-11-02',
    to: '2026-11-08',
    days: 7,
    blocks: [
        { key: 'revenue', number: 1, rows: [line('product_revenue', '9250.00', ['sale_revenue']), line('delivery_revenue', '360.00', ['shipping_revenue']), sub('gross_revenue'), line('discounts', '-100.00', ['discount_given']), sub('net_revenue')] },
        { key: 'goods', number: 2, rows: [line('cogs', '-5800.00', ['cogs']), sub('gross_profit')] },
        {
            key: 'delivery',
            number: 3,
            rows: [
                line('courier_charges', '-540.00', ['courier_charge']),
                line('cod_fees', '-97.00', ['courier_cod_fee']),
                line('return_charges', '-70.00', ['courier_return_charge']),
                line('gateway_fees', '0.00', ['gateway_fee']),
                line('packaging', '-125.00', ['packaging']),
                sub('profit_after_delivery'),
            ],
        },
        { key: 'losses', number: 4, rows: [line('refunds', '-300.00', ['refund']), line('write_offs', '-1280.00', ['inventory_write_off']), sub('profit_after_losses')] },
        {
            key: 'running',
            number: 5,
            rows: [
                line('marketing', '-1400.00', ['marketing']),
                line('salaries', '0.00', ['salary']),
                line('rent', '0.00', ['rent']),
                line('utilities', '0.00', ['utility']),
                line('other_expenses', '-500.00', ['other_expense']),
                line('other_income', '0.00', ['other_income']),
                sub('net_profit'),
            ],
        },
    ],
    totals,
    gross_margin_percent: '39.01',
    net_margin_percent: '-6.33',
    unmapped: [],
    expenses_by_category: [{ category_id: 1, name: 'Facebook ads', name_bn: 'ফেসবুক বিজ্ঞাপন', type: 'marketing', parent_id: null, amount: '-1400.00' }],
    cash: { in: '9153.00', out: '7200.00', net: '1953.00', by_type: [] },
    reconciliation: { orders: '3710.00', order_count: 3, other: '-4312.00', total: '-602.00', reconciled: true },
    open_orders: { count: 4, value: '18000.00' },
    inventory_at_cost: '136741.00',
    comparison: { kind: 'previous_period', from: '2026-10-26', to: '2026-11-01', totals: { ...totals, net_profit: '512.00' }, deltas: { gross_revenue: null, net_revenue: '36.60', gross_profit: null, profit_after_delivery: null, profit_after_losses: null, net_profit: '-217.58' } },
    source: 'ledger',
};

const series: PnlSeries = {
    granularity: 'week',
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

function reportsApi(me: Me = accountant) {
    return mockApi((url) => {
        if (url.endsWith('/auth/me')) return { status: 200, body: me };
        if (url.includes('/finance/reports/pnl/series')) return { status: 200, body: series };
        if (url.includes('/finance/reports/pnl')) return { status: 200, body: statement };
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
    it('lays out the server’s five blocks, subtotals, cash, reconciliation and the not-in-statement footer', async () => {
        reportsApi();
        renderApp('/finance/pnl');

        expect(await screen.findByText('What customers owed us')).toBeTruthy();
        for (const heading of ['What the toys cost us', 'What it cost to deliver and get paid', 'What went wrong', 'What it cost to run the shop']) {
            expect(screen.getByText(heading)).toBeTruthy();
        }
        // True minus on deductions; values exactly as the server sent them.
        expect(screen.getAllByText(text('−৳5,800')).length).toBeGreaterThan(0);
        // Margins to one place, as Figma sets them; the server's gross_revenue subtotal is not drawn (B · 31).
        expect(screen.getByText('Gross profit · 39.0%')).toBeTruthy();
        expect(screen.queryByText('Gross revenue')).toBeNull();
        expect(screen.getAllByText(text('−৳602')).length).toBeGreaterThan(0);
        expect(screen.getByText('The period lost money.')).toBeTruthy();
        // Zero quiet lines are hidden; a zero core line is shown.
        expect(screen.queryByText('Salaries')).toBeNull();
        expect(screen.getByText('Payment gateway fees')).toBeTruthy();
        // Cash kept apart from profit.
        expect(screen.getByText('Cash, not profit')).toBeTruthy();
        expect(screen.getAllByText(text('+৳1,953')).length).toBeGreaterThan(0);
        // "Still with the courier" is the server's COD receivable as at the period's last day.
        expect(await screen.findByText('Still with the courier')).toBeTruthy();
        expect(screen.getAllByText(text('৳787')).length).toBeGreaterThan(0);
        // "Owed back to a customer": the server's refunds approved but unpaid, as at the same day.
        expect(await screen.findByText('Owed back to a customer')).toBeTruthy();
        expect(screen.getAllByText(text('−৳200')).length).toBeGreaterThan(0);
        expect(screen.getByText('Reconciled with the ledger')).toBeTruthy();
        expect(screen.getByText('Open orders not yet delivered: ৳18,000 across 4 orders')).toBeTruthy();
        expect(screen.getByRole('link', { name: 'Stock on hand ৳136,741 at cost — an asset, not a cost' }).getAttribute('href')).toBe('/inventory/valuation');
        // Export offers the server-built Excel and CSV files as plain links.
        expect(screen.getByRole('link', { name: 'Excel (.xlsx)' }).getAttribute('href')).toBe('/api/v1/admin/finance/reports/pnl/export?format=xlsx&locale=en');
        expect(screen.getByRole('link', { name: 'CSV' }).getAttribute('href')).toBe('/api/v1/admin/finance/reports/pnl/export?format=csv&locale=en');
    });

    it('sends the period and comparison to the server from the URL', async () => {
        const calls = reportsApi();
        renderApp('/finance/pnl?preset=custom&from=2026-11-02&to=2026-11-08&compare=previous_year');
        await screen.findByText('What customers owed us');
        expect(urls(calls).some((u) => u.includes('/finance/reports/pnl?filter[from]=2026-11-02&filter[to]=2026-11-08&compare=previous_year'))).toBe(true);

        fireEvent.change(screen.getByLabelText('Compare with'), { target: { value: 'none' } });
        await waitFor(() => expect(urls(calls).some((u) => u.includes('compare=none'))).toBe(true));
    });

    it('renders in Bangla with Western digits', async () => {
        reportsApi();
        renderApp('/finance/pnl', { locale: 'bn' });
        expect(await screen.findByText('গ্রাহকদের কাছে আমাদের পাওনা')).toBeTruthy();
        expect(screen.getAllByText(text('−৳5,800')).length).toBeGreaterThan(0);
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
        expect(within(table).getAllByText(text('−৳702')).length).toBeGreaterThan(0);
        expect(within(table).getByText('Total')).toBeTruthy();

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
        expect(formatAmount('136741.00')).toBe('136,741');
        expect(formatAmount('-1280.50')).toBe('-1,280.5');
    });

    it('computes preset ranges from the Dhaka date', () => {
        expect(presetRange('last7', '2026-11-12')).toEqual({ from: '2026-11-06', to: '2026-11-12' });
        expect(presetRange('lastMonth', '2026-03-12')).toEqual({ from: '2026-02-01', to: '2026-02-28' });
        expect(presetRange('thisYear', '2026-11-12')).toEqual({ from: '2026-01-01', to: '2026-11-12' });
    });
});
