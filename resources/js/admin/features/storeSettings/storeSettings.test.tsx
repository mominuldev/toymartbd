import { cleanup, fireEvent, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { mockApi, renderApp, viewer } from '@/test/renderApp';
import type { Me } from '@/features/auth/api';
import type { BusinessDetails, LocationDistrict, StoreLocation } from './api';
import { formatMobile } from './parts';
import { addressOf } from './BranchesSection';

afterEach(() => {
    cleanup();
    localStorage.clear();
});

const owner: Me = { ...viewer, roles: ['super_admin'], permissions: ['dashboard.view', 'settings.view', 'settings.update'] };
const readOnly: Me = { ...viewer, roles: ['viewer'], permissions: ['dashboard.view', 'settings.view'] };
const nobody: Me = { ...viewer, roles: ['viewer'], permissions: ['dashboard.view'] };

const business: BusinessDetails = {
    name_en: 'Toy Mart BD',
    name_bn: null,
    trade_licence: null,
    bin: '004567891-0101',
    hotline: null,
    whatsapp: '+8801711000222',
    default_names: { name_en: 'Toy Mart BD', name_bn: 'টয় মার্ট বিডি' },
    hotline_default: '09600123456',
    placeholders: ['name_bn', 'trade_licence', 'hotline'],
};

const place = (id: number, name: string, name_bn: string) => ({ id, name, name_bn });

const dhaka: StoreLocation = {
    id: 1,
    code: 'dhk',
    name: 'Dhaka HQ · Mirpur',
    name_bn: 'ঢাকা এইচকিউ · মিরপুর',
    type: 'warehouse',
    description: null,
    description_bn: null,
    division: place(3, 'Dhaka', 'ঢাকা'),
    district: place(47, 'Dhaka', 'ঢাকা'),
    upazila: null,
    area: 'Mirpur',
    address_line: 'House 7, Road 2, Section 10',
    landmark: 'Behind Mirpur 10 circle',
    notes: 'Pathao pickup 4–7 pm · 2 packers',
    is_default: true,
    is_active: true,
    holds_stock: true,
};

const sylhet: StoreLocation = {
    ...dhaka,
    id: 3,
    code: 'syl',
    name: 'Sylhet pickup point',
    name_bn: null,
    type: null,
    division: null,
    district: null,
    area: null,
    address_line: null,
    landmark: null,
    notes: null,
    is_default: false,
    holds_stock: false,
};

const districts: LocationDistrict[] = [
    { id: 47, name: 'Dhaka', name_bn: 'ঢাকা', division: place(3, 'Dhaka', 'ঢাকা'), upazilas: [] },
    { id: 60, name: 'Sylhet', name_bn: 'সিলেট', division: place(7, 'Sylhet', 'সিলেট'), upazilas: [place(601, 'Sylhet Sadar', 'সিলেট সদর')] },
];

type Reply = { status: number; body?: unknown };

function storeApi(me: Me, overrides: Partial<Record<string, Reply>> = {}) {
    return mockApi((url, init) => {
        const method = init?.method ?? 'GET';
        const path = new URL(url, 'http://x').pathname.replace('/api/v1/admin', '');
        const override = overrides[`${method} ${path}`];
        if (override) return override;
        if (path === '/auth/me') return { status: 200, body: me };
        if (path === '/settings/business' && method === 'PUT') {
            const sent = JSON.parse(String(init?.body)) as Partial<BusinessDetails>;
            return { status: 200, body: { ...business, ...sent, whatsapp: '+8801911000222', placeholders: [] } };
        }
        if (path === '/settings/business') return { status: 200, body: business };
        if (path === '/settings/locations/districts') return { status: 200, body: { data: districts } };
        if (path === '/settings/locations' && method === 'GET') return { status: 200, body: { data: [dhaka, sylhet] } };
        if (path === '/settings/locations' && method === 'POST') return { status: 201, body: { ...sylhet, id: 9, ...(JSON.parse(String(init?.body)) as object) } };
        if (path.startsWith('/settings/locations/') && method === 'PUT') return { status: 200, body: { ...sylhet, ...(JSON.parse(String(init?.body)) as object) } };
        return { status: 404 };
    });
}

function sent(calls: ReturnType<typeof storeApi>, method: string, path: string): Record<string, unknown> {
    const call = calls.mock.calls.find(([url, init]) => String(url).endsWith(path) && (init as RequestInit | undefined)?.method === method);
    return JSON.parse(String((call![1] as RequestInit).body)) as Record<string, unknown>;
}

describe('store settings layout', () => {
    it('redirects /settings to Business and lists the built sections as route links, the active one current', async () => {
        storeApi(owner);
        renderApp('/settings');

        expect(await screen.findByRole('heading', { level: 1, name: 'Store settings' })).toBeTruthy();
        const nav = screen.getByRole('navigation', { name: 'Store settings sections' });
        const links = within(nav).getAllByRole('link');
        expect(links.map((l) => [l.textContent, l.getAttribute('href')])).toEqual([
            ['Business', '/settings/business'],
            ['Branches & warehouses', '/settings/branches'],
            ['Checkout & delivery', '/settings/checkout'],
            ['Taxes & invoices', '/settings/invoice'],
        ]);
        expect(within(nav).getByRole('link', { name: 'Business' }).getAttribute('aria-current')).toBe('page');
        // No backend yet: not offered.
        expect(within(nav).queryByText('Danger zone')).toBeNull();
        expect(await screen.findByLabelText('Store name')).toBeTruthy();
    });

    it('moves between sections from the nav', async () => {
        storeApi(owner);
        renderApp('/settings/business');

        fireEvent.click(await screen.findByRole('link', { name: 'Branches & warehouses' }));
        expect(await screen.findByText('Dhaka HQ · Mirpur')).toBeTruthy();
        expect(screen.getByRole('link', { name: 'Branches & warehouses' }).getAttribute('aria-current')).toBe('page');
    });

    it('is forbidden without settings.view', async () => {
        storeApi(nobody);
        renderApp('/settings/business');

        expect(await screen.findByText('You do not have access to this page.')).toBeTruthy();
        expect(screen.queryByRole('navigation', { name: 'Store settings sections' })).toBeNull();
    });

    it('is read-only without settings.update: disabled fields, no save, no add or edit', async () => {
        storeApi(readOnly);
        renderApp('/settings/business');

        expect((await screen.findByLabelText('Store name')).matches(':disabled')).toBe(true);
        expect(screen.getByText(/Read only/)).toBeTruthy();
        expect(screen.queryByRole('button', { name: 'Save changes' })).toBeNull();

        fireEvent.click(screen.getByRole('link', { name: 'Branches & warehouses' }));
        expect(await screen.findByText('Dhaka HQ · Mirpur')).toBeTruthy();
        expect(screen.queryByRole('button', { name: 'Add branch' })).toBeNull();
        expect(screen.queryByRole('button', { name: /^Edit / })).toBeNull();
    });
});

describe('business', () => {
    it('shows the saved values, the honest fallbacks and what invoices still print as placeholders', async () => {
        storeApi(owner);
        renderApp('/settings/business');

        const name = (await screen.findByLabelText('Store name')) as HTMLInputElement;
        expect(name.value).toBe('Toy Mart BD');
        expect((screen.getByLabelText('দোকানের নাম') as HTMLInputElement).placeholder).toBe('টয় মার্ট বিডি');
        expect((screen.getByLabelText('WhatsApp') as HTMLInputElement).value).toBe('+880 1711-000222');
        expect(screen.getByText(/order messages use 09600123456/)).toBeTruthy();
        expect(screen.getByRole('status').textContent).toContain('Trade licence no.');
    });

    it('saves the section, blanks as null, through the generated schema', async () => {
        const calls = storeApi(owner);
        renderApp('/settings/business');

        fireEvent.change(await screen.findByLabelText('Trade licence no.'), { target: { value: 'TRAD/DNCC/045512/2025' } });
        fireEvent.change(screen.getByLabelText('Support hotline'), { target: { value: '09610-123456' } });
        fireEvent.change(screen.getByLabelText('WhatsApp'), { target: { value: '01911-000222' } });
        fireEvent.change(screen.getByLabelText('BIN (VAT registration)'), { target: { value: '' } });
        fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));

        expect(await screen.findByText('Business details saved. New invoices will use them.')).toBeTruthy();
        expect(sent(calls, 'PUT', '/settings/business')).toEqual({
            name_en: 'Toy Mart BD',
            name_bn: null,
            trade_licence: 'TRAD/DNCC/045512/2025',
            bin: null,
            hotline: '09610-123456',
            whatsapp: '01911-000222',
        });
    });

    it('shows a server field error under its field', async () => {
        storeApi(owner, {
            'PUT /settings/business': { status: 422, body: { type: 'validation-error', title: 'Invalid', status: 422, errors: [{ field: 'whatsapp', message: 'Enter a Bangladeshi mobile number (013–019), e.g. 01711-000222.' }] } },
        });
        renderApp('/settings/business');

        fireEvent.change(await screen.findByLabelText('WhatsApp'), { target: { value: '01111-000222' } });
        fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));

        expect(await screen.findByText('Enter a Bangladeshi mobile number (013–019), e.g. 01711-000222.')).toBeTruthy();
    });
});

