import type { ReactNode } from 'react';
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router';
import { afterEach, describe, expect, it } from 'vitest';
import { createQueryClient } from '@/lib/queryClient';
import { I18nProvider } from '@/i18n/I18nProvider';
import { ToastProvider } from '@/components/ui/Toast';
import { CourierBookingPanel } from './booking/CourierBookingPanel';
import { ShipmentCard } from './shipment/ShipmentCard';
import type { Me } from '@/features/auth/api';
import { mockApi, renderApp } from '@/test/renderApp';
import type { CourierProvider, Shipment, ShipmentQuote } from './api';

/*
 * P6 couriers: settings (credentials write-only, rule 20), the booking panel (every figure
 * from the server, rule 1; bulk says "submit", rule 24d), the shipment card and the
 * failed-bookings queue.
 */

function mount(node: ReactNode) {
    const client = createQueryClient();
    client.setDefaultOptions({ queries: { ...client.getDefaultOptions().queries, retry: false } });
    return render(
        <QueryClientProvider client={client}>
            <I18nProvider initialLocale="en">
                <ToastProvider>
                    <MemoryRouter>{node}</MemoryRouter>
                </ToastProvider>
            </I18nProvider>
        </QueryClientProvider>,
    );
}

afterEach(() => {
    cleanup();
    localStorage.clear();
});

const owner: Me = {
    id: 1,
    name: 'Mominul Islam',
    email: 'owner@example.com',
    locale: null,
    roles: ['super_admin'],
    permissions: ['dashboard.view', 'orders.view', 'orders.update', 'shipments.view', 'shipments.book', 'shipments.cancel', 'shipments.manage_rates', 'settings.manage_integrations', 'customers.view_pii', 'settings.view'],
    two_factor: { enabled: true, required: true },
};
/** Rates without credentials. Since 2026-11-13 a manager holds both; this is a custom role's view. */
const ratesOnly: Me = { ...owner, roles: ['rates_only'], permissions: owner.permissions.filter((p) => p !== 'settings.manage_integrations') };

const rates = { base_charge_dhaka: '70.00', base_charge_suburb: '100.00', base_charge_outside: '130.00', included_weight_grams: 1000, per_kg_charge: '20.00', cod_fee_percent: '1.00', return_charge: '0.00' };

const steadfast: CourierProvider = {
    id: 1,
    code: 'steadfast',
    name: 'Steadfast',
    name_bn: 'স্টেডফাস্ট',
    is_active: true,
    is_default: true,
    verified: false,
    trust: 'unverified',
    requires_location_mapping: false,
    supports_bulk: true,
    supports_webhook: true,
    credential_fields: [
        { key: 'api_key', secret: true, set: true, hint: '••••1111' },
        { key: 'secret_key', secret: true, set: false, hint: null },
    ],
    credentials_complete: false,
    credentials_updated_at: '2026-11-10T04:00:00Z',
    webhook_secret_set: false,
    webhook_url: 'https://toymartbd.test/api/v1/webhooks/couriers/steadfast',
    rates,
    rate_card_updated_at: null,
    balance: null,
    balance_checked_at: null,
    can: { update_rates: true, update_credentials: true },
};
const pathao: CourierProvider = {
    ...steadfast,
    id: 2,
    code: 'pathao',
    name: 'Pathao',
    name_bn: 'পাঠাও',
    is_active: false,
    is_default: false,
    trust: 'not_connected',
    requires_location_mapping: true,
    credential_fields: ['client_id', 'client_secret', 'username', 'password', 'store_id'].map((key) => ({ key, secret: key.includes('secret') || key === 'password', set: false, hint: null })),
    rates: { ...rates, included_weight_grams: 2000, per_kg_charge: '15.00' },
};

const quoteProvider = (p: CourierProvider, blocked: ShipmentQuote['providers'][number]['blocked_reason'] = null): ShipmentQuote['providers'][number] => ({
    id: p.id,
    code: p.code,
    name: p.name,
    name_bn: p.name_bn,
    is_active: p.is_active,
    is_default: p.is_default,
    trust: p.trust,
    bookings_count: 0,
    requires_location_mapping: p.requires_location_mapping,
    supports_bulk: true,
    balance: null,
    balance_checked_at: null,
    rate_card_updated_at: '2026-10-02T04:00:00Z',
    rates: p.rates,
    blocked_reason: blocked,
});

