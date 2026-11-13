import { cleanup, fireEvent, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { mockApi, renderApp, viewer } from '@/test/renderApp';
import type { Me } from '@/features/auth/api';
import type { CourierPerformance } from './api';

afterEach(() => {
    cleanup();
    localStorage.clear();
});

const accountant: Me = { ...viewer, roles: ['accountant'], permissions: ['dashboard.view', 'shipments.view', 'reports.view', 'reports.export'] };
const reader: Me = { ...viewer, permissions: ['dashboard.view', 'shipments.view', 'reports.view'] };

const metrics = (over: Partial<CourierPerformance['totals']> = {}): CourierPerformance['totals'] => ({
    booked: 7,
    delivered: 3,
    partially_delivered: 1,
    returned: 1,
    cancelled: 1,
    in_flight: 2,
    delivery_rate: '75.00',
    return_rate: '25.00',
    average_days_to_deliver: '2.0',
    average_charge: '86.00',
    cod_expected: '4400.00',
    cod_collected: '1750.00',
    cod_outstanding: '1500.00',
    ...over,
});

/** The server's hand-checked week (tests/Feature/Fulfilment/Tracking/CourierPerformanceReportTest.php). */
const report: CourierPerformance = {
    from: '2026-11-01',
    to: '2026-11-07',
    provider: null,
    totals: metrics(),
    providers: [
        { provider: { id: 1, code: 'steadfast', name: 'Steadfast', name_bn: 'স্টেডফাস্ট' }, ...metrics({ booked: 5, delivered: 2, delivery_rate: '66.67', average_days_to_deliver: '1.5', average_charge: '92.50', cod_collected: '1500.00' }) },
        { provider: { id: 2, code: 'pathao', name: 'Pathao', name_bn: 'পাঠাও' }, ...metrics({ booked: 2, delivered: 1, delivery_rate: '100.00', average_days_to_deliver: '3.0', average_charge: '60.00', cod_collected: '250.00', cod_outstanding: '0.00' }) },
    ],
    districts: [
        { district: { id: 1, name: 'Dhaka', name_bn: 'ঢাকা' }, ...metrics({ booked: 4 }) },
        { district: null, ...metrics({ booked: 3, delivery_rate: null, average_days_to_deliver: null, average_charge: null }) },
    ],
};

function api(me: Me = accountant, body: CourierPerformance = report) {
    return mockApi((url) => {
        if (url.endsWith('/auth/me')) return { status: 200, body: me };
        if (url.includes('/reports/couriers')) return { status: 200, body };
        if (url.includes('/inventory/low-stock-count')) return { status: 200, body: { location_id: 1, count: 0 } };
        return { status: 404 };
    });
}

const urls = (calls: ReturnType<typeof mockApi>) => calls.mock.calls.map(([url]) => decodeURIComponent(String(url)));

describe('courier performance', () => {
    it('lays out the server’s totals, the per-courier and per-district tables, and the CSV link', async () => {
        api();
        renderApp('/analytics/couriers');

        expect(await screen.findByRole('heading', { name: 'By courier' })).toBeTruthy();
        expect(screen.getByText('Parcels handed to a courier 01 Nov 2026 – 07 Nov 2026 · Dhaka dates')).toBeTruthy();
        expect(screen.getByText('3 delivered · 1 returned · 2 on the way')).toBeTruthy();
        expect(screen.getAllByText('75.00%').length).toBeGreaterThan(0);
        expect(screen.getByText('2.0 days')).toBeTruthy();

        const couriers = screen.getAllByRole('table')[0]!;
        const steadfast = within(couriers).getByRole('row', { name: /Steadfast/ });
        expect(within(steadfast).getByText('66.67%')).toBeTruthy();
        expect(within(steadfast).getByText('92.5')).toBeTruthy(); // ৳ sign in its own span; the server's 92.50 grouped, never re-computed
        const districts = screen.getAllByRole('table')[1]!;
        expect(within(districts).getByRole('row', { name: /No district/ })).toBeTruthy();
        expect(within(districts).getAllByText('—').length).toBeGreaterThan(0);

        expect(screen.getByRole('link', { name: /Export/ }).getAttribute('href')).toBe('/api/v1/admin/reports/couriers/export');
    });

    it('sends the period and courier to the server from the URL and the filters', async () => {
        const calls = api();
        renderApp('/analytics/couriers?from=2026-11-01&to=2026-11-07');
        await screen.findByRole('heading', { name: 'By courier' });
        expect(urls(calls).some((u) => u.includes('/reports/couriers?filter[from]=2026-11-01&filter[to]=2026-11-07'))).toBe(true);

        fireEvent.change(screen.getByLabelText('Courier'), { target: { value: 'pathao' } });
        await waitFor(() => expect(urls(calls).some((u) => u.includes('filter[provider]=pathao'))).toBe(true));
        expect(screen.getByRole('link', { name: /Export/ }).getAttribute('href')).toContain('filter%5Bprovider%5D=pathao');
    });

    it('hides Export without reports.export and shows the empty state for a quiet period', async () => {
        api(reader, { ...report, totals: metrics({ booked: 0 }), providers: [], districts: [] });
        renderApp('/analytics/couriers');
        expect(await screen.findByText('No parcels in this period')).toBeTruthy();
        expect(screen.queryByRole('link', { name: /Export/ })).toBeNull();
    });

    it('renders in Bangla with Western digits', async () => {
        api();
        renderApp('/analytics/couriers', { locale: 'bn' });
        expect(await screen.findByRole('heading', { name: 'কুরিয়ার অনুযায়ী' })).toBeTruthy();
        expect(screen.getByRole('row', { name: /স্টেডফাস্ট/ })).toBeTruthy();
        expect(screen.getByText('2.0 দিন')).toBeTruthy();
        expect(document.body.textContent ?? '').not.toMatch(/[০-৯]/);
    });
});
