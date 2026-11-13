import { cleanup, fireEvent, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { mockApi, renderApp, viewer } from '@/test/renderApp';
import type { Me } from '@/features/auth/api';
import type { SalesAnalytics } from './api';

afterEach(() => {
    cleanup();
    localStorage.clear();
});

const manager: Me = { ...viewer, roles: ['manager'], permissions: ['dashboard.view', 'reports.view', 'reports.export', 'shipments.view', 'finance.view', 'catalog.view_cost'] };
const reader: Me = { ...viewer, permissions: ['dashboard.view', 'reports.view', 'shipments.view'] };

const placed = (net: string, orders: number) => ({ orders: orders + 1, value: '7630.00', cancelled_orders: 1, cancelled_value: '570.00', net_orders: orders, net_value: net, units: 7, aov: '1412.00', cancel_rate: '16.67' });
const summary = (profit: boolean): SalesAnalytics['summary'] => ({
    placed: placed('7060.00', 5),
    delivered: { orders: 3, value: '4710.00', aov: '1570.00' },
    recognised: { product_revenue: '4600.00', delivery_revenue: '210.00', gross_revenue: '4810.00', discounts: '-100.00', net_revenue: '4710.00', cogs: profit ? '-2400.00' : null, gross_profit: profit ? '2310.00' : null, gross_margin_percent: profit ? '49.04' : null },
    customers: { customers: 3, returning: 2, new: 1, repeat_rate: '66.67' },
});
const outcomes = { delivered: 2, shipped: 0, open: 0, cancelled: 0, returned: 0 };

/** The server's hand-checked November (tests/Feature/Reporting/SalesAnalyticsTest.php). */
function report(profit = true): SalesAnalytics {
    return {
        range: { preset: 'custom', from: '2026-11-01', to: '2026-11-30', days: 30 },
        comparison: { preset: 'custom', from: '2026-10-02', to: '2026-10-31', days: 30 },
        granularity: 'week',
        profit_visible: profit,
        source: 'ledger',
        summary: summary(profit),
        previous: { ...summary(profit), placed: placed('800.00', 1) },
        deltas: { placed_value: '782.50', placed_orders: '400.00', aov: '76.50', recognised_revenue: null, repeat_rate_points: '-33.33' },
        trend: [
            { key: '2026-11-01', from: '2026-11-01', to: '2026-11-06', days: 6, placed_orders: 3, placed_value: '4710.00', units: 4, cancelled_orders: 0, outcomes, delivered_orders: 2, recognised_revenue: '3740.00', gross_profit: profit ? '1840.00' : null, previous_placed_orders: 0, previous_placed_value: '0.00', previous_units: 0 },
            { key: '2026-11-07', from: '2026-11-07', to: '2026-11-13', days: 7, placed_orders: 0, placed_value: '0.00', units: 0, cancelled_orders: 1, outcomes: { ...outcomes, delivered: 0, cancelled: 1 }, delivered_orders: 1, recognised_revenue: '970.00', gross_profit: profit ? '470.00' : null, previous_placed_orders: null, previous_placed_value: null, previous_units: null },
        ],
        categories: [
            { category_id: 1, name: 'Puzzles', name_bn: 'পাজল', orders: 2, units_sold: 3, units_returned: 0, units: 3, net_revenue: '3600.00', cogs: profit ? '1800.00' : null, gross_profit: profit ? '1800.00' : null, margin_percent: profit ? '50.00' : null, return_rate: '0.00' },
        ],
        brands: [
            { brand_id: null, name: null, name_bn: null, orders: 1, units_sold: 1, units_returned: 0, units: 1, net_revenue: '900.00', cogs: null, gross_profit: profit ? '300.00' : null, margin_percent: null, return_rate: '0.00' },
        ],
        lines_total: { orders: 3, units_sold: 4, units_returned: 0, units: 4, net_revenue: '4500.00', cogs: null, gross_profit: null, margin_percent: null, return_rate: '0.00' },
        reconciliation: { ledger_product_revenue: '4500.00', lines_net_revenue: '4500.00', difference: '0.00' },
        payment_methods: [
            { method: 'cod', orders: 4, value: '6090.00', orders_percent: '80.00', value_percent: '86.26', aov: '1522.50' },
            { method: 'bkash', orders: 1, value: '970.00', orders_percent: '20.00', value_percent: '13.74', aov: '970.00' },
        ],
        channels: [
            { source: 'web', orders: 4, value: '6090.00', orders_percent: '80.00', value_percent: '86.26', aov: '1522.50' },
            { source: 'facebook', orders: 1, value: '970.00', orders_percent: '20.00', value_percent: '13.74', aov: '970.00' },
        ],
        discounts: {
            orders_with_discount: 1,
            orders_with_discount_percent: '20.00',
            discount_total: '100.00',
            coupon_discount: '100.00',
            promotion_discount: '0.00',
            shipping_discount: '0.00',
            aov_with_discount: '970.00',
            aov_without_discount: '1522.50',
            recognised_discounts: '-100.00',
            coupons: [{ code: 'SAVE10', orders: 1, discount: '100.00', value: '970.00' }],
            promotions: [],
        },
        recommendations: [
            { source: 'pdp_cross_sell', placed_lines: 1, placed_units: 2, placed_orders: 1, placed_value: '2400.00', placed_value_percent: '40.68', delivered_units: 2, net_revenue: '2400.00', gross_profit: null, return_rate: '0.00' },
            { source: 'none', placed_lines: 4, placed_units: 5, placed_orders: 4, placed_value: '3500.00', placed_value_percent: '59.32', delivered_units: 2, net_revenue: '2100.00', gross_profit: null, return_rate: '0.00' },
        ],
        conversion: { available: false, reason: 'no_session_denominator' },
    };
}

function api(me: Me = manager, body: SalesAnalytics = report()) {
    return mockApi((url) => {
        if (url.endsWith('/auth/me')) return { status: 200, body: me };
        if (url.includes('/analytics/sales')) return { status: 200, body };
        if (url.includes('/inventory/low-stock-count')) return { status: 200, body: { location_id: 1, count: 0 } };
        return { status: 404 };
    });
}

const urls = (calls: ReturnType<typeof mockApi>) => calls.mock.calls.map(([url]) => decodeURIComponent(String(url)));

describe('sales analytics', () => {
    it('shows placed demand and recognised revenue as separate figures, from the server', async () => {
        api();
        renderApp('/analytics/sales');

        expect(await screen.findByText('Order value placed')).toBeTruthy();
        expect(screen.getAllByText('Revenue recognised').length).toBeGreaterThan(1);
        expect(screen.getByText('5 orders · 1 cancelled, not counted')).toBeTruthy();
        expect(screen.getByText('on delivery · 3 orders delivered')).toBeTruthy();
        expect(screen.getByText('+782.50%')).toBeTruthy();
        expect(screen.getByText('−33.33 pts')).toBeTruthy();
        expect(screen.getByText('66.67%')).toBeTruthy();
        expect(screen.getByText(/Conversion rate is not shown/)).toBeTruthy();

        const categories = screen.getAllByRole('table')[0]!;
        const puzzles = within(categories).getByRole('row', { name: /Puzzles/ });
        expect(within(puzzles).getByText('3,600')).toBeTruthy();
        expect(within(puzzles).getByText('50.00%')).toBeTruthy();
        expect(within(screen.getAllByRole('table')[1]!).getByRole('row', { name: /No brand/ })).toBeTruthy();

        expect(screen.getByText('Facebook & Instagram')).toBeTruthy();
        expect(screen.getByText('SAVE10')).toBeTruthy();
        expect(screen.getByText('Product page — cross-sell')).toBeTruthy();
        expect(screen.getByRole('link', { name: 'Scheduled reports' })).toBeTruthy();
    });

    it('sends the preset, custom dates and grouping to the server and builds both export links', async () => {
        const calls = api();
        renderApp('/analytics/sales?range=custom&from=2026-11-01&to=2026-11-30');
        await screen.findByText('Order value placed');
        expect(urls(calls).some((u) => u.includes('/analytics/sales?range=custom&filter[from]=2026-11-01&filter[to]=2026-11-30'))).toBe(true);

        fireEvent.click(screen.getByRole('button', { name: 'Month' }));
        await waitFor(() => expect(urls(calls).some((u) => u.includes('group=month'))).toBe(true));

        const xlsx = screen.getByRole('link', { name: 'Excel (.xlsx)' }).getAttribute('href') ?? '';
        expect(decodeURIComponent(xlsx)).toBe('/api/v1/admin/analytics/sales/export?range=custom&filter[from]=2026-11-01&filter[to]=2026-11-30&group=month&format=xlsx&locale=en');
        expect(screen.getByRole('link', { name: 'CSV' }).getAttribute('href')).toContain('format=csv');

        fireEvent.change(screen.getByLabelText('Period'), { target: { value: 'last_month' } });
        await waitFor(() => expect(urls(calls).some((u) => u.includes('/analytics/sales?range=last_month&group=month'))).toBe(true));
    });

    it('hides export without reports.export and the profit columns when the server withholds them', async () => {
        api(reader, report(false));
        renderApp('/analytics/sales');
        await screen.findByText('Order value placed');
        expect(screen.queryByText('Export')).toBeNull();
        expect(screen.queryByRole('columnheader', { name: 'Gross profit' })).toBeNull();
        expect(screen.getByText(/Cost and profit are hidden for your role/)).toBeTruthy();
    });

    it('renders in Bangla with Western digits', async () => {
        api();
        renderApp('/analytics/sales', { locale: 'bn' });
        expect(await screen.findByText('দেওয়া অর্ডারের মূল্য')).toBeTruthy();
        expect(screen.getByText('ফেসবুক ও ইনস্টাগ্রাম')).toBeTruthy();
        expect(screen.getByRole('row', { name: /পাজল/ })).toBeTruthy();
        expect(document.body.textContent ?? '').not.toMatch(/[০-৯]/);
    });
});