const estimate = { zone: 'outside_dhaka', weight_grams: 920, base_charge: '130.00', weight_surcharge: '0.00', delivery_charge: '130.00', cod_fee_percent: '1.00', cod_fee: '23.10', return_charge: '70.00', total_if_delivered: '153.10', cost_if_refused: '200.00' };

function orderQuote(id: number, number: string): ShipmentQuote['orders'][number] {
    return {
        order_id: id,
        found: true,
        number,
        status: 'ready_to_ship',
        can_ship: true,
        recipient_name: 'রাহেলা খাতুন',
        district: { id: 8, name: 'Chattogram', name_bn: 'চট্টগ্রাম' },
        upazila: null,
        zone: 'outside_dhaka',
        item_count: 2,
        variant_weight_grams: 920,
        weight_grams: 920,
        cod_amount: '2310.00',
        declared_value: '2810.00',
        quotes: [
            { provider_id: 1, location_status: 'not_required', location_options: [], blocked_reason: null, estimate },
            { provider_id: 2, location_status: 'unmapped', location_options: [], blocked_reason: 'unmapped_location', estimate },
        ],
    };
}

const quote = (ids: number[]): ShipmentQuote => ({
    providers: [quoteProvider(steadfast), quoteProvider(pathao, 'inactive')],
    orders: ids.map((id) => orderQuote(id, `TMB-26-00${id}`)),
    totals: [{ provider_id: 1, parcels: ids.length, weight_grams: 920 * ids.length, cod_amount: ids.length === 1 ? '2310.00' : '4620.00', total_if_delivered: ids.length === 1 ? '153.10' : '306.20' }],
});

const shipment = (over: Partial<Shipment> = {}): Shipment => ({
    id: 40,
    number: 'SHP-26-000040',
    order_id: 126,
    order: { id: 126, number: 'TMB-26-00126', status: 'ready_to_ship' },
    provider: { code: 'steadfast', name: 'Steadfast', name_bn: 'স্টেডফাস্ট' },
    status: 'booked',
    provider_status_raw: null,
    consignment_id: '1234567',
    tracking_code: '15BAEB8A',
    tracking_url: 'https://steadfast.com.bd/t/15BAEB8A',
    weight_grams: 920,
    item_count: 2,
    cod_amount: '2310.00',
    declared_value: '2810.00',
    charge: '130.00',
    cod_fee: '23.10',
    return_charge: null,
    failure_code: null,
    error_message: null,
    booking_attempts: 1,
    courier_location_id: null,
    total_cost: null,
    cod_collected: null,
    delivery_attempts: 0,
    needs_attention: false,
    dispatched: true,
    note: null,
    rider: null,
    dispatched_at: null,
    picked_up_at: null,
    delivered_at: null,
    returned_at: null,
    cancelled_at: null,
    last_attempt_at: null,
    booked_at: '2026-11-13T03:05:00Z',
    created_at: '2026-11-13T03:00:00Z',
    events: [],
    ...over,
});

type Init = RequestInit | undefined;
const bodyOf = (calls: ReturnType<typeof mockApi>, suffix: string, method = 'POST') => {
    const call = [...calls.mock.calls].reverse().find(([url, init]) => String(url).includes(suffix) && (init as Init)?.method === method);
    return call ? (JSON.parse(String((call[1] as Init)?.body)) as Record<string, unknown>) : null;
};
const headerOf = (calls: ReturnType<typeof mockApi>, suffix: string) => {
    const call = [...calls.mock.calls].reverse().find(([url, init]) => String(url).includes(suffix) && (init as Init)?.method === 'POST');
    return ((call?.[1] as Init)?.headers as Record<string, string> | undefined)?.['Idempotency-Key'] ?? null;
};

function settingsApi(me: Me, providers: CourierProvider[] = [steadfast, pathao]) {
    return mockApi((url, init) => {
        if (url.endsWith('/auth/me')) return { status: 200, body: me };
        if (url.endsWith('/settings/couriers')) return { status: 200, body: { data: providers } };
        if (url.endsWith('/settings/delivery')) return { status: 200, body: { free_shipping_threshold: init?.method === 'PUT' ? '2500.00' : '3000.00' } };
        if (url.endsWith('/settings/couriers/1/credentials')) return { status: 200, body: { ...steadfast, credential_fields: steadfast.credential_fields.map((f) => ({ ...f, set: true, hint: '••••2222' })), trust: 'unverified' } };
        if (url.endsWith('/settings/couriers/1/rates')) return { status: 200, body: { ...steadfast, rates: { ...rates, base_charge_outside: '140.00' } } };
        return { status: 404, body: { type: 'https://toymartbd.com/problems/not-found', title: 'Not found', status: 404 } };
    });
}