describe('branches & warehouses', () => {
    it('lists each location with its type, address, landmark and facts line', async () => {
        storeApi(owner);
        renderApp('/settings/branches');

        const row = (await screen.findByText('Dhaka HQ · Mirpur')).closest('li')!;
        expect(within(row).getByText('Warehouse · online fulfilment')).toBeTruthy();
        expect(within(row).getByText('Default')).toBeTruthy();
        expect(within(row).getByText('House 7, Road 2, Section 10, Mirpur, Dhaka')).toBeTruthy();
        expect(within(row).getByText('Near Behind Mirpur 10 circle')).toBeTruthy();
        expect(within(row).getByText('Pathao pickup 4–7 pm · 2 packers')).toBeTruthy();

        const empty = screen.getByText('Sylhet pickup point').closest('li')!;
        expect(within(empty).getByText('Type not set')).toBeTruthy();
        expect(within(empty).getByText(/No address yet/)).toBeTruthy();
        expect(addressOf(dhaka, 'bn')).toBe('House 7, Road 2, Section 10, Mirpur, ঢাকা');
    });

    it('adds a branch from the dialog with a rule-31 address', async () => {
        const calls = storeApi(owner);
        renderApp('/settings/branches');

        fireEvent.click(await screen.findByRole('button', { name: 'Add branch' }));
        const dialog = screen.getByRole('dialog', { hidden: true });
        fireEvent.click(within(dialog).getByRole('button', { name: 'Add branch', hidden: true }));
        // The generated schema refuses the empty form before anything is sent.
        expect(calls.mock.calls.some(([, init]) => (init as RequestInit | undefined)?.method === 'POST')).toBe(false);

        const field = (label: RegExp) => within(dialog).getByLabelText(label);
        fireEvent.change(field(/^Name(?! \()/), { target: { value: 'Sylhet · Zindabazar' } });
        fireEvent.change(field(/^Short code/), { target: { value: 'SYL2' } });
        fireEvent.change(field(/^Type/), { target: { value: 'pickup' } });
        await waitFor(() => expect(within(dialog).getByRole('option', { name: 'Sylhet', hidden: true })).toBeTruthy());
        fireEvent.change(field(/^District/), { target: { value: '60' } });
        fireEvent.change(field(/^Upazila/), { target: { value: '601' } });
        fireEvent.change(field(/^Address/), { target: { value: 'Zindabazar, Sylhet 3100' } });
        fireEvent.change(field(/^Landmark/), { target: { value: 'Next to Al-Hamra' } });
        fireEvent.click(within(dialog).getByRole('button', { name: 'Add branch', hidden: true }));

        await waitFor(() => expect(calls.mock.calls.some(([, init]) => (init as RequestInit | undefined)?.method === 'POST')).toBe(true));
        expect(sent(calls, 'POST', '/settings/locations')).toEqual({
            code: 'syl2',
            name: 'Sylhet · Zindabazar',
            name_bn: null,
            type: 'pickup',
            district_id: 60,
            upazila_id: 601,
            area: null,
            address_line: 'Zindabazar, Sylhet 3100',
            landmark: 'Next to Al-Hamra',
            notes: null,
            is_active: true,
        });
    });

    it('edits a branch without its code, and will not switch off the default location', async () => {
        const calls = storeApi(owner);
        renderApp('/settings/branches');

        fireEvent.click(await screen.findByRole('button', { name: 'Edit Dhaka HQ · Mirpur' }));
        const dialog = screen.getByRole('dialog', { hidden: true });
        expect(within(dialog).queryByLabelText(/^Short code/)).toBeNull();
        expect((within(dialog).getByLabelText('Active') as HTMLInputElement).disabled).toBe(true);
        expect(within(dialog).getByText(/default location is always active/)).toBeTruthy();
        await waitFor(() => expect(within(dialog).getByRole('option', { name: 'Sylhet', hidden: true })).toBeTruthy());
        fireEvent.change(within(dialog).getByLabelText(/^Facts line/), { target: { value: 'Pathao pickup 3–6 pm' } });
        fireEvent.click(within(dialog).getByRole('button', { name: 'Save branch', hidden: true }));

        await waitFor(() => expect(calls.mock.calls.some(([, init]) => (init as RequestInit | undefined)?.method === 'PUT')).toBe(true));
        expect(sent(calls, 'PUT', '/settings/locations/1')).toMatchObject({ name: 'Dhaka HQ · Mirpur', district_id: 47, upazila_id: null, notes: 'Pathao pickup 3–6 pm', is_active: true });
        expect(sent(calls, 'PUT', '/settings/locations/1')).not.toHaveProperty('code');
    });

    it('shows a 409 from the server as a toast', async () => {
        storeApi(owner, { 'PUT /settings/locations/3': { status: 409, body: { type: 'conflict', title: 'Conflict', status: 409, detail: 'This location still holds stock.' } } });
        renderApp('/settings/branches');

        fireEvent.click(await screen.findByRole('button', { name: 'Edit Sylhet pickup point' }));
        const dialog = screen.getByRole('dialog', { hidden: true });
        await waitFor(() => expect(within(dialog).getByRole('option', { name: 'Sylhet', hidden: true })).toBeTruthy());
        fireEvent.change(within(dialog).getByLabelText(/^Type/), { target: { value: 'pickup' } });
        fireEvent.change(within(dialog).getByLabelText(/^District/), { target: { value: '60' } });
        fireEvent.change(within(dialog).getByLabelText(/^Address/), { target: { value: 'Zindabazar' } });
        fireEvent.click(within(dialog).getByLabelText('Active'));
        fireEvent.click(within(dialog).getByRole('button', { name: 'Save branch', hidden: true }));

        expect(await screen.findByText('This location still holds stock.')).toBeTruthy();
    });

    it('formats an E.164 mobile for display only', () => {
        expect(formatMobile('+8801711000222')).toBe('+880 1711-000222');
        expect(formatMobile(null)).toBe('');
    });
});
