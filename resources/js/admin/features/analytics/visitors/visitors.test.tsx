import { cleanup, fireEvent, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { mockApi, renderApp, viewer } from '@/test/renderApp';
import type { Me } from '@/features/auth/api';
import type { VisitorAnalytics } from './api';

afterEach(() => {
    cleanup();
    localStorage.clear();
});

const reader: Me = { ...viewer, permissions: ['dashboard.view', 'reports.view'] };

const summary = (visits: number, placed: number, rate: string | null): VisitorAnalytics['summary'] => ({
    visits,
    viewed_product: 1120,
    added_to_cart: 214,
    reached_checkout: 88,
    placed,
    delivered: 5,
    in_progress: 6,
    orders_total: 30,
    order_rate: rate,
    cart_to_order_rate: '5.61',
    delivered_rate: '41.67',
});

function report(over: Partial<VisitorAnalytics> = {}): VisitorAnalytics {
    return {
        range: { preset: '7d', from: '2026-11-11', to: '2026-11-17', days: 7 },
        comparison: { preset: 'custom', from: '2026-11-04', to: '2026-11-10', days: 7 },
        counted: 'analytics_consented_sessions',
        tracking: { events_recorded: 4000, events_without_session: 37, page_views_recorded: false, order_link_retention_days: 90, order_link_complete: true, devices_recorded: false, places_recorded: false },
        summary: summary(1906, 12, '0.63'),
        previous: summary(1672, 8, '0.48'),
        deltas: { visits: '13.99', placed: '50.00', order_rate_points: '0.15', cart_to_order_points: '-1.20', delivered_rate_points: null },
        funnel: [
            { key: 'visited', sessions: 1906, previous_sessions: 1672, change: '13.99', drop_percent: null, of_visits_percent: '100.00' },
            { key: 'viewed_product', sessions: 1120, previous_sessions: 1000, change: '12.00', drop_percent: '41.24', of_visits_percent: '58.76' },
            { key: 'added_to_cart', sessions: 214, previous_sessions: 200, change: '7.00', drop_percent: '80.89', of_visits_percent: '11.23' },
            { key: 'reached_checkout', sessions: 88, previous_sessions: 70, change: '25.71', drop_percent: '58.88', of_visits_percent: '4.62' },
            { key: 'placed_order', sessions: 12, previous_sessions: 8, change: '50.00', drop_percent: '86.36', of_visits_percent: '0.63' },
            { key: 'delivered', sessions: 5, previous_sessions: 4, change: '25.00', drop_percent: '58.33', of_visits_percent: '0.26' },
        ],
        biggest_drop: { from: 'reached_checkout', to: 'placed_order', percent: '86.36' },
        daily: [
            { date: '2026-11-11', visits: 250, orders: 2 },
            { date: '2026-11-12', visits: 310, orders: 3 },
        ],
        top_products: [{ product_id: 9, name: 'Kids Tricycle with Push Handle', name_bn: 'পুশ হ্যান্ডেলসহ ট্রাইসাইকেল', views: 1412, add_to_carts: 38, cart_rate: '2.69' }],
        ...over,
    };
}

function api(body: VisitorAnalytics = report()) {
    return mockApi((url) => {
        if (url.endsWith('/auth/me')) return { status: 200, body: reader };
        if (url.includes('/analytics/visitors')) return { status: 200, body };
        if (url.includes('/inventory/low-stock-count')) return { status: 200, body: { location_id: 1, count: 0 } };
        return { status: 404 };
    });
}

const urls = (calls: ReturnType<typeof mockApi>) => calls.mock.calls.map(([url]) => decodeURIComponent(String(url)));

describe('visitors & funnel', () => {
    it('leads with plain sentences built only from the server’s figures', async () => {
        api();
        renderApp('/analytics/visitors');
        const words = await screen.findByRole('region', { name: /In plain words/ });
        expect(within(words).getByText('1,906 shoppers visited and 12 placed an order — 0.63% of visits, up from 0.48%.')).toBeTruthy();
        expect(within(words).getByText('The biggest drop is checkout → order: 88 reached checkout, 12 went on to order (86.36% lost).')).toBeTruthy();
        expect(within(words).getByText(/Counted: only shoppers who allowed analytics tracking/)).toBeTruthy();
        expect(within(words).getByText(/plain page views aren’t recorded/)).toBeTruthy();
    });

    it('shows the KPIs and all six funnel steps with the server’s drops', async () => {
        api();
        const { container } = renderApp('/analytics/visitors');
        expect(await screen.findByText('12 orders from 1,906 visits')).toBeTruthy();
        expect(screen.getByText('0.63%')).toBeTruthy();
        expect(screen.getByText('+13.99%')).toBeTruthy();
        expect(screen.getByText('+0.15 pts')).toBeTruthy();
        expect(screen.getByText('12 of 214 carts became orders')).toBeTruthy();

        const steps = Array.from(container.querySelectorAll('[data-step]')).map((el) => el.getAttribute('data-step'));
        expect(steps).toEqual(['visited', 'viewed_product', 'added_to_cart', 'reached_checkout', 'placed_order', 'delivered']);
        const checkout = container.querySelector('[data-step="placed_order"]') as HTMLElement;
        expect(within(checkout).getByText('−86.36%')).toBeTruthy();
        expect(within(checkout).getByText('Placed an order')).toBeTruthy();
        expect(within(checkout).getByText('0.63% of visits')).toBeTruthy();
        expect(screen.getByText('Biggest drop: checkout → order (86.36%).')).toBeTruthy();

        expect(screen.getByText('Kids Tricycle with Push Handle')).toBeTruthy();
        expect(screen.getByText('1,412 views')).toBeTruthy();
        expect(screen.getByText('Devices and places aren’t recorded')).toBeTruthy();
        expect(screen.getByText(/37 events have no visit id/)).toBeTruthy();
    });

    it('sends the preset and the custom range dates to the server', async () => {
        const calls = api();
        renderApp('/analytics/visitors?range=custom&from=2026-11-01&to=2026-11-07');
        await screen.findByText('12 orders from 1,906 visits');
        expect(urls(calls).some((u) => u.includes('/analytics/visitors?range=custom&filter[from]=2026-11-01&filter[to]=2026-11-07'))).toBe(true);

        fireEvent.change(screen.getByLabelText('Period'), { target: { value: '30d' } });
        await waitFor(() => expect(urls(calls).some((u) => u.endsWith('/analytics/visitors?range=30d'))).toBe(true));
    });

    it('says plainly when nothing was recorded', async () => {
        api(report({ summary: summary(0, 0, null), funnel: [], biggest_drop: null, daily: [], top_products: [] }));
        renderApp('/analytics/visitors');
        expect(await screen.findByText('No visits recorded in this period')).toBeTruthy();
        expect(screen.getByText(/no shopper allowed analytics tracking/)).toBeTruthy();
    });

    it('renders Bangla with Western digits and the Bangla product name', async () => {
        api();
        renderApp('/analytics/visitors', { locale: 'bn' });
        const words = await screen.findByRole('region', { name: /সহজ কথায়/ });
        expect(within(words).getByText('1,906 জন ক্রেতা ভিজিট করেছেন, 12 জন অর্ডার দিয়েছেন — ভিজিটের 0.63%, আগে ছিল 0.48%।')).toBeTruthy();
        expect(screen.getByText('পুশ হ্যান্ডেলসহ ট্রাইসাইকেল')).toBeTruthy();
        expect(document.body.textContent ?? '').not.toMatch(/[০-৯]/);
    });
});