describe('courier settings', () => {
    it('shows each courier’s trust, never a credential value, and saves only the fields typed', async () => {
        const calls = settingsApi(owner);
        renderApp('/integrations/couriers');
        expect(await screen.findByRole('heading', { level: 1, name: 'Courier settings & delivery charges' })).toBeTruthy();
        const cards = screen.getByRole('list', { name: 'Couriers' });
        expect(within(cards).getByText('Untested driver')).toBeTruthy();
        expect(within(cards).getByText('No credentials')).toBeTruthy();
        expect(screen.getByText('Steadfast has never been tested against a live account')).toBeTruthy();

        const apiKey = screen.getByLabelText('API key') as HTMLInputElement;
        expect(apiKey.value).toBe('');
        expect(apiKey.type).toBe('password');
        expect(screen.getByText('Saved, ends ••••1111. Leave empty to keep it.')).toBeTruthy();

        fireEvent.change(screen.getByLabelText('Secret key'), { target: { value: 'sf-secret-2222' } });
        fireEvent.click(screen.getByRole('button', { name: 'Save credentials' }));
        await waitFor(() => expect(bodyOf(calls, '/settings/couriers/1/credentials', 'PUT')).toEqual({ credentials: { secret_key: 'sf-secret-2222' }, clear: [], webhook_secret: null, clear_webhook_secret: false }));
        // The field empties after saving: the secret is never held in the page longer than needed.
        await waitFor(() => expect((screen.getByLabelText('Secret key') as HTMLInputElement).value).toBe(''));
    });

    it('validates and saves the rate card as decimal strings, and the free-delivery threshold', async () => {
        const calls = settingsApi(owner);
        renderApp('/integrations/couriers');
        await screen.findByRole('heading', { level: 2, name: 'Steadfast rate card' });
        expect(screen.getByText('This is the default courier: customers are charged from these rates at checkout.')).toBeTruthy();

        fireEvent.change(screen.getByLabelText('Outside Dhaka'), { target: { value: '-140' } });
        fireEvent.click(screen.getByRole('button', { name: 'Save rate card' }));
        expect(await screen.findByText('Enter an amount in taka, up to 2 decimals.')).toBeTruthy();
        expect(bodyOf(calls, '/rates', 'PUT')).toBeNull();

        fireEvent.change(screen.getByLabelText('Outside Dhaka'), { target: { value: '140' } });
        fireEvent.click(screen.getByRole('button', { name: 'Save rate card' }));
        await waitFor(() =>
            expect(bodyOf(calls, '/settings/couriers/1/rates', 'PUT')).toEqual({
                base_charge_dhaka: '70',
                base_charge_suburb: '100',
                base_charge_outside: '140',
                per_kg_charge: '20',
                cod_fee_percent: '1',
                return_charge: '0',
                included_weight_grams: 1000,
            }),
        );

        const threshold = screen.getByLabelText('Free-delivery threshold') as HTMLInputElement;
        await waitFor(() => expect(threshold.value).toBe('3000'));
        fireEvent.change(threshold, { target: { value: '' } });
        fireEvent.click(screen.getAllByRole('button', { name: 'Save' })[0]!);
        await waitFor(() => expect(bodyOf(calls, '/settings/delivery', 'PUT')).toEqual({ free_shipping_threshold: null }));
    });

    it('lets a rates-only role edit rates but not see the credential form', async () => {
        settingsApi(ratesOnly, [{ ...steadfast, can: { update_rates: true, update_credentials: false } }]);
        renderApp('/integrations/couriers');
        expect(await screen.findByText('Only the owner can see or change courier credentials.')).toBeTruthy();
        expect(screen.queryByLabelText('API key')).toBeNull();
        expect(screen.getByRole('button', { name: 'Save rate card' })).toBeTruthy();
    });

    it('explains that Pathao’s username/password is its official API token flow (rule 24a)', async () => {
        settingsApi(owner);
        renderApp('/integrations/couriers');
        const pathaoCard = await screen.findByRole('listitem', { name: 'Pathao' });
        fireEvent.click(within(pathaoCard).getByRole('button', { name: 'Settings' }));
        expect(await screen.findByText(/never used to log in to the merchant portal/)).toBeTruthy();
        expect(screen.getByLabelText('Merchant password')).toBeTruthy();
    });
});

