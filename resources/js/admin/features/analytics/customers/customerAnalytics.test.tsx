import { cleanup, fireEvent, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { mockApi, renderApp, viewer } from '@/test/renderApp';
import type { Me } from '@/features/auth/api';
import { customerAnalyticsPath, monthsRange, type CustomerAnalytics } from './api';

afterEach(() => {
    cleanup();
    localStorage.clear();
});

const manager: Me = { ...viewer, roles: ['manager'], permissions: ['dashboard.view', 'reports.view', 'customers.view', 'shipments.view'] };

/** The shape the server returns for the hand-checked month in tests/Feature/Customers/CustomerAnalyticsTest.php. */
const report: CustomerAnalytics = {
    range: { preset: 'custom', from: '2026-06-01', to: '2026-11-13', days: 166 },
    thresholds: { vip_min_lifetime_value: '15000.00', at_risk_after_days: 90, lapsed_after_days: 180 },
    segments: [
        { segment: 'no_orders', customers: 1, lifetime_value: '0.00' },
        { segment: 'lapsed', customers: 0, lifetime_value: '0.00' },
        { segment: 'at_risk', customers: 0, lifetime_value: '0.00' },
        { segment: 'vip', customers: 0, lifetime_value: '0.00' },
        { segment: 'new', customers: 1, lifetime_value: '1100.00' },
        { segment: 'active', customers: 1, lifetime_value: '3300.00' },
    ],
    lifetime_value: {
        customers_with_value: 2,
        total: '4400.00',
        average: '2200.00',
        median: '2200.00',
        buckets: [
            { from: '0.00', to: '1000.00', customers: 0, lifetime_value: '0.00' },
            { from: '1000.00', to: '2500.00', customers: 1, lifetime_value: '1100.00' },
            { from: '2500.00', to: null, customers: 1, lifetime_value: '3300.00' },
        ],
    },
    repeat_purchase: { customers_with_delivered: 2, repeat_customers: 1, rate: '50.00' },
    period: { customers: 3, new_customers: 2, returning_customers: 1, returning_share: '33.33' },
    cohorts: [
        {
            month: '2026-10',
            customers: 2,
            lifetime_value: '4400.00',
            average_lifetime_value: '2200.00',
            retention: [
                { offset: 0, month: '2026-10', customers: 2, rate: '100.00' },
                { offset: 1, month: '2026-11', customers: 1, rate: '50.00' },
            ],
        },
        { month: '2026-11', customers: 0, lifetime_value: '0.00', average_lifetime_value: null, retention: [{ offset: 0, month: '2026-11', customers: 0, rate: null }] },
    ],
    top_customers: [{ id: 1, name: 'Alice Rahman', phone_masked: '017****8046', orders_count: 3, delivered_orders_count: 3, lifetime_value: '3300.00', last_order_at: '2026-11-02T04:00:00+00:00' }],
};

function api(me: Me = manager, body: CustomerAnalytics = report) {
    return mockApi((url) => {
        if (url.endsWith('/auth/me')) return { status: 200, body: me };
        if (url.includes('/reports/customers')) return { status: 200, body };
        if (url.includes('/inventory/low-stock-count')) return { status: 200, body: { location_id: 1, count: 0 } };
        return { status: 404 };
    });
}

const urls = (calls: ReturnType<typeof mockApi>) => calls.mock.calls.map(([url]) => decodeURIComponent(String(url)));

describe('customer analytics', () => {
    it('lays out the server’s figures: LTV, repeat rate, segments, cohorts and top customers', async () => {
        api();
        renderApp('/analytics/customers');

        expect(await screen.findByRole('heading', { name: 'Monthly cohorts' })).toBeTruthy();
        expect(screen.getByText('median ৳2,200 · 2 customers with a delivery')).toBeTruthy();
        expect(screen.getAllByText('50%').length).toBeGreaterThan(0);
        expect(screen.getByText('1 of 2 buyers have 2+ delivered orders')).toBeTruthy();
        expect(screen.getByText('33.33%')).toBeTruthy();
        expect(screen.getByText('Lifetime value ৳15,000 or more')).toBeTruthy();
        expect(screen.getByText('Last order 180+ days ago')).toBeTruthy();

        const cohorts = screen.getByRole('row', { name: /Oct 2026/ });
        expect(within(cohorts).getByText('100%')).toBeTruthy();
        expect(within(cohorts).getByTitle('50% — 1 of 2 ordered in Nov 2026')).toBeTruthy();

        const top = screen.getByRole('row', { name: /Alice Rahman/ });
        expect(within(top).getByText('017****8046')).toBeTruthy();
        expect(within(top).getByText('3,300')).toBeTruthy();
        expect(screen.getByRole('table', { name: 'Customers per lifetime-value band' })).toBeTruthy();
        expect(screen.getByRole('link', { name: 'Couriers' }).getAttribute('href')).toBe('/analytics/couriers');
    });

    it('asks the server for another month span as a custom range; the default goes without parameters', async () => {
        const calls = api();
        renderApp('/analytics/customers');
        await screen.findByRole('heading', { name: 'Monthly cohorts' });
        expect(urls(calls).some((u) => u.endsWith('/reports/customers'))).toBe(true);

        fireEvent.change(screen.getByLabelText('Cohort months'), { target: { value: '12' } });
        await waitFor(() => expect(urls(calls).some((u) => u.includes('/reports/customers?range=custom&from='))).toBe(true));

        const now = new Date('2026-11-13T20:00:00Z'); // 14 Nov in Dhaka
        expect(monthsRange(3, now)).toEqual({ from: '2026-09-01', to: '2026-11-14' });
        expect(customerAnalyticsPath('12', now)).toBe('/reports/customers?range=custom&from=2025-12-01&to=2026-11-14');
    });

    it('shows the empty state before any customer exists, and renders Bangla with Western digits', async () => {
        api(manager, { ...report, segments: report.segments.map((s) => ({ ...s, customers: 0 })) });
        renderApp('/analytics/customers');
        expect(await screen.findByText('No customers yet')).toBeTruthy();
        cleanup();

        api();
        renderApp('/analytics/customers', { locale: 'bn' });
        expect(await screen.findByRole('heading', { name: 'মাসিক কোহর্ট' })).toBeTruthy();
        expect(document.body.textContent ?? '').not.toMatch(/[০-৯]/);
    });
});
