import { cleanup, fireEvent, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { mockApi, renderApp, viewer } from '@/test/renderApp';
import type { Me } from '@/features/auth/api';
import type { Integration } from './api';

afterEach(() => {
    cleanup();
    localStorage.clear();
});

const owner: Me = {
    ...viewer,
    roles: ['super_admin'],
    permissions: ['dashboard.view', 'settings.view', 'settings.manage_integrations', 'shipments.manage_rates'],
};
const operator: Me = { ...viewer, roles: ['order_operator'], permissions: ['dashboard.view', 'settings.view'] };

function item(overrides: Partial<Integration> & Pick<Integration, 'key' | 'name' | 'category' | 'status'>): Integration {
    return { notes: [], attention: null, bookings_count: null, enabled: null, courier_provider_id: null, can_toggle: false, settings_path: null, ...overrides };
}

const rows: Integration[] = [
    item({ key: 'pathao', name: 'Pathao', category: 'couriers', status: 'connected_live', notes: ['bookings'], bookings_count: 47, enabled: true, courier_provider_id: 2, can_toggle: true, settings_path: '/integrations/couriers' }),
    item({ key: 'steadfast', name: 'Steadfast', category: 'couriers', status: 'test_mode', notes: ['no_sandbox', 'unverified_booking_blocked'], enabled: true, courier_provider_id: 1, can_toggle: true, settings_path: '/integrations/couriers' }),
    item({ key: 'redx', name: 'RedX', category: 'couriers', status: 'not_available', notes: ['no_driver'] }),
    item({ key: 'cod', name: 'Cash on delivery', category: 'payments', status: 'connected_live', notes: ['cod_always_on'] }),
    item({ key: 'sms', name: 'SMS gateway', category: 'messaging', status: 'needs_attention', notes: ['driver_reve'], attention: 'sms_last_send_failed', settings_path: '/integrations/messaging' }),
    item({ key: 'ga4', name: 'Google Analytics 4', category: 'marketing', status: 'not_connected', notes: ['switched_off'], enabled: false, settings_path: '/integrations/tracking' }),
];

function hubApi(me: Me, patch: { status: number; body?: unknown } = { status: 200, body: {} }) {
    return mockApi((url, init) => {
        if (url.endsWith('/auth/me')) return { status: 200, body: me };
        if (url.includes('/settings/couriers/') && init?.method === 'PATCH') return patch;
        if (url.endsWith('/integrations')) return { status: 200, body: { data: rows } };
        return { status: 404 };
    });
}

describe('integrations hub', () => {
    it('groups services by category with counted tabs, chips and detail lines', async () => {
        hubApi(owner);
        renderApp('/integrations');

        expect(await screen.findByRole('heading', { name: 'Integrations' })).toBeTruthy();
        const pathao = await screen.findByRole('listitem', { name: 'Pathao' });
        const tabs = screen.getByRole('navigation', { name: 'Integration categories' });
        expect(within(tabs).getByRole('button', { name: /All\s*6/ })).toBeTruthy();
        expect(within(tabs).getByRole('button', { name: /Couriers\s*3/ })).toBeTruthy();
        expect(within(tabs).getByRole('button', { name: /Marketing & tracking\s*1/ })).toBeTruthy();

        expect(within(pathao).getByText('47 bookings')).toBeTruthy();
        expect(within(pathao).getByText('Connected · live')).toBeTruthy();

        const steadfast = screen.getByRole('listitem', { name: 'Steadfast' });
        expect(within(steadfast).getByText('No sandbox · tested on fixtures only · Not verified · live bookings refused')).toBeTruthy();
        expect(within(steadfast).getByText('Test mode')).toBeTruthy();

        const redx = screen.getByRole('listitem', { name: 'RedX' });
        expect(within(redx).getByText('Not available yet')).toBeTruthy();
        expect(within(redx).queryByRole('link')).toBeNull();
        expect(within(redx).queryByRole('switch')).toBeNull();

        const sms = screen.getByRole('listitem', { name: 'SMS gateway' });
        expect(within(sms).getByText('The last SMS failed')).toBeTruthy();
        expect(within(sms).getByText('Needs attention')).toBeTruthy();
        expect(within(sms).getByRole('link', { name: 'Fix: SMS gateway' }).getAttribute('href')).toBe('/integrations/messaging');

        const ga4 = screen.getByRole('listitem', { name: 'Google Analytics 4' });
        expect(within(ga4).getByRole('link', { name: 'Connect: Google Analytics 4' }).getAttribute('href')).toBe('/integrations/tracking');
        // Tracking has no on/off endpoint of its own here: the state shows, read-only.
        expect((within(ga4).getByRole('switch') as HTMLButtonElement).disabled).toBe(true);
        expect(within(screen.getByRole('listitem', { name: 'Cash on delivery' })).queryByRole('link')).toBeNull();
    });

    it('filters to one category from its tab', async () => {
        hubApi(owner);
        renderApp('/integrations');

        fireEvent.click(await screen.findByRole('button', { name: /Payments\s*1/ }));
        expect(screen.getByRole('listitem', { name: 'Cash on delivery' })).toBeTruthy();
        expect(screen.queryByRole('listitem', { name: 'Pathao' })).toBeNull();
        expect(screen.queryByRole('heading', { name: 'Couriers' })).toBeNull();
    });

    it('switches a courier through the courier settings endpoint and shows a refusal', async () => {
        const calls = hubApi(owner, { status: 422, body: { type: 'validation', title: 'Invalid', status: 422, errors: [{ field: 'is_active', message: 'Make another courier the default first.' }] } });
        renderApp('/integrations');

        const toggle = within(await screen.findByRole('listitem', { name: 'Steadfast' })).getByRole('switch', { name: 'Turn Steadfast off' });
        fireEvent.click(toggle);

        await waitFor(() => expect(screen.getByText('Make another courier the default first.')).toBeTruthy());
        const call = calls.mock.calls.find(([url, init]) => String(url).endsWith('/settings/couriers/1') && (init as RequestInit | undefined)?.method === 'PATCH')!;
        expect(JSON.parse(String((call[1] as RequestInit).body))).toEqual({ is_active: false });
    });

    it('renders in Bangla with Western digits', async () => {
        hubApi(owner);
        renderApp('/integrations', { locale: 'bn' });

        expect(await screen.findByRole('heading', { name: 'ইন্টিগ্রেশন' })).toBeTruthy();
        expect(within(await screen.findByRole('listitem', { name: 'Pathao' })).getByText('47টি বুকিং')).toBeTruthy();
        expect(screen.getAllByText('সংযুক্ত · লাইভ').length).toBe(2);
    });

    it('is forbidden without settings.manage_integrations and never asks the API', async () => {
        const calls = hubApi(operator);
        renderApp('/integrations');

        expect(await screen.findByText('You do not have access to this page.')).toBeTruthy();
        expect(calls.mock.calls.some(([url]) => String(url).endsWith('/integrations'))).toBe(false);
    });
});