function bookingApi(over?: (url: string, init: Init) => { status: number; body?: unknown } | undefined) {
    return mockApi((url, init) => {
        const custom = over?.(url, init);
        if (custom) return custom;
        if (url.endsWith('/auth/me')) return { status: 200, body: owner };
        if (url.endsWith('/shipments/quote')) {
            const ids = (JSON.parse(String(init?.body)) as { orders: { id: number }[] }).orders.map((o) => o.id);
            return { status: 200, body: quote(ids) };
        }
        if (url.includes('/shipments?filter[order_id]=126')) return { status: 200, body: { data: [] } };
        if (url.endsWith('/orders/126/shipments')) return { status: 202, body: shipment({ status: 'pending', consignment_id: null, tracking_code: null, tracking_url: null, charge: null, cod_fee: null, booked_at: null }) };
        if (url.endsWith('/shipments/bulk'))
            return {
                status: 200,
                body: {
                    data: [
                        { order_id: 126, result: 'requested', shipment_id: 41, message: null },
                        { order_id: 127, result: 'conflict', shipment_id: null, message: 'Order 127 already has an open shipment.' },
                    ],
                    meta: { requested: 1, failed: 1 },
                },
            };
        return undefined as never;
    });
}

const readyOrder = { id: 126, number: 'TMB-26-00126', status: 'ready_to_ship' };

describe('booking panel', () => {
    it('books one order with the server’s preview and an Idempotency-Key', async () => {
        const calls = bookingApi();
        mount(<ShipmentCard order={readyOrder} />);
        fireEvent.click(await screen.findByRole('button', { name: 'Book courier' }));
        const dialog = await screen.findByRole('dialog', { name: /Book courier — TMB-26-00126/ });
        await waitFor(() => expect(bodyOf(calls, '/shipments/quote')).toEqual({ orders: [{ id: 126, weight_grams: null }] }));

        // Every figure is the server's: delivery ৳130, COD fee ৳23.1, cost if delivered ৳153.1.
        expect(within(dialog).getByText('Delivery · outside Dhaka, 920 g')).toBeTruthy();
        expect(within(dialog).getByText('COD fee · 1% of ৳2,310')).toBeTruthy();
        expect(within(dialog).getByText('If refused it costs ৳200 (৳130 out + ৳70 back) and earns nothing.')).toBeTruthy();
        // Pathao is switched off: its card is disabled, never silently chosen.
        expect((within(dialog).getByRole('radio', { name: /Pathao/ }) as HTMLInputElement).disabled).toBe(true);

        fireEvent.click(within(dialog).getByRole('button', { name: 'Book with Steadfast · ৳153.1' }));
        await waitFor(() => expect(bodyOf(calls, '/orders/126/shipments')).toEqual({ provider: 'steadfast', courier_location_id: null, weight_grams: null, note: null }));
        expect(headerOf(calls, '/orders/126/shipments')).not.toBeNull();
        expect(await within(dialog).findByText('Booking sent to Steadfast')).toBeTruthy();
    });

    it('blocks an unmapped Pathao area and links to the mapping screen (rule 32)', async () => {
        bookingApi((url) => {
            if (url.endsWith('/shipments/quote')) {
                const q = quote([126]);
                const pathaoReady = { ...q.providers[1]!, is_active: true, trust: 'unverified' as const, blocked_reason: null };
                return { status: 200, body: { ...q, providers: [{ ...q.providers[0]!, is_default: false }, { ...pathaoReady, is_default: true }] } };
            }
            return undefined;
        });
        mount(<ShipmentCard order={readyOrder} />);
        fireEvent.click(await screen.findByRole('button', { name: 'Book courier' }));
        const dialog = await screen.findByRole('dialog');
        fireEvent.click(await within(dialog).findByRole('radio', { name: /Pathao/ }));
        expect(await within(dialog).findByText(/not mapped to a Pathao zone\. Booking is blocked/)).toBeTruthy();
        expect(within(dialog).getByRole('link', { name: 'Map now' }).getAttribute('href')).toBe('/shipments/locations');
        expect((within(dialog).getByRole('button', { name: /Book with Pathao/ }) as HTMLButtonElement).disabled).toBe(true);
    });

    it('bulk SUBMITS (never "books"), needs the acknowledgement, and reports per order', async () => {
        const calls = bookingApi();
        mount(<CourierBookingPanel open orderIds={[126, 127]} onClose={() => {}} />);
        const dialog = await screen.findByRole('dialog', { name: /Bulk submit — 2 orders/ });
        const submit = await within(dialog).findByRole('button', { name: 'Submit 2 orders to Steadfast' });
        expect(within(dialog).queryByRole('button', { name: /Book/ })).toBeNull();
        expect(within(dialog).getByText('2 parcels · 1,840 g · COD ৳4,620 · estimated cost ৳306.2')).toBeTruthy();
        expect((submit as HTMLButtonElement).disabled).toBe(true);

        fireEvent.click(within(dialog).getByRole('checkbox'));
        fireEvent.click(submit);
        await waitFor(() => expect(bodyOf(calls, '/shipments/bulk')).toEqual({ order_ids: [126, 127], provider: 'steadfast' }));
        expect(headerOf(calls, '/shipments/bulk')).not.toBeNull();
        expect(await within(dialog).findByText('Submitted — awaiting confirmation')).toBeTruthy();
        expect(within(dialog).getByText('Order 127 already has an open shipment.')).toBeTruthy();
    });
});


