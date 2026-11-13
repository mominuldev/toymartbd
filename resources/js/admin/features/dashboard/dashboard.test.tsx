import { cleanup, fireEvent, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { mockApi, renderApp, viewer } from '@/test/renderApp';
import type { Me } from '@/features/auth/api';
import type { Dashboard } from './api';
import { deltaText, groupDecimal, onePlace, rangeText } from './format';

afterEach(() => {
    cleanup();
    localStorage.clear();
});

const owner: Me = { ...viewer, name: 'Mominul Islam', roles: ['super_admin'], permissions: ['dashboard.view', 'orders.view', 'finance.view', 'customers.view', 'inventory.view', 'risk.view', 'shipments.view', 'reports.export'] };

const days = ['2026-11-02', '2026-11-03', '2026-11-04', '2026-11-05', '2026-11-06', '2026-11-07', '2026-11-08'];
const placed = ['8030.00', '6830.00', '3080.00', '4450.00', '4740.00', '8780.00', '1910.00'];
const prior = ['3980.00', '4420.00', '2860.00', '5310.00', '3650.00', '4790.00', '2440.00'];
const counts = [2, 2, 1, 2, 2, 2, 1];
const revenue = ['0.00', '0.00', '8030.00', '0.00', '4550.00', '1330.00', '0.00'];
const profit = ['-255.00', '-700.00', '2444.00', '-204.00', '249.00', '243.00', '-1060.00'];

/** 04-admin-spec B1's week (02–08 Nov 2026), as the server would send it. */
const week: Dashboard = {
    range: { preset: '7d', from: '2026-11-02', to: '2026-11-08', days: 7, granularity: 'day' },
    previous: { from: '2026-10-26', to: '2026-11-01', days: 7 },
    visibility: { orders: true, finance: true, customers: true, inventory: true },
    kpis: {
        orders_placed: { count: 12, previous_count: 9, count_delta_percent: '33.33', value: '37820.00', previous_value: '27450.00', value_delta_percent: '37.78', sparkline: days.map((key, i) => ({ key, count: counts[i]!, value: placed[i]! })) },
        average_order_value: { value: '3151.67', previous: '3050.00', delta_percent: '3.33', sparkline: days.map((key) => ({ key, value: null })) },
        recognised_revenue: { value: '13910.00', previous: '10180.00', delta_percent: '36.64', source: 'ledger', sparkline: days.map((key, i) => ({ key, value: revenue[i]! })) },
        gross_profit: { value: '5210.00', previous: '3900.00', delta_percent: '33.59', margin_percent: '37.45', sparkline: days.map((key) => ({ key, value: '0.00' })) },
        net_profit: { value: '717.00', previous: '512.00', delta_percent: '40.04', margin_percent: '5.15', sparkline: days.map((key, i) => ({ key, value: profit[i]! })) },
        cash_collected: { value: '1330.00', previous: '0.00', delta_percent: null, sparkline: days.map((key) => ({ key, value: '0.00' })) },
        customers: { customers: 11, new: 8, returning: 3, previous_customers: 9, previous_new: 6, previous_returning: 3, new_delta_percent: '33.33', sparkline: days.map((key) => ({ key, value: 1 })) },
        low_stock: { low: 3, out: 0 },
    },
    status: {
        counts: { pending: 2, on_hold: 0, confirmed: 0, processing: 0, ready_to_ship: 3, shipped: 1, delivered: 3, completed: 0, cancelled: 1, returned: 1, partially_returned: 1 },
        open: { count: 6, value: '18000.00' },
    },
    trend: {
        granularity: 'day',
        placed_value: '37820.00',
        previous_placed_value: '27450.00',
        recognised_revenue: '13910.00',
        buckets: days.map((key, i) => ({
            key,
            from: key,
            to: key,
            placed_value: placed[i]!,
            placed_count: counts[i]!,
            units: counts[i]! * 2,
            previous_key: key,
            previous_placed_value: prior[i]!,
            previous_placed_count: 1,
            previous_units: 2,
            recognised_revenue: revenue[i]!,
        })),
    },
    categories: {
        total: '34520.00',
        slices: [
            { category_id: 1, name: 'Ride-ons & Outdoor', name_bn: 'রাইড-অন ও আউটডোর', other: false, amount: '9800.00', share_percent: '28.39' },
            { category_id: 2, name: 'Building & Construction', name_bn: null, other: false, amount: '5550.00', share_percent: '16.08' },
            { category_id: null, name: null, name_bn: null, other: true, amount: '19170.00', share_percent: '55.53' },
        ],
    },
    best_sellers: {
        rows: [
            { product_id: 5, name: 'Kids Tricycle with Push Handle', name_bn: null, sku: 'TMB-TRC-PH', image_url: null, units: 2, value: '9800.00' },
            { product_id: 6, name: 'Brick Box 500', name_bn: null, sku: 'TMB-BRK-500-CL', image_url: null, units: 3, value: '5550.00' },
        ],
        units: 23,
        products: 10,
        value: '34520.00',
    },
    low_stock: {
        location_id: 1,
        low: 3,
        out: 0,
        rows: [{ variant_id: 41, product_id: 9, name: 'Wooden Alphabet Puzzle BN+EN', name_bn: null, variant_name: null, sku: 'TMB-WAP', on_hand: 8, reserved: 2, available: 6, reorder_point: 12 }],
    },
    attention: [
        { key: 'verification', count: 1, oldest_at: '2026-11-07T14:08:00Z' },
        { key: 'failed_bookings', count: 0, oldest_at: null },
        { key: 'low_stock', count: 3, oldest_at: null },
    ],
    activity: [{ id: 1, type: 'status_changed', field: 'status', from: 'pending', to: 'confirmed', order_id: 127, order_number: 'TMB-26-00127', actor: 'Rina Akter', at: '2026-11-08T08:18:00Z' }],
};

/** What a viewer (orders.view, no finance) gets: ledger blocks are null, not zero. */
const noFinance: Dashboard = {
    ...week,
    visibility: { orders: true, finance: false, customers: false, inventory: false },
    kpis: { ...week.kpis, recognised_revenue: null, gross_profit: null, net_profit: null, cash_collected: null, customers: null, low_stock: null },
    trend: { ...week.trend!, recognised_revenue: null, buckets: week.trend!.buckets.map((b) => ({ ...b, recognised_revenue: null })) },
    categories: null,
    low_stock: null,
    attention: [],
};

function dashboardApi(body: Dashboard = week, me: Me = owner) {
    return mockApi((url) => {
        if (url.endsWith('/auth/me')) return { status: 200, body: me };
        if (url.includes('/dashboard')) return { status: 200, body };
        if (url.includes('/inventory/low-stock-count')) return { status: 200, body: { location_id: 1, count: 3 } };
        return { status: 404 };
    });
}

const urls = (calls: ReturnType<typeof mockApi>) => calls.mock.calls.map(([url]) => decodeURIComponent(String(url)));
const spanText = (value: string) => (_: string, el: Element | null) => el?.tagName === 'SPAN' && el.textContent === value;

describe('dashboard formatting', () => {
    it('groups server decimals and sets deltas as Figma does, without computing money', () => {
        expect(groupDecimal('37820.00')).toBe('37,820');
        expect(deltaText('37.78')).toBe('+37.8%');
        expect(deltaText('-4.00')).toBe('−4%');
        expect(onePlace('5.15')).toBe('5.2');
        expect(onePlace('-0.04')).toBe('0.0');
        expect(rangeText('2026-11-02', '2026-11-08', 'en')).toBe('02–08 Nov');
        expect(rangeText('2026-10-26', '2026-11-01', 'en')).toBe('26 Oct – 01 Nov');
    });
});

describe('dashboard', () => {
    it('lays out the server’s KPIs, status strip, charts, tables, attention and activity', async () => {
        dashboardApi();
        renderApp('/');

        expect(await screen.findByRole('heading', { name: 'Overview' })).toBeTruthy();
        expect(await screen.findByText(/here is the shop for Last 7 days · 02–08 Nov/)).toBeTruthy();

        // KPI values exactly as the server sent them; "placed" and "recognised" labelled apart.
        expect(screen.getByRole('heading', { name: 'Gross order value' })).toBeTruthy();
        expect(screen.getAllByText(spanText('৳37,820')).length).toBeGreaterThan(0);
        expect(screen.getByText('+37.8%')).toBeTruthy();
        expect(screen.getByRole('heading', { name: 'Net revenue (delivered)' })).toBeTruthy();
        expect(screen.getAllByText(spanText('৳13,910')).length).toBeGreaterThan(0);
        expect(screen.getByRole('img', { name: /Revenue is recognised when an order is delivered.*৳18,000/ })).toBeTruthy();
        expect(screen.getAllByText(spanText('৳717')).length).toBeGreaterThan(0);
        expect(screen.getByText('vs ৳512 · 5.2% margin')).toBeTruthy();
        expect(screen.getByText('Gross profit ৳5,210 · 37.5% margin')).toBeTruthy();
        expect(screen.getAllByText(spanText('৳3,151.67')).length).toBeGreaterThan(0);
        expect(screen.getByText('8 new · 3 returning')).toBeTruthy();
        expect(screen.getByText('COD remitted by couriers — cash, not revenue')).toBeTruthy();
        expect(screen.getAllByText('0 out of stock')).toHaveLength(2);

        // Status strip: non-zero cells link to the board tab; zero cells are not links.
        const strip = screen.getAllByRole('navigation', { name: 'Orders by status, right now' })[0]!;
        expect(within(strip).getAllByRole('link', { name: 'Pending: 2 — open the order board' })[0]!.getAttribute('href')).toBe('/orders?tab=pending');
        expect(within(strip).queryByRole('link', { name: /Confirmed/ })).toBeNull();

        // Trend legend and donut from server totals.
        expect(screen.getByText('Order value placed per day')).toBeTruthy();
        expect(screen.getByText('This period ৳37,820')).toBeTruthy();
        expect(screen.getByText('Previous ৳27,450')).toBeTruthy();
        expect(screen.getByText('Recognised revenue ৳13,910')).toBeTruthy();
        expect(screen.getByText('Where the money came from')).toBeTruthy();
        expect(screen.getByText('28.4%')).toBeTruthy();
        expect(screen.getByText('Other')).toBeTruthy();

        // Tables.
        expect(screen.getByRole('link', { name: 'Kids Tricycle with Push Handle' }).getAttribute('href')).toBe('/products/5');
        expect(screen.getByText('23 units across 10 products · ৳34,520')).toBeTruthy();
        expect(screen.getByText('8 on hand − 2 reserved · reorder at 12')).toBeTruthy();

        // Needs attention: only non-empty queues, each a link to its screen (rendered for 768 and desktop).
        expect(screen.getAllByText('Needs attention · 2').length).toBeGreaterThan(0);
        expect(screen.getAllByText('1 awaiting phone verification').length).toBeGreaterThan(0);
        expect(screen.queryByText(/courier bookings failed/)).toBeNull();
        expect(screen.getAllByRole('link', { name: 'Open verification queue' })[0]!.getAttribute('href')).toBe('/risk/queue');

        // Activity from structured events, no free text.
        expect(screen.getByRole('link', { name: 'Rina Akter moved TMB-26-00127 from Pending to Confirmed' }).getAttribute('href')).toBe('/orders/127');
    });

    it('omits ledger blocks the role may not see instead of showing zeros', async () => {
        dashboardApi(noFinance, viewer);
        renderApp('/');

        expect(await screen.findByRole('heading', { name: 'Gross order value' })).toBeTruthy();
        expect(screen.queryByRole('heading', { name: 'Net revenue (delivered)' })).toBeNull();
        expect(screen.queryByRole('heading', { name: 'Net profit' })).toBeNull();
        expect(screen.queryByRole('heading', { name: 'Cash collected (COD)' })).toBeNull();
        expect(screen.queryByText('Where the money came from')).toBeNull();
        expect(screen.queryByText(/Recognised revenue/)).toBeNull();
        expect(screen.queryByText('Low stock')).toBeNull();
    });

    it('sends the range preset and a custom range to the server from the URL', async () => {
        const calls = dashboardApi();
        renderApp('/?range=custom&from=2026-10-01&to=2026-10-31');
        await screen.findByRole('heading', { name: 'Overview' });
        expect(urls(calls).some((u) => u.endsWith('/dashboard?range=custom&from=2026-10-01&to=2026-10-31'))).toBe(true);

        fireEvent.change(screen.getByLabelText('Date range'), { target: { value: '30d' } });
        await waitFor(() => expect(urls(calls).some((u) => u.endsWith('/dashboard?range=30d'))).toBe(true));
    });

    it('offers CSV and Excel exports of the range on screen, built by the server', async () => {
        dashboardApi();
        renderApp('/?range=custom&from=2026-10-01&to=2026-10-31');
        await screen.findByText(/here is the shop for/);

        expect(screen.getByText('Export')).toBeTruthy();
        const xlsx = screen.getByRole('link', { name: 'Excel (.xlsx)' });
        const csv = screen.getByRole('link', { name: 'CSV' });
        expect(xlsx.getAttribute('href')).toBe('/api/v1/admin/dashboard/export?range=custom&from=2026-10-01&to=2026-10-31&format=xlsx&locale=en');
        expect(csv.getAttribute('href')).toBe('/api/v1/admin/dashboard/export?range=custom&from=2026-10-01&to=2026-10-31&format=csv&locale=en');
        expect(csv.hasAttribute('download')).toBe(true);

        // Changing the range changes what is exported.
        fireEvent.change(screen.getByLabelText('Date range'), { target: { value: '30d' } });
        await waitFor(() => expect(screen.getByRole('link', { name: 'CSV' }).getAttribute('href')).toBe('/api/v1/admin/dashboard/export?range=30d&format=csv&locale=en'));
    });

    it('asks for Bangla labels in Bangla, and hides Export when the role sees no block', async () => {
        dashboardApi();
        renderApp('/', { locale: 'bn' });
        expect(await screen.findByText('এক্সপোর্ট')).toBeTruthy();
        expect(screen.getByRole('link', { name: 'এক্সেল (.xlsx)' }).getAttribute('href')).toBe('/api/v1/admin/dashboard/export?format=xlsx&locale=bn');
        cleanup();

        const nothing: Dashboard = { ...noFinance, visibility: { orders: false, finance: false, customers: false, inventory: false }, kpis: { ...noFinance.kpis, orders_placed: null, average_order_value: null }, status: null, trend: null, best_sellers: null, activity: null };
        dashboardApi(nothing, { ...viewer, permissions: ['dashboard.view'] });
        renderApp('/');
        expect(await screen.findByRole('heading', { name: 'Overview' })).toBeTruthy();
        await screen.findByText(/here is the shop for/);
        expect(screen.queryByText('Export')).toBeNull();
        cleanup();

        // Every block visible but no reports.export (owner decision 2026-11-13): no Export either.
        dashboardApi(undefined, { ...owner, permissions: owner.permissions.filter((p) => p !== 'reports.export') });
        renderApp('/');
        await screen.findByText(/here is the shop for/);
        expect(screen.queryByText('Export')).toBeNull();
    });

    it('renders in Bangla with Western digits', async () => {
        dashboardApi();
        renderApp('/', { locale: 'bn' });
        expect(await screen.findByRole('heading', { name: 'সংক্ষিপ্ত চিত্র' })).toBeTruthy();
        expect(await screen.findByText('8 নতুন · 3 পুরনো')).toBeTruthy();
        expect(screen.getAllByText(spanText('৳37,820')).length).toBeGreaterThan(0);
        expect(screen.getByText('8 নতুন · 3 পুরনো')).toBeTruthy();
    });
});
