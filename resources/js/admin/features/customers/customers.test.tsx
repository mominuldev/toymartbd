import { cleanup, fireEvent, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { mockApi, renderApp, viewer } from '@/test/renderApp';
import type { Me } from '@/features/auth/api';
import type { CustomerList, CustomerNote, CustomerProfile, CustomerRow, MessageConsents } from './api';

afterEach(() => {
    cleanup();
    localStorage.clear();
});

const operator: Me = {
    ...viewer,
    roles: ['order_operator'],
    permissions: ['dashboard.view', 'orders.view', 'orders.create', 'customers.view', 'customers.update', 'customers.view_pii', 'risk.view', 'reports.view'],
};
const reader: Me = { ...viewer, permissions: ['dashboard.view', 'customers.view'] };

const row = (over: Partial<CustomerRow> = {}): CustomerRow => ({
    id: 1,
    name: 'Jahangir Alam',
    phone_masked: '017****8046',
    has_account: true,
    source: 'web',
    tags: [],
    district: 'Dhaka',
    orders_count: 6,
    delivered_orders_count: 6,
    lifetime_value: '24380.00',
    segment: 'vip',
    risk: { score: 5, level: 'low', status: 'normal' },
    last_order_at: '2026-11-02T04:00:00+00:00',
    created_at: '2026-02-12T04:00:00+00:00',
    ...over,
});

const list: CustomerList = {
    data: [row(), row({ id: 2, name: 'Shahriar Kabir', phone_masked: '017****2318', segment: 'at_risk', lifetime_value: '4900.00', risk: { score: 80, level: 'high', status: 'watch' } }), row({ id: 3, name: 'Tanzila Akter', segment: 'new', risk: null })],
    meta: {
        current_page: 1,
        last_page: 2,
        from: 1,
        to: 3,
        total: 28,
        counts: { all: 28, new: 8, active: 11, vip: 3, at_risk: 4, lapsed: 1, no_orders: 1, blacklisted: 2 },
        thresholds: { vip_min_lifetime_value: '15000.00', at_risk_after_days: 90, lapsed_after_days: 180 },
    },
};

const profile = (over: Partial<CustomerProfile> = {}): CustomerProfile => ({
    id: 1,
    name: 'Jahangir Alam',
    pii_masked: false,
    phone: '+8801711258046',
    email: 'jahangir.alam@example.com',
    has_account: true,
    date_of_birth: '1990-05-04',
    has_date_of_birth: true,
    preferred_language: 'en',
    source: 'web',
    status: 'active',
    tags: [],
    segment: 'vip',
    created_at: '2026-02-12T04:00:00+00:00',
    first_order_at: '2026-02-12T04:00:00+00:00',
    last_order_at: '2026-11-02T04:00:00+00:00',
    stats: {
        orders_count: 6,
        delivered_orders_count: 6,
        returned_orders_count: 0,
        cancelled_orders_count: 1,
        lifetime_value: '24380.00',
        delivered_revenue: '24380.00',
        refunds: '0.00',
        average_order_value: '4063.33',
        delivery_success_rate: '100.00',
    },
    addresses: [
        { id: 9, label: null, type: 'home', is_default_shipping: true, recipient_name: 'Jahangir Alam', phone: '+8801711258046', area: 'Dhanmondi', upazila: null, district: 'Dhaka', district_bn: 'ঢাকা', division: 'Dhaka', address_line: 'House 22, Road 9/A', landmark: 'beside Abahani field', postcode: '1209' },
    ],
    orders: [
        {
            id: 118,
            number: 'TMB-26-00118',
            status: 'delivered',
            payment_status: 'paid',
            grand_total: '5060.00',
            placed_at: '2026-11-02T04:00:00+00:00',
            delivered_at: '2026-11-04T04:00:00+00:00',
            items_count: 1,
            items: [{ name: 'Kids Tricycle', name_bn: null, variant: null, quantity: 1 }],
        },
    ],
    orders_truncated: false,
    risk: {
        ours: { stored: true, internal_score: 10, level: 'low', breakdown: [], score_computed_at: '2026-11-04T03:27:00+00:00', counts: { total_orders: 7, delivered: 6, cancelled: 1, returned: 0, failed_delivery: 0, refused: 0 }, last_order_at: null },
        status: { value: 'normal', reason: null, set_by: null, set_at: null },
    },
    notes_count: 1,
    pinned_note: { id: 4, body: 'Buys birthday gifts in Feb and Aug', is_pinned: true, user_id: 1, user_name: 'Mominul', created_at: '2026-09-05T04:00:00+00:00' },
    ...over,
});

const note: CustomerNote = { id: 4, body: 'Buys birthday gifts in Feb and Aug', is_pinned: true, user_id: 1, user_name: 'Mominul', created_at: '2026-09-05T04:00:00+00:00' };

const noConsent: MessageConsents = { birthday: { consented: false, consented_at: null, withdrawn_at: null, source: null, recorded_by: null } };
const givenByStaff: MessageConsents = { birthday: { consented: true, consented_at: '2026-11-10T04:15:00+00:00', withdrawn_at: null, source: 'admin', recorded_by: { id: 7, name: 'Nadia' } } };

function api(me: Me = operator, detail: CustomerProfile = profile(), consents: MessageConsents = noConsent) {
    return mockApi((url, init) => {
        if (url.endsWith('/auth/me')) return { status: 200, body: me };
        if (url.includes('/notifications/customers/1/consents') && init?.method === 'PUT') {
            const body = JSON.parse(String(init.body)) as { birthday: boolean };
            return { status: 200, body: body.birthday ? givenByStaff : { birthday: { ...givenByStaff.birthday, consented: false, withdrawn_at: '2026-11-13T05:00:00+00:00' } } };
        }
        if (url.includes('/notifications/customers/1/consents')) return { status: 200, body: consents };
        if (url.includes('/customers/1/date-of-birth') && init?.method === 'PUT') {
            const body = JSON.parse(String(init.body)) as { date_of_birth: string | null };
            return { status: 200, body: { id: 1, date_of_birth: body.date_of_birth } };
        }
        if (url.includes('/customers/1/notes') && init?.method === 'POST') return { status: 201, body: { ...note, id: 5, body: JSON.parse(String(init.body)).body } };
        if (url.includes('/customers/1/notes')) return { status: 200, body: { data: [note] } };
        if (url.includes('/notifications/customers/1/preferences'))
            return { status: 200, body: { data: [{ channel: 'whatsapp', category: 'order_updates', enabled: true, is_default: true, can_opt_out: true }, { channel: 'sms', category: 'marketing', enabled: false, is_default: true, can_opt_out: true }] } };
        if (url.includes('/customers/1')) return { status: 200, body: detail };
        if (url.includes('/customers')) return { status: 200, body: list };
        if (url.includes('/orders/compose/districts')) return { status: 200, body: { data: [{ id: 1, name: 'Dhaka', name_bn: 'ঢাকা' }] } };
        if (url.includes('/inventory/low-stock-count')) return { status: 200, body: { location_id: 1, count: 0 } };
        return { status: 404 };
    });
}

const urls = (calls: ReturnType<typeof mockApi>) => calls.mock.calls.map(([url]) => decodeURIComponent(String(url)));

describe('customers list', () => {
    it('shows the server’s tiles and rows: masked phone, ledger lifetime value, segment and risk', async () => {
        api();
        renderApp('/customers');

        expect(await screen.findByText('28 customers · phone is the identity')).toBeTruthy();
        expect(screen.getByRole('heading', { name: 'Customers' })).toBeTruthy();
        const tiles = screen.getByRole('group', { name: 'Customer segments' });
        expect(within(tiles).getByRole('button', { name: 'VIP: 3 · LTV over ৳15,000' })).toBeTruthy();
        expect(within(tiles).getByRole('button', { name: 'At risk: 4 · no order in 90 days' })).toBeTruthy();

        const jahangir = screen.getByRole('row', { name: /Jahangir Alam/ });
        expect(within(jahangir).getByText('017****8046')).toBeTruthy();
        expect(within(jahangir).getByText('24,380')).toBeTruthy();
        expect(within(jahangir).getByText('VIP')).toBeTruthy();
        expect(within(jahangir).getByText('Low 5')).toBeTruthy();
        const shahriar = screen.getByRole('row', { name: /Shahriar Kabir/ });
        expect(within(shahriar).getByText('High 80')).toBeTruthy();
        expect(within(shahriar).getByText('Watch list')).toBeTruthy();
        expect(within(screen.getByRole('row', { name: /Tanzila Akter/ })).getByText('Not scored')).toBeTruthy();
        expect(screen.getByText('Showing 1–3 of 28')).toBeTruthy();
        expect(document.body.textContent ?? '').not.toContain('1711258046');
    });

    it('sends search, tile and filter choices to the server as allowlisted parameters', async () => {
        const calls = api();
        renderApp('/customers');
        await screen.findByRole('row', { name: /Jahangir Alam/ });

        fireEvent.click(screen.getByRole('button', { name: /^VIP: 3/ }));
        await waitFor(() => expect(urls(calls).some((u) => u.includes('/customers?filter[segment]=vip'))).toBe(true));

        fireEvent.change(screen.getByLabelText('Search customers'), { target: { value: '01712' } });
        await waitFor(() => expect(urls(calls).some((u) => u.includes('filter[search]=01712'))).toBe(true));

        fireEvent.change(screen.getByLabelText('Risk'), { target: { value: 'blacklist' } });
        await waitFor(() => expect(urls(calls).some((u) => u.includes('filter[risk]=blacklist'))).toBe(true));
        fireEvent.change(screen.getByLabelText('District'), { target: { value: '1' } });
        await waitFor(() => expect(urls(calls).some((u) => u.includes('filter[district_id]=1'))).toBe(true));
        fireEvent.change(screen.getByLabelText('Sort'), { target: { value: '-lifetime_value' } });
        await waitFor(() => expect(urls(calls).some((u) => u.includes('sort=-lifetime_value'))).toBe(true));
        fireEvent.click(screen.getByRole('button', { name: /Next/ }));
        await waitFor(() => expect(urls(calls).some((u) => u.includes('page=2'))).toBe(true));
    });

    it('renders in Bangla with Western digits', async () => {
        api();
        renderApp('/customers', { locale: 'bn' });
        expect(await screen.findByRole('row', { name: /Jahangir Alam/ })).toBeTruthy();
        expect(screen.getByText('ভিআইপি', { selector: 'span.inline-flex' })).toBeTruthy();
        expect(document.body.textContent ?? '').not.toMatch(/[০-৯]/);
    });
});

describe('customer profile', () => {
    it('lays out the stats, orders, addresses, our risk score and the pinned note', async () => {
        api();
        renderApp('/customers/1');

        expect(await screen.findByRole('heading', { name: 'Jahangir Alam' })).toBeTruthy();
        expect(screen.getByText(/\+8801711258046 · jahangir\.alam@example\.com · customer since 12 Feb 2026 · prefers English/)).toBeTruthy();
        expect(screen.getByText('6 delivered · AOV ৳4,063.33')).toBeTruthy();
        expect(screen.getByText('100%')).toBeTruthy();
        expect(screen.getByRole('row', { name: /TMB-26-00118/ })).toBeTruthy();
        expect(screen.getByText('Delivered 04 Nov')).toBeTruthy();
        expect(screen.getByText(/House 22, Road 9\/A, Dhanmondi, Dhaka · beside Abahani field/)).toBeTruthy();
        expect(screen.getByRole('heading', { name: 'Toy Mart risk score' })).toBeTruthy();
        expect(screen.getByRole('heading', { name: 'Courier-verified delivery record' })).toBeTruthy();
        expect(screen.getByText('Buys birthday gifts in Feb and Aug')).toBeTruthy();
        expect(screen.getByRole('link', { name: /Phone risk lookup/ }).getAttribute('href')).toBe('/risk/lookup?phone=%2B8801711258046');
        expect(screen.getByText('WhatsApp')).toBeTruthy();
    });

    it('shows the masked profile to a role without customers.view_pii, without risk or note entry', async () => {
        api(reader, profile({ pii_masked: true, phone: '017****8046', email: 'j***@example.com', risk: null, addresses: [{ ...profile().addresses[0]!, address_line: null, landmark: null, recipient_name: null, postcode: null, phone: '017****8046' }] }));
        renderApp('/customers/1');

        expect(await screen.findByText('Phone, e-mail and street addresses are masked for your role.')).toBeTruthy();
        expect(screen.getByText(/017\*\*\*\*8046/)).toBeTruthy();
        expect(screen.getByText('Street line and landmark hidden for your role')).toBeTruthy();
        expect(screen.queryByRole('heading', { name: 'Toy Mart risk score' })).toBeNull();
        expect(screen.queryByRole('link', { name: /Phone risk lookup/ })).toBeNull();
        expect(document.body.textContent ?? '').not.toContain('Road 9/A');

        fireEvent.click(screen.getByRole('button', { name: /Notes/ }));
        expect(await screen.findByText('Buys birthday gifts in Feb and Aug', { selector: 'li p' })).toBeTruthy();
        expect(screen.queryByRole('button', { name: 'Add note' })).toBeNull();
    });

    it('appends a note through the API', async () => {
        const calls = api();
        renderApp('/customers/1?tab=notes');

        fireEvent.change(await screen.findByLabelText('New note'), { target: { value: 'Prefers evening calls' } });
        fireEvent.click(screen.getByLabelText('Pin to the profile'));
        fireEvent.click(screen.getByRole('button', { name: 'Add note' }));

        await waitFor(() => {
            const post = calls.mock.calls.find(([url, init]) => String(url).includes('/customers/1/notes') && init?.method === 'POST');
            expect(post && JSON.parse(String(post[1]?.body))).toEqual({ body: 'Prefers evening calls', is_pinned: true });
        });
        expect(await screen.findByText('Note added')).toBeTruthy();
    });
});

describe('birthday messages panel', () => {
    const put = (calls: ReturnType<typeof mockApi>, part: string) => calls.mock.calls.filter(([url, init]) => String(url).includes(part) && init?.method === 'PUT').map(([, init]) => JSON.parse(String(init?.body)) as unknown);

    it('records consent only after staff tick that the customer agreed', async () => {
        const calls = api();
        renderApp('/customers/1');

        const panel = (await screen.findByRole('heading', { name: 'Birthday messages' })).closest('section')!;
        expect(within(panel).getByText('04 May 1990')).toBeTruthy();
        expect(await within(panel).findByText('Not opted in')).toBeTruthy();
        const record = within(panel).getByRole('button', { name: 'Record consent' }) as HTMLButtonElement;
        expect(record.disabled).toBe(true);
        expect((within(panel).getByRole('checkbox') as HTMLInputElement).checked).toBe(false); // never pre-ticked

        fireEvent.click(within(panel).getByLabelText(/The customer agreed to a birthday message/));
        fireEvent.click(record);

        await waitFor(() => expect(put(calls, '/consents')).toEqual([{ birthday: true, customer_confirmed: true }]));
        expect(await within(panel).findByText('Opted in')).toBeTruthy();
        expect(within(panel).getByText(/Given 10 Nov 2026, 10:15 am · recorded by Nadia/)).toBeTruthy();
    });

    it('withdraws consent and shows when, and keeps when it was given', async () => {
        const calls = api(operator, profile(), givenByStaff);
        renderApp('/customers/1');

        const panel = (await screen.findByRole('heading', { name: 'Birthday messages' })).closest('section')!;
        fireEvent.click(await within(panel).findByRole('button', { name: 'Withdraw consent' }));

        await waitFor(() => expect(put(calls, '/consents')).toEqual([{ birthday: false }]));
        expect(await within(panel).findByText('Withdrawn')).toBeTruthy();
        expect(within(panel).getByText(/Withdrawn 13 Nov 2026, 11:00 am · recorded by Nadia\. Last given 10 Nov 2026\./)).toBeTruthy();
    });

    it('warns when a consent has no date of birth, and edits the date through the API', async () => {
        const calls = api(operator, profile({ date_of_birth: null, has_date_of_birth: false }), givenByStaff);
        renderApp('/customers/1');

        const panel = (await screen.findByRole('heading', { name: 'Birthday messages' })).closest('section')!;
        expect(await within(panel).findByText('No date of birth on file, so nothing will be sent until one is added.')).toBeTruthy();
        expect(within(panel).getByText('Not recorded')).toBeTruthy();

        fireEvent.click(within(panel).getByRole('button', { name: 'Add' }));
        fireEvent.change(within(panel).getByLabelText('Date of birth'), { target: { value: '2016-02-29' } });
        fireEvent.click(within(panel).getByRole('button', { name: 'Save date' }));
        await waitFor(() => expect(put(calls, '/date-of-birth')).toEqual([{ date_of_birth: '2016-02-29' }]));
        expect(await screen.findByText('Date of birth saved')).toBeTruthy();
    });

    it('hides the date and every control from a role without customers.view_pii or customers.update', async () => {
        const calls = api(reader, profile({ pii_masked: true, phone: '017****8046', email: null, risk: null, date_of_birth: null, has_date_of_birth: true }), givenByStaff);
        renderApp('/customers/1');

        const panel = (await screen.findByRole('heading', { name: 'Birthday messages' })).closest('section')!;
        expect(await within(panel).findByText('Opted in')).toBeTruthy();
        expect(within(panel).getByText('Recorded — hidden for your role')).toBeTruthy();
        expect(within(panel).queryByRole('button')).toBeNull();
        expect(within(panel).queryByRole('checkbox')).toBeNull();
        expect(document.body.textContent ?? '').not.toContain('1990');
        expect(put(calls, '/')).toEqual([]);
    });

    it('renders the panel in Bangla with Western digits', async () => {
        api(operator, profile(), givenByStaff);
        renderApp('/customers/1', { locale: 'bn' });
        const panel = (await screen.findByRole('heading', { name: 'জন্মদিনের বার্তা' })).closest('section')!;
        expect(await within(panel).findByText('সম্মতি দিয়েছেন')).toBeTruthy();
        expect(panel.textContent ?? '').not.toMatch(/[০-৯]/);
    });
});
