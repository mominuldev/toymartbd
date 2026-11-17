import { cleanup, fireEvent, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { mockApi, renderApp, viewer } from '@/test/renderApp';
import type { Me } from '@/features/auth/api';
import type { AdSpend, AdSpendList, MarketingAnalytics } from './api';

afterEach(() => {
    cleanup();
    localStorage.clear();
});

const owner: Me = { ...viewer, roles: ['manager'], permissions: ['dashboard.view', 'reports.view', 'reports.export', 'shipments.view', 'marketing.manage'] };
const reader: Me = { ...viewer, permissions: ['dashboard.view', 'reports.view'] };

const summary = { spend: '9500.00', revenue: '31200.00', orders: 21, delivered_orders: 18, campaigns: 3, unpaid_revenue: '0.00', unpaid_orders: 0, return_ratio: '3.28', cost_per_order: '452.38' };

/** The approved frame's figures (Figma 522:81016). */
function report(): MarketingAnalytics {
    return {
        range: { preset: 'custom', from: '2026-11-01', to: '2026-11-17', days: 17 },
        comparison: { preset: 'custom', from: '2026-10-15', to: '2026-10-31', days: 17 },
        summary,
        previous: { ...summary, spend: '8000.00', revenue: '20000.00' },
        deltas: { spend: '18.75', revenue: '56.00', orders: null },
        campaigns: [
            { campaign: 'Winter Toy Fest', source: 'facebook', has_spend: true, spend: '4000.00', orders: 11, delivered_orders: 10, delivered_revenue: '18400.00', return_ratio: '4.60', cost_per_order: '363.64' },
            { campaign: 'Eid gifts retarget', source: 'facebook', has_spend: true, spend: '3500.00', orders: 7, delivered_orders: 6, delivered_revenue: '9100.00', return_ratio: '2.60', cost_per_order: '500.00' },
            { campaign: 'Search · ride-on toys', source: 'google', has_spend: true, spend: '2000.00', orders: 3, delivered_orders: 2, delivered_revenue: '3700.00', return_ratio: '1.85', cost_per_order: '666.67' },
        ],
        untagged: { orders: 64, delivered_orders: 60, delivered_revenue: '192300.00', sources: ['web', 'whatsapp', 'pos'] },
        deals: [
            { kind: 'promotion', id: 2, name: 'Winter Toy Fest', name_bn: 'শীতের খেলনা উৎসব', type: 'discount', orders: 4, new_customers: 0, cost: '840.00', order_value: '15000.00', delivered_orders: 4, delivered_value: '14900.00' },
            { kind: 'coupon', id: 1, name: 'WELCOME100', name_bn: null, type: 'fixed', orders: 4, new_customers: 3, cost: '400.00', order_value: '13000.00', delivered_orders: 4, delivered_value: '12800.00' },
            { kind: 'coupon', id: 3, name: 'FREEDEL3000', name_bn: null, type: 'free_shipping', orders: 1, new_customers: 0, cost: '200.00', order_value: '3400.00', delivered_orders: 1, delivered_value: '3400.00' },
        ],
        deal_totals: { coupon: { cost: '1240.00', orders: 9, new_customers: 3 }, promotion: { cost: '840.00', orders: 4, new_customers: 0 } },
        highlights: {
            best_campaign: { campaign: 'Winter Toy Fest', source: 'facebook', spend: '4000.00', delivered_revenue: '18400.00', return_ratio: '4.60' },
            top_new_customer_coupon: { name: 'WELCOME100', new_customers: 3 },
        },
        empty: false,
        rules: { spend: 'prorated_by_day', revenue: 'delivered_net_product_revenue' },
    };
}

const entry: AdSpend = {
    id: 5,
    campaign: 'Winter Toy Fest',
    source: 'facebook',
    month: '2026-11',
    amount: '4000.00',
    note: null,
    entered_by: { id: 1, name: 'Mominul' },
    changed_by: { id: 1, name: 'Mominul' },
    entered_on: '2026-11-02',
    can: { update: true, delete: true },
};
const spendList: AdSpendList = { data: [entry], meta: { campaigns: ['Winter Toy Fest', 'eid-retarget'] } };

function api(me: Me = owner, body: MarketingAnalytics = report(), list: AdSpendList = spendList) {
    return mockApi((url, init) => {
        if (url.endsWith('/auth/me')) return { status: 200, body: me };
        if (url.includes('/analytics/marketing/spend')) {
            if (init?.method === 'POST') return { status: 201, body: { ...entry, id: 9, campaign: 'eid-retarget', amount: '3500.00' } };
            if (init?.method === 'PUT') return { status: 200, body: entry };
            if (init?.method === 'DELETE') return { status: 204 };
            return { status: 200, body: list };
        }
        if (url.includes('/analytics/marketing')) return { status: 200, body };
        if (url.includes('/inventory/low-stock-count')) return { status: 200, body: { location_id: 1, count: 0 } };
        return { status: 404 };
    });
}

const urls = (calls: ReturnType<typeof mockApi>) => calls.mock.calls.map(([url]) => decodeURIComponent(String(url)));

describe('marketing ROI', () => {
    it('leads with plain sentences built only from the server’s figures', async () => {
        api();
        renderApp('/analytics/marketing');
        const words = await screen.findByRole('region', { name: /In plain words/ });
        expect(within(words).getByText('You spent ৳9,500 on ads and got ৳31,200 back in delivered orders — ৳3.28 for every ৳1.')).toBeTruthy();
        expect(within(words).getByText('“Winter Toy Fest” on Facebook & Instagram did best: ৳18,400 back for ৳4,000.')).toBeTruthy();
        expect(within(words).getByText('Coupons cost ৳1,240 in discounts and brought 9 orders; WELCOME100 brought the most new customers.')).toBeTruthy();
        expect(screen.getByText('3.28×')).toBeTruthy();
        expect(screen.getByText('21 orders from ads')).toBeTruthy();
    });

    it('lists campaigns with spend, orders, delivered and return, and the untagged row last', async () => {
        api();
        renderApp('/analytics/marketing');
        const table = await screen.findByRole('table');
        const rows = within(table).getAllByRole('row');
        expect(rows).toHaveLength(5);
        const winter = within(table).getByRole('row', { name: /Winter Toy Fest/ });
        expect(within(winter).getByText('11')).toBeTruthy();
        expect(within(winter).getByText('4.6×')).toBeTruthy();
        expect(within(winter).getByText('18,400')).toBeTruthy();
        const last = rows[rows.length - 1]!;
        expect(within(last).getByText('No campaign tag')).toBeTruthy();
        expect(within(last).getByText('Website, WhatsApp, Shop counter (POS)')).toBeTruthy();
        expect(within(last).getByText('1,92,300')).toBeTruthy();

        expect(screen.getByText('Coupon · 4 orders · 3 new customers')).toBeTruthy();
        expect(screen.getByText('Free delivery · 1 orders')).toBeTruthy();
        expect(screen.getByText('Brought ৳12,800')).toBeTruthy();
        expect(screen.getByText('Spend is typed in by you — we never read ad accounts')).toBeTruthy();
    });

    it('adds ad spend with the right body', async () => {
        const calls = api();
        renderApp('/analytics/marketing');
        fireEvent.click(await screen.findByRole('button', { name: 'Add ad spend' }));
        const form = await screen.findByRole('form', { name: 'Add ad spend' });
        fireEvent.change(within(form).getByLabelText(/Campaign/), { target: { value: ' eid-retarget ' } });
        fireEvent.change(within(form).getByLabelText(/Where the ad ran/), { target: { value: 'facebook' } });
        fireEvent.change(within(form).getByLabelText(/Month/), { target: { value: '2026-11' } });
        fireEvent.change(within(form).getByLabelText(/Amount/), { target: { value: '3,500' } });
        fireEvent.click(within(form).getByRole('button', { name: 'Save' }));

        await waitFor(() => expect(calls.mock.calls.some(([, init]) => init?.method === 'POST')).toBe(true));
        const [url, init] = calls.mock.calls.find(([, i]) => i?.method === 'POST')!;
        expect(String(url)).toContain('/api/v1/admin/analytics/marketing/spend');
        expect(JSON.parse(String(init?.body))).toEqual({ campaign: 'eid-retarget', source: 'facebook', month: '2026-11', amount: '3500', note: null });
        expect(await screen.findByText('Ad spend added')).toBeTruthy();
    });

    it('edits and deletes an entry through a confirm dialog', async () => {
        const calls = api();
        renderApp('/analytics/marketing');
        fireEvent.click(await screen.findByRole('button', { name: 'Edit Winter Toy Fest' }));
        const form = await screen.findByRole('form', { name: 'Edit ad spend' });
        expect((within(form).getByLabelText(/Amount/) as HTMLInputElement).value).toBe('4000.00');
        fireEvent.click(within(form).getByRole('button', { name: 'Delete' }));
        fireEvent.click(await screen.findByRole('button', { name: 'Remove' }));
        await waitFor(() => expect(calls.mock.calls.some(([u, i]) => i?.method === 'DELETE' && String(u).endsWith('/analytics/marketing/spend/5'))).toBe(true));
    });

    it('hides adding and editing without marketing.manage', async () => {
        api(reader, report(), { ...spendList, data: [{ ...entry, can: { update: false, delete: false } }] });
        renderApp('/analytics/marketing');
        await screen.findByRole('table');
        expect(screen.queryByRole('button', { name: 'Add ad spend' })).toBeNull();
        expect(screen.queryByRole('button', { name: 'Add' })).toBeNull();
        expect(screen.queryByRole('button', { name: 'Edit Winter Toy Fest' })).toBeNull();
    });

    it('sends a custom range to both endpoints', async () => {
        const calls = api();
        renderApp('/analytics/marketing?range=custom&from=2026-11-01&to=2026-11-17');
        await screen.findByRole('table');
        expect(urls(calls).some((u) => u.includes('/analytics/marketing?range=custom&filter[from]=2026-11-01&filter[to]=2026-11-17'))).toBe(true);
        expect(urls(calls).some((u) => u.includes('/analytics/marketing/spend?range=custom&filter[from]=2026-11-01&filter[to]=2026-11-17'))).toBe(true);

        fireEvent.change(screen.getByLabelText('Period'), { target: { value: 'last_month' } });
        await waitFor(() => expect(urls(calls).some((u) => u.endsWith('/analytics/marketing?range=last_month'))).toBe(true));
    });

    it('shows the empty state when there is nothing in the period', async () => {
        api(owner, { ...report(), empty: true, campaigns: [], deals: [], untagged: { orders: 0, delivered_orders: 0, delivered_revenue: '0.00', sources: [] } }, { data: [], meta: { campaigns: [] } });
        renderApp('/analytics/marketing');
        expect(await screen.findByText(/Nothing to show for/)).toBeTruthy();
        expect(screen.getByRole('button', { name: 'Add ad spend' })).toBeTruthy();
    });

    it('renders in Bangla with Western digits', async () => {
        api();
        renderApp('/analytics/marketing', { locale: 'bn' });
        expect(await screen.findByText('বিজ্ঞাপন থেকে ফেরত')).toBeTruthy();
        expect(screen.getByText('শীতের খেলনা উৎসব')).toBeTruthy();
        expect(screen.getAllByText(/4\.6×/).length).toBeGreaterThan(0);
        expect(document.body.textContent ?? '').not.toMatch(/[০-৯]/);
    });
});