describe('shipment card', () => {
    it('shows a submitted shipment honestly: no consignment yet, no label', async () => {
        mockApi((url) => {
            if (url.endsWith('/auth/me')) return { status: 200, body: owner };
            if (url.includes('/shipments?filter[order_id]=126'))
                return { status: 200, body: { data: [shipment({ status: 'submitted', consignment_id: null, tracking_code: null, tracking_url: null, charge: null, cod_fee: null, booked_at: null })] } };
            return { status: 404, body: {} };
        });
        mount(<ShipmentCard order={{ id: 126, number: 'TMB-26-00126', status: 'ready_to_ship' }} />);
        expect(await screen.findByText('Not issued yet')).toBeTruthy();
        expect(screen.getAllByText('Submitted — awaiting confirmation').length).toBeGreaterThan(0);
        expect((screen.getByRole('button', { name: /Print label/ }) as HTMLButtonElement).disabled).toBe(true);
        expect(screen.getByText('No tracking code yet')).toBeTruthy();
        expect(screen.queryByRole('button', { name: 'Book again' })).toBeNull();
    });

    it('links a booked shipment’s label and tracking', async () => {
        mockApi((url) => {
            if (url.endsWith('/auth/me')) return { status: 200, body: owner };
            if (url.includes('/shipments?filter[order_id]=126'))
                return { status: 200, body: { data: [shipment({ events: [{ id: 1, normalised_status: 'picked_up', provider_status: 'pickup_done', message: 'পিকআপ সম্পন্ন', location: 'Mirpur', source: 'webhook', occurred_at: '2026-11-13T05:00:00Z' }] })] } };
            return { status: 404, body: {} };
        });
        mount(<ShipmentCard order={{ id: 126, number: 'TMB-26-00126', status: 'shipped' }} />);
        expect(await screen.findByText('1234567')).toBeTruthy();
        expect(screen.getByRole('link', { name: /Print label/ }).getAttribute('href')).toBe('/api/v1/admin/shipments/40/label?locale=en');
        expect(screen.getByRole('link', { name: /Track on Steadfast/ }).getAttribute('href')).toBe('https://steadfast.com.bd/t/15BAEB8A');
        expect(screen.getByText('“পিকআপ সম্পন্ন”')).toBeTruthy();
        expect(screen.getByText('webhook')).toBeTruthy();
    });
});

