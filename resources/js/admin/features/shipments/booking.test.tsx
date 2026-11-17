import { cleanup, fireEvent, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import type { Me } from '@/features/auth/api';
import { mockApi, renderApp } from '@/test/renderApp';
import type { BookingSummary, QueueShipment, ReadyOrder, UnmappedArea } from './bookingApi';

/*
 * Courier booking queue (Figma B · 07): tabs and counts from the server, the per-row courier
 * choice with blocked couriers disabled and the reason shown, selection, and bulk booking through
 * POST /shipments/bulk — one call per courier, each with its own Idempotency-Key (rule 17), only
 * after acknowledging that bulk returns no consignment ids (rule 24d).
 */

afterEach(() => {
    cleanup();
    localStorage.clear();
});

const owner: Me = {
    id: 1,
    name: 'Mominul Islam',
    email: 'owner@example.com',
    avatar_url: null,
    locale: null,
    roles: ['super_admin'],
    permissions: ['dashboard.view', 'orders.view', 'orders.update', 'shipments.view', 'shipments.book'],
    two_factor: { enabled: true, required: true },
    branches: { multi_branch: false, locations: [] },
};
const viewOnly: Me = {
    ...owner,
    roles: ['viewer'],
    permissions: ['dashboard.view', 'orders.view', 'shipments.view'],
};
const nobody: Me = {
    ...owner,
    roles: ['viewer'],
    permissions: ['dashboard.view'],
};

const card = (over: Partial<BookingSummary['couriers'][number]>): BookingSummary['couriers'][number] => ({
    id: 1,
    code: 'steadfast',
    name: 'Steadfast',
    name_bn: 'স্টেডফাস্ট',
    is_active: true,
    is_default: true,
    requires_location_mapping: false,
    supports_bulk: true,
    trust: 'unverified',
    blocked_reason: null,
    bookings_count: 0,
    booked_today: 0,
    awaiting_id: 0,
    webhook: { supported: true, health: 'none', last_received_at: null },
    balance: null,
    balance_checked_at: null,
    ...over,
});

const summary: BookingSummary = {
    production: false,
    cutoff_time: '16:00',
    today: '2026-11-15',
    couriers: [
        card({
            id: 2,
            code: 'pathao',
            name: 'Pathao',
            name_bn: 'পাঠাও',
            is_default: false,
            requires_location_mapping: true,
            trust: 'verified',
            bookings_count: 47,
            balance: '3240.00',
            balance_checked_at: '2026-11-15T07:40:00Z',
            webhook: {
                supported: true,
                health: 'healthy',
                last_received_at: new Date().toISOString(),
            },
        }),
        card({}),
    ],
    counts: { ready: 3, submitted: 2, booked_today: 1, failed: 1, unmapped: 1 },
};

const row = (over: Partial<ReadyOrder>): ReadyOrder => ({
    order_id: 125,
    number: 'TMB-26-00125',
    status: 'ready_to_ship',
    ready_since: '2026-11-06T04:00:00Z',
    recipient_name: 'Abdullah Al Mamun',
    district: { id: 40, name: 'Khulna', name_bn: 'খুলনা' },
    upazila: null,
    zone: 'outside_dhaka',
    weight_grams: 920,
    weight_missing: false,
    item_count: 2,
    cod_amount: '2310.00',
    couriers: [
        { provider_id: 2, location_status: 'mapped', blocked_reason: null },
        {
            provider_id: 1,
            location_status: 'not_required',
            blocked_reason: null,
        },
    ],
    suggested_provider_id: 1,
    ...over,
});

const ready: ReadyOrder[] = [
    row({}),
    row({
        order_id: 133,
        number: 'TMB-26-00133',
        recipient_name: 'Riya Saha',
        // Confirmed, not packed yet: a courier may still take it (owner, 2026-11-15).
        status: 'confirmed',
        ready_since: '2026-11-14T04:00:00Z',
        weight_grams: 1200,
        cod_amount: '1780.00',
        zone: 'dhaka_suburb',
    }),
    row({
        order_id: 134,
        number: 'TMB-26-00134',
        recipient_name: 'Habib Ullah',
        weight_grams: 0,
        weight_missing: true,
        couriers: [
            {
                provider_id: 2,
                location_status: 'unmapped',
                blocked_reason: 'unmapped_location',
            },
            {
                provider_id: 1,
                location_status: 'not_required',
                blocked_reason: 'missing_weight',
            },
        ],
        suggested_provider_id: null,
    }),
];

const shipment = (over: Partial<QueueShipment['shipment']>): QueueShipment['shipment'] =>
    ({
        id: 9,
        number: 'SHP-26-000009',
        order_id: 200,
        order: { id: 200, number: 'TMB-26-00200', status: 'ready_to_ship' },
        provider: {
            code: 'steadfast',
            name: 'Steadfast',
            name_bn: 'স্টেডফাস্ট',
        },
        status: 'submitted',
        provider_status_raw: null,
        consignment_id: null,
        tracking_code: null,
        tracking_url: null,
        courier_location_id: null,
        weight_grams: 920,
        item_count: 2,
        cod_amount: '2310.00',
        declared_value: '2810.00',
        charge: null,
        cod_fee: null,
        return_charge: null,
        total_cost: null,
        cod_collected: null,
        delivery_attempts: 0,
        booking_attempts: 1,
        failure_code: null,
        error_message: null,
        needs_attention: false,
        dispatched: false,
        note: null,
        rider: null,
        booked_at: null,
        dispatched_at: null,
        picked_up_at: null,
        delivered_at: null,
        returned_at: null,
        cancelled_at: null,
        last_attempt_at: null,
        created_at: '2026-11-15T05:00:00Z',
        ...over,
    } as QueueShipment['shipment']);

const unmapped: UnmappedArea[] = [
    {
        provider: { id: 2, code: 'pathao', name: 'Pathao', name_bn: 'পাঠাও' },
        reason: 'ambiguous',
        district: { id: 40, name: 'Khulna', name_bn: 'খুলনা' },
        upazila: { id: 401, name: 'Sonadanga', name_bn: 'সোনাডাঙ্গা' },
        district_name: 'Khulna',
        upazila_name: 'Sonadanga',
        orders_count: 1,
        order_numbers: ['TMB-26-00134'],
    },
];

type Init = RequestInit | undefined;

function queueApi(me: Me) {
    return mockApi((url, init) => {
        if (url.endsWith('/auth/me')) return { status: 200, body: me };
        if (url.endsWith('/shipments/booking-queue')) return { status: 200, body: summary };
        if (url.includes('/shipments/booking-queue/ready'))
            return {
                status: 200,
                body: { data: ready, meta: { next_cursor: null } },
            };
        if (url.includes('/shipments/booking-queue/shipments')) {
            return {
                status: 200,
                body: {
                    data: [
                        {
                            shipment: shipment({}),
                            submitted_at: '2026-11-15T05:00:00Z',
                            recipient_name: 'Nadia',
                            district_name: 'Dhaka',
                            upazila_name: 'Mirpur',
                        },
                    ],
                    meta: { next_cursor: null },
                },
            };
        }
        if (url.includes('/shipments/booking-queue/unmapped')) return { status: 200, body: { data: unmapped } };
        if (url.endsWith('/shipments/bulk') && (init as Init)?.method === 'POST') {
            const ids = (JSON.parse(String(init?.body)) as { order_ids: number[] }).order_ids;
            return {
                status: 200,
                body: {
                    data: ids.map((id) => ({
                        order_id: id,
                        result: 'requested',
                        shipment_id: id + 1000,
                        message: null,
                    })),
                    meta: { requested: ids.length, failed: 0 },
                },
            };
        }
        return { status: 200, body: { data: [] } };
    });
}

const bulkCalls = (calls: ReturnType<typeof mockApi>) =>
    calls.mock.calls
        .filter(([url, init]) => String(url).endsWith('/shipments/bulk') && (init as Init)?.method === 'POST')
        .map(([, init]) => ({
            body: JSON.parse(String((init as Init)?.body)) as {
                order_ids: number[];
                provider: string;
            },
            key: ((init as Init)?.headers as Record<string, string>)['Idempotency-Key'],
        }));

describe('courier booking queue', () => {
    it('shows the live facts, the courier cards and every tab count from the server', async () => {
        queueApi(owner);
        renderApp('/shipments/booking');

        expect(await screen.findByText(/Ready-to-ship orders · Pathao live · Steadfast untested · today’s pickup cut-off 4:00 pm/)).toBeTruthy();
        const tabs = screen.getByRole('navigation', { name: 'Booking queue' });
        expect(within(tabs).getByRole('button', { name: /Ready to ship\s*3/ })).toBeTruthy();
        expect(
            within(tabs).getByRole('button', {
                name: /Submitted — awaiting ID\s*2/,
            })
        ).toBeTruthy();
        expect(within(tabs).getByRole('button', { name: /Unmapped areas\s*1/ })).toBeTruthy();

        const cards = screen.getByRole('list', { name: 'Couriers' });
        expect(within(cards).getByText(/Live · 47 bookings/)).toBeTruthy();
        // A stored balance shows; none stored shows nothing — never ৳0.
        expect(within(cards).getByText('৳3,240')).toBeTruthy();
        expect(within(cards).getByText(/no webhook received yet/)).toBeTruthy();
        expect(within(cards).queryByText('৳0')).toBeNull();
        // A confirmed order not packed yet is ready too, and says so.
        expect(await screen.findByText(/^confirmed .+ · not packed yet$/)).toBeTruthy();
        // The rule-24d notice is always on the ready tab.
        expect(screen.getByRole('note').textContent).toContain('Bulk booking returns no consignment IDs');
    });

    it('disables a blocked courier with its reason and makes the row unselectable', async () => {
        queueApi(owner);
        renderApp('/shipments/booking');

        const blocked = await screen.findByRole('checkbox', {
            name: 'Select TMB-26-00134',
        });
        expect((blocked as HTMLInputElement).disabled).toBe(true);
        const select = screen.getByRole('combobox', {
            name: 'Courier for TMB-26-00134',
        }) as HTMLSelectElement;
        const options = [...select.options];
        expect(options.find((o) => o.textContent?.startsWith('Pathao'))?.disabled).toBe(true);
        expect(options.find((o) => o.textContent?.startsWith('Pathao'))?.textContent).toContain('area not mapped');
        expect(options.find((o) => o.textContent?.startsWith('Steadfast'))?.textContent).toContain('a product has no weight');
        expect(screen.getAllByText('No courier can take this order').length).toBeGreaterThan(0);

        // An open row defaults to the suggested (default) courier and can be selected.
        const open = screen.getByRole('checkbox', {
            name: 'Select TMB-26-00125',
        }) as HTMLInputElement;
        expect(open.disabled).toBe(false);
        expect(
            (
                screen.getByRole('combobox', {
                    name: 'Courier for TMB-26-00125',
                }) as HTMLSelectElement
            ).value
        ).toBe('1');
    });

    it('bulk-books the selection: one call per courier, each with its own Idempotency-Key, only after the 24d acknowledgement', async () => {
        const calls = queueApi(owner);
        renderApp('/shipments/booking');

        fireEvent.click(await screen.findByRole('checkbox', { name: 'Select TMB-26-00125' }));
        fireEvent.click(screen.getByRole('checkbox', { name: 'Select TMB-26-00133' }));
        // Send 00133 with Pathao instead of the default.
        fireEvent.change(screen.getByRole('combobox', { name: 'Courier for TMB-26-00133' }), { target: { value: '2' } });

        expect(screen.getByText('2 selected')).toBeTruthy();
        fireEvent.click(screen.getByRole('button', { name: /Book 2 selected/ }));

        const dialog = await screen.findByRole('dialog', {
            name: 'Book 2 orders',
        });
        expect(within(dialog).getByText('Pathao: 1 orders')).toBeTruthy();
        expect(within(dialog).getByText('Steadfast: 1 orders')).toBeTruthy();
        const submit = within(dialog).getByRole('button', {
            name: /Submit 2 bookings/,
        }) as HTMLButtonElement;
        expect(submit.disabled).toBe(true);
        fireEvent.click(within(dialog).getByRole('checkbox'));
        fireEvent.click(submit);

        await waitFor(() => expect(bulkCalls(calls)).toHaveLength(2));
        const sent = bulkCalls(calls);
        expect(sent.map((c) => c.body)).toEqual([
            { order_ids: [133], provider: 'pathao' },
            { order_ids: [125], provider: 'steadfast' },
        ]);
        expect(sent[0]!.key).toMatch(/^[0-9a-f-]{36}$/);
        expect(sent[0]!.key).not.toBe(sent[1]!.key);
        expect(await screen.findByText(/submitted to Steadfast — awaiting consignment IDs/)).toBeTruthy();
    });

    it('lets a view-only user see the queue but not select or book', async () => {
        queueApi(viewOnly);
        renderApp('/shipments/booking');

        expect(await screen.findByText('TMB-26-00125')).toBeTruthy();
        expect(screen.queryByRole('checkbox', { name: 'Select TMB-26-00125' })).toBeNull();
        expect(screen.queryByRole('button', { name: 'Book TMB-26-00125' })).toBeNull();
    });

    it('opens the existing booking drawer for one order', async () => {
        queueApi(owner);
        renderApp('/shipments/booking');

        fireEvent.click(await screen.findByRole('button', { name: 'Book TMB-26-00125' }));
        await waitFor(() => expect(document.querySelector('dialog[open]')).not.toBeNull());
    });

    it('shows submitted shipments as awaiting an ID, never booked', async () => {
        queueApi(owner);
        renderApp('/shipments/booking?tab=submitted');

        expect(await screen.findByText('TMB-26-00200')).toBeTruthy();
        expect(screen.getAllByText('Submitted — awaiting ID').length).toBeGreaterThan(0);
        expect(screen.getByText('awaiting ID')).toBeTruthy();
        expect(screen.queryByText('Booked')).toBeNull();
    });

    it('lists unmapped areas with a link to the mapping screen for that courier', async () => {
        queueApi(owner);
        renderApp('/shipments/booking?tab=unmapped');

        const link = await screen.findByRole('link', { name: 'Map area' });
        expect(link.getAttribute('href')).toBe('/shipments/locations?provider=pathao');
        expect(screen.getByText('Sonadanga, Khulna')).toBeTruthy();
        // Listed for several mapped zones too, with the reason (owner, 2026-11-15).
        expect(screen.getByText('Several zones mapped — bulk cannot choose')).toBeTruthy();
    });

    it('refuses a user without shipments.view', async () => {
        queueApi(nobody);
        renderApp('/shipments/booking');
        expect(await screen.findByText('You do not have access to this page.')).toBeTruthy();
        expect(screen.queryByText('TMB-26-00125')).toBeNull();
    });
});
