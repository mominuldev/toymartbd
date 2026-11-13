import { cleanup, fireEvent, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { renderApp, viewer } from '@/test/renderApp';
import { ageLabel, formatMoney } from './format';
import { buildTracks } from './Stepper';
import { detail, operator, ordersApi, postedTo } from './fixtures';

afterEach(() => {
    cleanup();
    localStorage.clear();
});

const urls = (calls: ReturnType<typeof ordersApi>) => calls.mock.calls.map(([url]) => decodeURIComponent(String(url)));

describe('order board', () => {
    it('shows server counts on the tabs and the triad, risk and payment per row', async () => {
        ordersApi(operator);
        renderApp('/orders');
        const table = await screen.findByRole('table');

        expect(within(table).getByRole('link', { name: 'TMB-26-00126' })).toBeTruthy();
        expect(within(table).getByText('রিফাত চৌধুরী')).toBeTruthy();
        expect(within(table).getByText('High 80')).toBeTruthy();
        expect(within(table).getAllByText('COD').length).toBe(2);
        // The anomaly is the server's verdict, rendered with its plain reason.
        expect(within(table).getAllByText('Paid ৳200 · refund pending').length).toBeGreaterThan(0);
        // Couriers are Phase 6: never a fake consignment.
        expect(within(table).getAllByText('Not booked').length).toBeGreaterThan(0);
        expect(within(table).getByText('Verify by phone first')).toBeTruthy();
        expect(screen.getByRole('button', { name: /^Pending\s*2$/ })).toBeTruthy();
        expect(screen.getByRole('button', { name: /^Anomalies\s*1$/ })).toBeTruthy();
    });

    it('puts every filter in the URL and sends it to the server', async () => {
        const calls = ordersApi(operator);
        renderApp('/orders');
        await screen.findByRole('table');

        fireEvent.click(screen.getByRole('button', { name: /^Verify\s*1$/ }));
        await waitFor(() => expect(urls(calls).some((u) => u.includes('filter[verification_status]=pending'))).toBe(true));

        fireEvent.change(screen.getByLabelText('Risk'), { target: { value: 'high' } });
        await waitFor(() => expect(urls(calls).some((u) => u.includes('filter[risk_level]=high'))).toBe(true));

        fireEvent.click(screen.getByRole('switch', { name: 'Only anomalies' }));
        await waitFor(() => expect(urls(calls).some((u) => u.includes('filter[anomaly]=1'))).toBe(true));

        fireEvent.change(screen.getByRole('searchbox', { name: 'Search orders' }), { target: { value: '00126' } });
        await waitFor(() => expect(urls(calls).some((u) => u.includes('filter[search]=00126'))).toBe(true));
    });

    it('pages with the server cursor', async () => {
        const calls = ordersApi(operator);
        renderApp('/orders');
        await screen.findByRole('table');
        fireEvent.click(screen.getByRole('button', { name: 'Older' }));
        await waitFor(() => expect(urls(calls).some((u) => u.includes('cursor=abc'))).toBe(true));
    });

    it('bulk confirms and reports the partial failure per order, keeping it selected', async () => {
        const calls = ordersApi(operator);
        renderApp('/orders');
        const table = await screen.findByRole('table');

        fireEvent.click(within(table).getByRole('checkbox', { name: 'Select TMB-26-00126' }));
        fireEvent.click(within(table).getByRole('checkbox', { name: 'Select TMB-26-00127' }));
        const bar = screen.getByRole('region', { name: 'Bulk actions' });
        expect(within(bar).getByText('2 orders selected')).toBeTruthy();
        expect(within(bar).getByText('1 high risk')).toBeTruthy();

        fireEvent.click(within(bar).getByRole('button', { name: 'Confirm 2' }));
        await waitFor(() => expect(postedTo(calls, '/orders/bulk/confirm')).toEqual({ order_ids: [126, 127] }));
        expect(await within(bar).findByText('1 confirmed')).toBeTruthy();
        expect(within(bar).getByText('1 failed')).toBeTruthy();
        expect(within(bar).getByText(/A cancelled order cannot be confirmed\./)).toBeTruthy();
        expect((within(table).getByRole('checkbox', { name: 'Select TMB-26-00127' }) as HTMLInputElement).checked).toBe(true);
        expect((within(table).getByRole('checkbox', { name: 'Select TMB-26-00126' }) as HTMLInputElement).checked).toBe(false);
    });

    it('offers no selection to a viewer without orders.confirm', async () => {
        ordersApi(viewer);
        renderApp('/orders');
        const table = await screen.findByRole('table');
        expect(within(table).queryByRole('checkbox')).toBeNull();
    });

    it('renders in Bangla with Western digits', async () => {
        ordersApi(operator);
        renderApp('/orders', { locale: 'bn' });
        await screen.findByRole('table');
        expect(screen.getByRole('button', { name: /^অপেক্ষমাণ\s*2$/ })).toBeTruthy();
        expect(screen.getByText('বেশি 80')).toBeTruthy();
    });
});

describe('order detail', () => {
    it('shows the stepper, our risk score apart from the courier panel, and masked PII', async () => {
        ordersApi(operator);
        renderApp('/orders/126');

        expect(await screen.findByRole('heading', { level: 1, name: 'TMB-26-00126' })).toBeTruthy();
        expect(screen.getByText('Where this order is')).toBeTruthy();
        expect(screen.getByText('Toy Mart risk score')).toBeTruthy();
        expect(screen.getByText('High risk · 80 / 100')).toBeTruthy();
        // Rule 33b: the courier panel says "not connected", never a zero.
        expect(screen.getByText('Provider not connected')).toBeTruthy();
        // P6: the shipment card needs shipments.view, which this operator fixture lacks.
        expect(screen.getByText('You do not have access to shipments.')).toBeTruthy();
        expect(screen.getByText('Phone masked — needs the customer PII permission.')).toBeTruthy();
        expect(screen.queryByRole('link', { name: 'Call customer' })).toBeNull();
        expect(screen.getByText('কল করেছি, ধরেননি')).toBeTruthy();
    });

    it('renders exactly the server’s available actions', async () => {
        ordersApi(operator);
        renderApp('/orders/126');
        await screen.findByRole('heading', { level: 1 });
        expect(screen.getByRole('button', { name: 'Confirm order' })).toBeTruthy();
        expect(screen.getByRole('button', { name: 'Hold' })).toBeTruthy();
        expect(screen.getByRole('button', { name: 'Cancel order' })).toBeTruthy();
        expect(screen.queryByRole('button', { name: 'Release hold' })).toBeNull();
        expect(screen.queryByRole('button', { name: 'Start processing' })).toBeNull();
    });

    it('confirms, and surfaces a 409 as the server’s sentence', async () => {
        ordersApi(operator, (url, init) =>
            url.endsWith('/orders/126/confirm') && init?.method === 'POST'
                ? { status: 409, body: { type: 'https://toymartbd.com/problems/conflict', title: 'Conflict', status: 409, detail: 'This order is already confirmed.' } }
                : undefined,
        );
        renderApp('/orders/126');
        await screen.findByRole('heading', { level: 1 });
        fireEvent.click(screen.getByRole('button', { name: 'Confirm order' }));
        expect(await screen.findByText('This order is already confirmed.')).toBeTruthy();
    });

    it('cancels only after the reason and the typed order number', async () => {
        const calls = ordersApi(operator);
        renderApp('/orders/126');
        await screen.findByRole('heading', { level: 1 });
        fireEvent.click(screen.getByRole('button', { name: 'Cancel order' }));
        const dialog = await screen.findByRole('dialog');
        const submit = within(dialog).getByRole('button', { name: 'Cancel order' }) as HTMLButtonElement;
        expect(submit.disabled).toBe(true);

        fireEvent.change(within(dialog).getByLabelText(/Type TMB-26-00126/), { target: { value: 'TMB-26-00126' } });
        expect(submit.disabled).toBe(false);
        fireEvent.click(submit);
        // The generated schema refuses a missing reason before any call.
        expect(await within(dialog).findByText('This field is required.')).toBeTruthy();
        expect(postedTo(calls, '/orders/126/cancel')).toBeNull();

        fireEvent.change(within(dialog).getByLabelText(/^Reason/), { target: { value: 'fake_order' } });
        fireEvent.click(submit);
        await waitFor(() => expect(postedTo(calls, '/orders/126/cancel')).toEqual({ reason: 'fake_order', note: null }));
        expect(await screen.findByText('TMB-26-00126 cancelled')).toBeTruthy();
    });

    it('holds with a reason', async () => {
        const calls = ordersApi(operator);
        renderApp('/orders/126');
        await screen.findByRole('heading', { level: 1 });
        fireEvent.click(screen.getByRole('button', { name: 'Hold' }));
        const dialog = await screen.findByRole('dialog');
        fireEvent.change(within(dialog).getByLabelText(/Reason/), { target: { value: 'Call before booking' } });
        fireEvent.click(within(dialog).getByRole('button', { name: 'Put on hold' }));
        await waitFor(() => expect(postedTo(calls, '/orders/126/hold')).toEqual({ reason: 'Call before booking' }));
    });

    it('adds an internal note, private by default', async () => {
        const calls = ordersApi(operator);
        renderApp('/orders/126');
        await screen.findByRole('heading', { level: 1 });
        fireEvent.change(screen.getByLabelText('Internal note'), { target: { value: 'ঠিকানা নিশ্চিত' } });
        fireEvent.click(screen.getByRole('button', { name: 'Add note' }));
        await waitFor(() => expect(postedTo(calls, '/orders/126/notes')).toEqual({ body: 'ঠিকানা নিশ্চিত', is_customer_visible: false }));
    });
});

describe('order helpers', () => {
    it('formats money from the server string without arithmetic', () => {
        expect(formatMoney('6260.00')).toBe('6,260');
        expect(formatMoney('1050.50')).toBe('1,050.5');
    });

    it('labels the age under a day', () => {
        const now = new Date('2026-11-08T08:30:00Z');
        expect(ageLabel('2026-11-08T04:15:00Z', now)).toEqual({ key: 'orders.age.hoursMinutes', vars: { h: 4, m: 15 } });
        expect(ageLabel('2026-11-06T04:15:00Z', now)).toBeNull();
    });

    it('lays the three tracks out from the three status columns', () => {
        const [workflow, money, goods] = buildTracks(detail);
        expect(workflow!.nodes.map((n) => n.state)).toEqual(['current', 'upcoming', 'upcoming', 'upcoming', 'upcoming', 'upcoming', 'upcoming']);
        expect(money!.nodes.map((n) => n.key)).toEqual(['unpaid', 'collected', 'settled']);
        expect(goods!.nodes[0]!.state).toBe('current');

        const returned = buildTracks({
            ...detail,
            status: 'partially_returned',
            payment_status: 'paid',
            fulfillment_status: 'partially_fulfilled',
            timeline: [{ id: 9, type: 'status_changed', field: 'status', from: 'shipped', to: 'partially_returned', description: null, user_id: null, user_name: null, is_customer_visible: false, created_at: '2026-11-07T05:48:00Z' }],
        });
        expect(returned[0]!.nodes.map((n) => `${n.key}:${n.state}`)).toEqual([
            'pending:done',
            'confirmed:done',
            'processing:done',
            'ready_to_ship:done',
            'shipped:done',
            'delivered:skipped',
            'partially_returned:current',
            'completed:upcoming',
        ]);
        // Settlement is never claimed before couriers exist.
        expect(returned[1]!.nodes.find((n) => n.key === 'settled')!.state).toBe('upcoming');
        expect(returned[2]!.nodes.at(-1)!.key).toBe('partially_fulfilled');
    });
});
