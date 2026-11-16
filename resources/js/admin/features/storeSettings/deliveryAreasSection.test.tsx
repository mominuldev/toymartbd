import { cleanup, fireEvent, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { updateDeliveryAreasRequestSchema } from '@toymart/api-types/zod';
import { mockApi, renderApp, viewer } from '@/test/renderApp';
import type { Me } from '@/features/auth/api';
import type { LocationDistrict } from './api';
import type { DeliveryAreas } from './deliveryAreasApi';

afterEach(() => {
    cleanup();
    localStorage.clear();
});

const owner: Me = { ...viewer, roles: ['super_admin'], permissions: ['dashboard.view', 'settings.view', 'settings.update', 'shipments.manage_rates'] };
const noRates: Me = { ...viewer, roles: ['custom'], permissions: ['dashboard.view', 'settings.view', 'settings.update'] };
const reader: Me = { ...viewer, roles: ['custom'], permissions: ['dashboard.view', 'settings.view'] };

const dhakaDivision = { id: 6, name: 'Dhaka', name_bn: 'ঢাকা' };
const ctgDivision = { id: 1, name: 'Chattogram', name_bn: 'চট্টগ্রাম' };
const districts: LocationDistrict[] = [
    { id: 47, name: 'Dhaka', name_bn: 'ঢাকা', division: dhakaDivision, upazilas: [{ id: 365, name: 'Savar', name_bn: 'সাভার' }] },
    { id: 41, name: 'Gazipur', name_bn: 'গাজীপুর', division: dhakaDivision, upazilas: [{ id: 300, name: 'Kaliakair', name_bn: 'কালিয়াকৈর' }] },
    { id: 8, name: 'Chattogram', name_bn: 'চট্টগ্রাম', division: ctgDivision, upazilas: [{ id: 65, name: 'Rangunia', name_bn: 'রাঙ্গুনিয়া' }] },
    {
        id: 4,
        name: 'Rangamati',
        name_bn: 'রাঙ্গামাটি',
        division: ctgDivision,
        upazilas: [
            { id: 37, name: 'Barkal', name_bn: 'বরকল' },
            { id: 40, name: 'Belaichari', name_bn: 'বিলাইছড়ি' },
        ],
    },
];

const saved: DeliveryAreas = {
    cutoff_time: '17:00',
    timezone: 'Asia/Dhaka',
    eta_days: { inside_dhaka_metro: { min: 1, max: 2 }, dhaka_suburb: { min: 2, max: 3 }, outside_dhaka: { min: 3, max: 4 } },
    metro_district_ids: [47],
    suburb_district_ids: [41],
    not_serviced_upazila_ids: [37],
    cod_advance: { enabled: true, zones: ['outside_dhaka'], threshold: '5000.00', amount: '200.00' },
};

function areasApi(me: Me, reply?: (body: unknown) => { status: number; body?: unknown }) {
    return mockApi((url, init) => {
        if (url.endsWith('/auth/me')) return { status: 200, body: me };
        if (url.endsWith('/settings/locations/districts')) return { status: 200, body: { data: districts } };
        if (url.endsWith('/settings/delivery-areas') && init?.method === 'PUT') {
            const body = JSON.parse(String(init.body)) as unknown;
            return reply ? reply(body) : { status: 200, body: saved };
        }
        if (url.endsWith('/settings/delivery-areas')) return { status: 200, body: saved };
        return { status: 404 };
    });
}

function putBodies(calls: ReturnType<typeof mockApi>): unknown[] {
    return calls.mock.calls
        .filter(([url, init]) => String(url).endsWith('/settings/delivery-areas') && (init as RequestInit | undefined)?.method === 'PUT')
        .map(([, init]) => JSON.parse(String((init as RequestInit).body)) as unknown);
}

describe('store settings → delivery areas & timing', () => {
    it('loads the saved values: cut-off, ETA per zone, chosen districts and upazilas, the COD advance', async () => {
        areasApi(owner);
        renderApp('/settings/delivery');

        expect(await screen.findByText('Order cut-off & delivery time')).toBeTruthy();
        expect(screen.getByRole('link', { name: 'Delivery areas & timing' })).toBeTruthy();
        expect(screen.getByText(/Placeholder values until the owner confirms them/)).toBeTruthy();
        expect((screen.getByLabelText('Order cut-off') as HTMLInputElement).value).toBe('17:00');
        expect((screen.getByLabelText('Outside Dhaka: slowest, in working days') as HTMLInputElement).value).toBe('4');

        expect(within(screen.getByRole('list', { name: 'Chosen: Inside Dhaka (city districts)' })).getByText('Dhaka')).toBeTruthy();
        // Owner decision 2026-11-15: a metro district covers every address in it, upazila or not.
        expect(screen.getByText('Every address in these districts, with or without an upazila, is charged as Inside Dhaka.')).toBeTruthy();
        expect(within(screen.getByRole('list', { name: 'Chosen: Dhaka suburbs (whole districts)' })).getByText('Gazipur')).toBeTruthy();
        expect(within(screen.getByRole('list', { name: 'Chosen: Not-serviced upazilas' })).getByText('Barkal')).toBeTruthy();

        expect(screen.getByRole('switch', { name: 'Require a COD advance' }).getAttribute('aria-checked')).toBe('true');
        expect((screen.getByLabelText('Order subtotal above') as HTMLInputElement).value).toBe('5000');
        expect((screen.getByLabelText('Advance amount') as HTMLInputElement).value).toBe('200');
        // Clean: the save bar is not rendered.
        expect(screen.queryByRole('button', { name: 'Save changes' })).toBeNull();
    });

    it('shows Bangla place names in Bangla and finds them by either script', async () => {
        areasApi(owner);
        renderApp('/settings/delivery', { locale: 'bn' });

        const chosen = await screen.findByRole('list', { name: 'বাছাই করা: ডেলিভারি নেই এমন উপজেলা' });
        expect(within(chosen).getByText('বরকল')).toBeTruthy();

        fireEvent.change(screen.getByLabelText('ডেলিভারি নেই এমন উপজেলা খুঁজুন'), { target: { value: 'Rangunia' } });
        expect(screen.getByRole('checkbox', { name: 'রাঙ্গুনিয়া' })).toBeTruthy();
        expect(screen.queryByRole('checkbox', { name: 'বিলাইছড়ি' })).toBeNull();
    });

    it('saves the edited document through the generated schema', async () => {
        const calls = areasApi(owner);
        renderApp('/settings/delivery');

        fireEvent.change(await screen.findByLabelText('Order cut-off'), { target: { value: '15:30' } });
        fireEvent.change(screen.getByLabelText('Outside Dhaka: slowest, in working days'), { target: { value: '6' } });
        // Chattogram becomes a suburb district; Dhaka cannot be (it is metro), so it is not selectable there.
        fireEvent.change(screen.getByLabelText('Search Dhaka suburbs (whole districts)'), { target: { value: 'চট্টগ্রাম' } });
        fireEvent.click(within(screen.getByRole('group', { name: 'Dhaka suburbs (whole districts)' })).getByRole('checkbox', { name: 'Chattogram' }));
        // Release Barkal, add Belaichari.
        fireEvent.click(screen.getByRole('button', { name: 'Remove Barkal' }));
        fireEvent.click(screen.getByRole('checkbox', { name: 'Belaichari' }));
        fireEvent.click(screen.getByRole('checkbox', { name: 'Dhaka suburbs' }));
        fireEvent.change(screen.getByLabelText('Advance amount'), { target: { value: '250.50' } });

        fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));

        await waitFor(() => expect(putBodies(calls)).toHaveLength(1));
        const body = putBodies(calls)[0];
        expect(updateDeliveryAreasRequestSchema.safeParse(body).success).toBe(true);
        expect(body).toEqual({
            cutoff_time: '15:30',
            eta_days: { inside_dhaka_metro: { min: 1, max: 2 }, dhaka_suburb: { min: 2, max: 3 }, outside_dhaka: { min: 3, max: 6 } },
            metro_district_ids: [47],
            suburb_district_ids: [8, 41],
            not_serviced_upazila_ids: [40],
            cod_advance: { enabled: true, zones: ['dhaka_suburb', 'outside_dhaka'], threshold: '5000', amount: '250.50' },
        });
        expect(await screen.findByText('Delivery areas & timing saved')).toBeTruthy();
    });

    it('keeps a metro district out of the suburb list', async () => {
        areasApi(owner);
        renderApp('/settings/delivery');

        fireEvent.change(await screen.findByLabelText('Search Dhaka suburbs (whole districts)'), { target: { value: 'Dhaka' } });
        const suburbPicker = screen.getByRole('group', { name: 'Dhaka suburbs (whole districts)' });
        expect((within(suburbPicker).getByRole('checkbox', { name: /^Dhaka/ }) as HTMLInputElement).disabled).toBe(true);
    });

    it('switching the COD advance off sends enabled: false', async () => {
        const calls = areasApi(owner);
        renderApp('/settings/delivery');

        fireEvent.click(await screen.findByRole('switch', { name: 'Require a COD advance' }));
        expect(screen.queryByLabelText('Advance amount')).toBeNull();
        fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));
        await waitFor(() => expect(putBodies(calls)).toHaveLength(1));
        expect((putBodies(calls)[0] as { cod_advance: unknown }).cod_advance).toEqual({ enabled: false });
    });

    it('refuses a non-numeric day count without calling the API', async () => {
        const calls = areasApi(owner);
        renderApp('/settings/delivery');

        fireEvent.change(await screen.findByLabelText('Inside Dhaka: fastest, in working days'), { target: { value: 'one' } });
        fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));
        expect((await screen.findByRole('alert')).textContent).toBe('Enter whole days from 0 to 30.');
        expect(putBodies(calls)).toEqual([]);
    });

    it('shows the server’s 422 on the field it names', async () => {
        areasApi(owner, () => ({
            status: 422,
            body: {
                type: 'https://toymartbd.com/problems/validation-error',
                title: 'Validation failed',
                status: 422,
                errors: [{ field: 'cod_advance.amount', message: 'The advance must be less than the threshold.' }],
            },
        }));
        renderApp('/settings/delivery');

        fireEvent.change(await screen.findByLabelText('Advance amount'), { target: { value: '6000' } });
        fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));
        expect(await screen.findByText('The advance must be less than the threshold.')).toBeTruthy();
        expect(screen.getByLabelText('Advance amount').getAttribute('aria-invalid')).toBe('true');
    });

    it('is read-only with settings.view only', async () => {
        areasApi(reader);
        renderApp('/settings/delivery');

        expect(await screen.findByText('Order cut-off & delivery time')).toBeTruthy();
        expect(screen.queryByRole('button', { name: 'Save changes' })).toBeNull();
        expect(screen.getByLabelText('Order cut-off').matches(':disabled')).toBe(true);
        expect((screen.getByRole('switch', { name: 'Require a COD advance' }) as HTMLButtonElement).disabled).toBe(true);
        expect(screen.queryByRole('button', { name: 'Remove Barkal' })).toBeNull();
        // The pickers show only the chosen chips; the COD zone boxes stay visible but locked.
        expect(screen.queryByRole('checkbox', { name: 'Belaichari' })).toBeNull();
        expect(screen.getAllByRole('checkbox').every((c) => c.matches(':disabled'))).toBe(true);
    });

    it('is read-only, and says why, without shipments.manage_rates', async () => {
        areasApi(noRates);
        renderApp('/settings/delivery');

        expect(await screen.findByText(/also needs the courier rates permission/)).toBeTruthy();
        expect(screen.queryByRole('button', { name: 'Save changes' })).toBeNull();
    });
});