describe('shipment rider (owner decision 2026-11-13)', () => {
    function riderApi(me: Me, initial: Shipment) {
        let current = initial;
        return mockApi((url, init) => {
            if (url.endsWith('/auth/me')) return { status: 200, body: me };
            if (url.endsWith('/shipments/40/rider') && init?.method === 'PATCH') {
                const sent = JSON.parse(String(init.body)) as { rider_name: string | null; rider_phone: string | null };
                if (sent.rider_phone === '01112345678') {
                    return { status: 422, body: { type: 'https://toymartbd.com/problems/validation', title: 'Invalid', status: 422, errors: [{ field: 'rider_phone', message: 'invalid' }] } };
                }
                current = { ...current, rider: sent.rider_name || sent.rider_phone ? { name: sent.rider_name, phone: sent.rider_phone ? '+8801812345678' : null, phone_masked: false, source: 'manual', recorded_at: '2026-11-13T06:00:00Z' } : null };
                return { status: 200, body: current };
            }
            if (url.includes('/shipments?filter[order_id]=126')) return { status: 200, body: { data: [current] } };
            return { status: 404, body: {} };
        });
    }

    it('records the rider’s name and phone, and shows the server’s answer', async () => {
        const calls = riderApi(owner, shipment({ status: 'out_for_delivery' }));
        mount(<ShipmentCard order={{ id: 126, number: 'TMB-26-00126', status: 'shipped' }} />);
        expect(await screen.findByText('Not recorded')).toBeTruthy();

        fireEvent.click(screen.getByRole('button', { name: 'Record rider' }));
        fireEvent.change(screen.getByLabelText(/Rider’s name/), { target: { value: 'করিম' } });
        fireEvent.change(screen.getByLabelText(/Rider’s phone/), { target: { value: '01812345678' } });
        fireEvent.click(screen.getByRole('button', { name: 'Save rider' }));

        await waitFor(() => expect(bodyOf(calls, '/shipments/40/rider', 'PATCH')).toEqual({ rider_name: 'করিম', rider_phone: '01812345678' }));
        expect(await screen.findByText('+8801812345678')).toBeTruthy();
        expect(screen.getByRole('button', { name: 'Edit rider' })).toBeTruthy();
    });

    it('shows the server’s refusal of a non-013–019 number in words', async () => {
        riderApi(owner, shipment({ status: 'out_for_delivery' }));
        mount(<ShipmentCard order={{ id: 126, number: 'TMB-26-00126', status: 'shipped' }} />);
        fireEvent.click(await screen.findByRole('button', { name: 'Record rider' }));
        fireEvent.change(screen.getByLabelText(/Rider’s phone/), { target: { value: '01112345678' } });
        fireEvent.click(screen.getByRole('button', { name: 'Save rider' }));

        expect(await screen.findByText('Enter a Bangladeshi mobile number starting 013–019, e.g. 01712345678.')).toBeTruthy();
    });

    it('shows a masked rider phone read-only to staff who cannot book', async () => {
        const viewerMe: Me = { ...owner, roles: ['viewer'], permissions: ['dashboard.view', 'orders.view', 'shipments.view'] };
        riderApi(viewerMe, shipment({ rider: { name: 'Karim', phone: '018****5678', phone_masked: true, source: 'manual', recorded_at: '2026-11-13T06:00:00Z' } }));
        mount(<ShipmentCard order={{ id: 126, number: 'TMB-26-00126', status: 'shipped' }} />);
        expect(await screen.findByText('018****5678')).toBeTruthy();
        expect(screen.queryByRole('button', { name: 'Edit rider' })).toBeNull();
    });
});

describe('failed bookings', () => {
    it('lists failures with the courier’s own words and retries with an Idempotency-Key', async () => {
        const calls = mockApi((url, init) => {
            if (url.endsWith('/auth/me')) return { status: 200, body: owner };
            if (url.endsWith('/shipments/failed'))
                return {
                    status: 200,
                    body: {
                        data: [
                            shipment({ status: 'failed', consignment_id: null, failure_code: 'provider_refused', error_message: 'Invalid recipient phone' }),
                            shipment({ id: 41, number: 'SHP-26-000041', status: 'failed', consignment_id: null, failure_code: 'unmapped_location', error_message: null }),
                        ],
                    },
                };
            if (url.endsWith('/shipments/40/retry') && init?.method === 'POST') return { status: 202, body: shipment({ status: 'pending' }) };
            return { status: 404, body: {} };
        });
        renderApp('/shipments/failed');
        expect(await screen.findByRole('heading', { level: 1, name: 'Failed bookings' })).toBeTruthy();
        await screen.findByRole('list', { name: 'Failed bookings' });
        expect(screen.getByText('“Invalid recipient phone”')).toBeTruthy();
        expect(screen.getByRole('link', { name: 'Map now' })).toBeTruthy();
        fireEvent.click(screen.getAllByRole('button', { name: 'Retry' })[0]!);
        await waitFor(() => expect(headerOf(calls, '/shipments/40/retry')).not.toBeNull());
    });

    it('says when nothing has failed', async () => {
        mockApi((url) => (url.endsWith('/auth/me') ? { status: 200, body: owner } : { status: 200, body: { data: [] } }));
        renderApp('/shipments/failed');
        expect(await screen.findByText('No failed bookings')).toBeTruthy();
    });
});
