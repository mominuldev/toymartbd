import { cleanup, fireEvent, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { mockApi, renderApp, viewer } from '@/test/renderApp';
import type { Me } from '@/features/auth/api';
import type { CheckoutSettings } from './checkoutApi';

afterEach(() => {
    cleanup();
    localStorage.clear();
});

const owner: Me = { ...viewer, roles: ['super_admin'], permissions: ['dashboard.view', 'settings.view', 'settings.update', 'shipments.manage_rates'] };
const reader: Me = { ...viewer, roles: ['custom'], permissions: ['dashboard.view', 'settings.view'] };
const outsider: Me = { ...viewer, roles: ['viewer'], permissions: ['dashboard.view'] };

const rules: CheckoutSettings = {
    guest_checkout: true,
    cash_on_delivery: true,
    online_methods: [],
    order_notes: true,
    order_note_max: 500,
    risk_advance: { available: false, blocker: 'no_bkash_gateway', bkash_status: 'not_registered', hold_score: 70 },
    cod_advance: { zones: ['outside_dhaka'], threshold: '5000.00', amount: '200.00' },
    free_shipping_threshold: '3000.00',
    delivery_courier: 'Steadfast',
    delivery_charges: [
        { zone: 'inside_dhaka_metro', charge: '70.00' },
        { zone: 'dhaka_suburb', charge: '100.00' },
        { zone: 'outside_dhaka', charge: '130.00' },
    ],
};

function checkoutApi(me: Me) {
    return mockApi((url, init) => {
        if (url.endsWith('/auth/me')) return { status: 200, body: me };
        if (url.endsWith('/settings/delivery') && init?.method === 'PUT') {
            const sent = JSON.parse(String(init.body)) as { free_shipping_threshold: string | null };
            return { status: 200, body: { free_shipping_threshold: sent.free_shipping_threshold === null ? null : `${sent.free_shipping_threshold}.00` } };
        }
        if (url.endsWith('/settings/checkout')) return { status: 200, body: rules };
        return { status: 404 };
    });
}

function putBodies(calls: ReturnType<typeof mockApi>): unknown[] {
    return calls.mock.calls
        .filter(([url, init]) => String(url).endsWith('/settings/delivery') && (init as RequestInit | undefined)?.method === 'PUT')
        .map(([, init]) => JSON.parse(String((init as RequestInit).body)) as unknown);
}

describe('store settings → checkout & delivery rules', () => {
    it('shows what checkout really does: fixed rows, the unavailable advance and the quoted charges', async () => {
        checkoutApi(owner);
        renderApp('/settings/checkout');

        expect(await screen.findByText('Checkout & delivery rules')).toBeTruthy();
        expect(screen.getAllByText('Always on')).toHaveLength(3);
        expect(screen.getByText('Not available yet')).toBeTruthy();
        expect(screen.getByText(/Orders scoring 70 or more are held for verification/)).toBeTruthy();
        expect(screen.getByText(/COD over ৳5,000 in Outside Dhaka is held until a ৳200 advance/)).toBeTruthy();
        expect(screen.getByText('Free delivery on orders of ৳3,000 or more')).toBeTruthy();
        // Only the free-delivery rule is a switch; no switch pretends to control a fixed rule.
        expect(screen.getAllByRole('switch')).toHaveLength(1);
        expect(screen.getByRole('switch', { name: 'Free delivery on orders of ৳3,000 or more' }).getAttribute('aria-checked')).toBe('true');

        const inside = screen.getByLabelText('Inside Dhaka') as HTMLInputElement;
        expect(inside.value).toBe('70');
        expect(inside.readOnly).toBe(true);
        expect((screen.getByLabelText('Outside Dhaka') as HTMLInputElement).value).toBe('130');
        expect(screen.getByText(/default courier’s rate card \(Steadfast\)/)).toBeTruthy();
        expect(screen.getByRole('link', { name: 'Edit in Courier settings' })).toBeTruthy();
    });

    it('saves a new threshold through PUT /settings/delivery with the generated-schema body', async () => {
        const calls = checkoutApi(owner);
        renderApp('/settings/checkout');

        const amount = (await screen.findByLabelText('Free delivery from (৳)')) as HTMLInputElement;
        expect(amount.value).toBe('3000');
        const save = screen.getByRole('button', { name: 'Save' }) as HTMLButtonElement;
        expect(save.disabled).toBe(true);

        fireEvent.change(amount, { target: { value: '2500' } });
        fireEvent.click(save);
        await waitFor(() => expect(putBodies(calls)).toEqual([{ free_shipping_threshold: '2500' }]));
        expect(await screen.findByText('Checkout & delivery rules saved')).toBeTruthy();
    });

    it('switching free delivery off sends null', async () => {
        const calls = checkoutApi(owner);
        renderApp('/settings/checkout');

        fireEvent.click(await screen.findByRole('switch'));
        expect(screen.queryByLabelText('Free delivery from (৳)')).toBeNull();
        fireEvent.click(screen.getByRole('button', { name: 'Save' }));
        await waitFor(() => expect(putBodies(calls)).toEqual([{ free_shipping_threshold: null }]));
    });

    it('refuses an invalid amount without calling the API', async () => {
        const calls = checkoutApi(owner);
        renderApp('/settings/checkout');

        fireEvent.change(await screen.findByLabelText('Free delivery from (৳)'), { target: { value: '0' } });
        fireEvent.click(screen.getByRole('button', { name: 'Save' }));
        const alert = await screen.findByRole('alert');
        expect(alert.textContent).toBe('Enter an amount above 0, like 3000 or 2999.50.');
        // The error is tied to the field, so it is read with it, not only announced once.
        expect(screen.getByLabelText('Free delivery from (৳)').getAttribute('aria-describedby')).toBe(alert.id);
        expect(putBodies(calls)).toEqual([]);
    });

    it('is read-only without settings.update and shipments.manage_rates', async () => {
        checkoutApi(reader);
        renderApp('/settings/checkout');

        expect(await screen.findByText('Checkout & delivery rules')).toBeTruthy();
        expect(screen.queryByRole('button', { name: 'Save' })).toBeNull();
        expect((screen.getByRole('switch') as HTMLButtonElement).disabled).toBe(true);
        expect((screen.getByLabelText('Free delivery from (৳)') as HTMLInputElement).disabled).toBe(true);
        expect(screen.queryByRole('link', { name: 'Edit in Courier settings' })).toBeNull();
    });

    it('says forbidden without settings.view', async () => {
        checkoutApi(outsider);
        renderApp('/settings/checkout');

        expect(await screen.findByText('You do not have access to this page.')).toBeTruthy();
        expect(screen.queryByText('Checkout & delivery rules')).toBeNull();
    });
});
