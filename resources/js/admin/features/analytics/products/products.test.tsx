import { cleanup, fireEvent, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { mockApi, renderApp, viewer } from '@/test/renderApp';
import type { Me } from '@/features/auth/api';
import type { ProductAnalytics, ProductAnalyticsRow } from './api';

afterEach(() => {
    cleanup();
    localStorage.clear();
});

const manager: Me = { ...viewer, roles: ['manager'], permissions: ['dashboard.view', 'reports.view', 'reports.export', 'shipments.view', 'finance.view', 'catalog.view_cost'] };
const reader: Me = { ...viewer, permissions: ['dashboard.view', 'reports.view', 'shipments.view'] };

const row = (over: Partial<ProductAnalyticsRow>): ProductAnalyticsRow => ({
    product_id: 1,
    name: 'Kids Tricycle with Push Handle',
    name_bn: 'পুশ হ্যান্ডেল ট্রাইসাইকেল',
    category_name: 'Ride-ons',
    category_name_bn: 'রাইড-অন',
    units_delivered: 6,
    units_returned: 0,
    units_kept: 6,
    net_revenue: '14700.00',
    cogs: '9114.00',
    gross_profit: '5586.00',
    margin_percent: '38.00',
    return_rate: '0.00',
    units_paced: 6,
    stock_left: 4,
    days_of_stock: 5,
    stock_value: '2400.00',
    ...over,
});

function report(profit = true): ProductAnalytics {
    const hide = (r: ProductAnalyticsRow): ProductAnalyticsRow => (profit ? r : { ...r, cogs: null, gross_profit: null, margin_percent: null, stock_value: null });
    const tricycle = hide(row({}));
    const teddy = hide(row({ product_id: 2, name: 'Plush Teddy Bear 45 cm', name_bn: 'টেডি বিয়ার 45 সেমি', category_name: 'Soft toys', category_name_bn: 'নরম খেলনা', units_delivered: 9, units_returned: 3, units_kept: 6, net_revenue: '6120.00', margin_percent: '41.00', return_rate: '33.33', units_paced: 9, stock_left: 22, days_of_stock: 73 }));
    const xylophone = { product_id: 3, name: 'Musical Xylophone 8 keys', name_bn: null, category_name: null, category_name_bn: null, units_paced: 0, stock_left: 14, days_of_stock: null, stock_value: profit ? '4200.00' : null };
    return {
        range: { preset: '30d', from: '2026-10-19', to: '2026-11-17', days: 30 },
        comparison: { preset: 'custom', from: '2026-09-19', to: '2026-10-18', days: 30 },
        profit_visible: profit,
        sort: 'revenue',
        thresholds: { running_out_days: 14, urgent_days: 7, min_units_for_returns: 3 },
        summary: {
            products_sold: 34,
            products_on_sale: 212,
            units_delivered: 58,
            units_delivered_previous: 49,
            units_delivered_change: 9,
            units_delta: '18.37',
            net_revenue: '42870.00',
            gross_profit: profit ? '15433.20' : null,
            margin_percent: profit ? '36.00' : null,
            units_returned: 4,
            return_rate: '6.90',
            running_out_count: 2,
            running_out_urgent_count: 2,
            not_selling_count: 11,
        },
        highlights: { best_seller: tricycle, most_returned: teddy },
        products: [tricycle, teddy],
        running_out: [tricycle],
        not_selling: [xylophone],
    };
}

function api(me: Me = manager, body: ProductAnalytics = report()) {
    return mockApi((url) => {
        if (url.endsWith('/auth/me')) return { status: 200, body: me };
        if (url.includes('/analytics/products')) return { status: 200, body };
        if (url.includes('/inventory/low-stock-count')) return { status: 200, body: { location_id: 1, count: 0 } };
        return { status: 404 };
    });
}

const urls = (calls: ReturnType<typeof mockApi>) => calls.mock.calls.map(([url]) => decodeURIComponent(String(url)));

describe('product analytics', () => {
    it('leads with three plain sentences built only from the server’s figures', async () => {
        api();
        renderApp('/analytics/products');
        const words = await screen.findByRole('region', { name: /In plain words/ });
        expect(within(words).getByText('Kids Tricycle with Push Handle was your best seller: 6 delivered for ৳14,700, keeping 38% after cost.')).toBeTruthy();
        expect(within(words).getByText('Plush Teddy Bear 45 cm came back 3 times out of 9 (33.33%) — worth checking its photos and size.')).toBeTruthy();
        expect(within(words).getByText('2 fast sellers have under 7 days of stock left; 11 products in stock sold nothing.')).toBeTruthy();
    });

    it('shows the KPI cards, best-seller rows and both stock cards', async () => {
        api();
        renderApp('/analytics/products');
        expect(await screen.findByText('Products sold')).toBeTruthy();
        expect(screen.getByText('of 212 on sale')).toBeTruthy();
        expect(screen.getByText('+9 vs previous 30 days')).toBeTruthy();
        expect(screen.getByText('36%')).toBeTruthy();
        expect(screen.getByText('4 units returned')).toBeTruthy();

        const table = screen.getByRole('table');
        const tricycle = within(table).getByRole('row', { name: /Kids Tricycle/ });
        expect(within(tricycle).getByText('14,700')).toBeTruthy();
        expect(within(tricycle).getByText('38%')).toBeTruthy();
        expect(within(tricycle).getByText('4 · 5 days')).toBeTruthy();
        expect(within(tricycle).getByLabelText('Running low')).toBeTruthy();
        const teddy = within(table).getByRole('row', { name: /Plush Teddy/ });
        expect(within(teddy).getByText('33.33%')).toBeTruthy();
        expect(within(teddy).getByText('22 · 2 months')).toBeTruthy();
        expect(within(teddy).queryByLabelText('Running low')).toBeNull();

        const out = screen.getByRole('region', { name: 'About to run out' });
        expect(within(out).getByText('4 left · about 5 days')).toBeTruthy();
        expect(within(out).getByRole('link', { name: 'Reorder' }).getAttribute('href')).toBe('/purchasing');
        const idle = screen.getByRole('region', { name: 'In stock but not selling' });
        expect(within(idle).getByText('14 in stock · ৳4,200')).toBeTruthy();
    });

    it('sends the custom range as filter[from]/filter[to] and the sort to the server', async () => {
        const calls = api();
        renderApp('/analytics/products?range=custom&from=2026-11-01&to=2026-11-15');
        await screen.findByText('Products sold');
        expect(urls(calls).some((u) => u.includes('/analytics/products?range=custom&filter[from]=2026-11-01&filter[to]=2026-11-15'))).toBe(true);

        fireEvent.click(screen.getByRole('button', { name: 'Returns' }));
        await waitFor(() => expect(urls(calls).some((u) => u.includes('filter[to]=2026-11-15&sort=returns'))).toBe(true));

        fireEvent.change(screen.getByLabelText('Period'), { target: { value: 'last_month' } });
        await waitFor(() => expect(urls(calls).some((u) => u.includes('/analytics/products?range=last_month&sort=returns'))).toBe(true));
    });

    it('hides margin and stock value when the server withholds cost', async () => {
        api(reader, report(false));
        renderApp('/analytics/products');
        const words = await screen.findByRole('region', { name: /In plain words/ });
        expect(within(words).getByText('Kids Tricycle with Push Handle was your best seller: 6 delivered for ৳14,700.')).toBeTruthy();
        expect(screen.queryByRole('columnheader', { name: 'Margin' })).toBeNull();
        expect(screen.queryByRole('button', { name: 'Margin' })).toBeNull();
        expect(screen.getByText('hidden for your role')).toBeTruthy();
        expect(screen.getByText('14 in stock')).toBeTruthy();
        expect(screen.getByText(/Cost, margin and stock value are hidden for your role/)).toBeTruthy();
    });

    it('renders in Bangla with Western digits', async () => {
        api();
        renderApp('/analytics/products', { locale: 'bn' });
        expect(await screen.findByText('বিক্রি হওয়া প্রোডাক্ট')).toBeTruthy();
        expect(screen.getByRole('row', { name: /টেডি বিয়ার 45 সেমি/ })).toBeTruthy();
        expect(screen.getAllByText(/পুশ হ্যান্ডেল ট্রাইসাইকেল/).length).toBeGreaterThan(0);
        expect(document.body.textContent ?? '').toContain('14,700');
        expect(document.body.textContent ?? '').not.toMatch(/[০-৯]/);
    });
});
