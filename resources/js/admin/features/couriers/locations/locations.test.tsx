import { cleanup, fireEvent, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { mockApi, renderApp, viewer } from '@/test/renderApp';
import type { Me } from '@/features/auth/api';
import type { CourierLocation, CourierPlace, LocationOverview, ZoneSuggestion } from './api';

afterEach(() => {
    cleanup();
    localStorage.clear();
});

const manager: Me = { ...viewer, roles: ['manager'], permissions: ['dashboard.view', 'shipments.view', 'shipments.manage_locations'] };
const operator: Me = { ...viewer, roles: ['order_operator'], permissions: ['dashboard.view', 'shipments.view', 'shipments.book'] };

const overview: LocationOverview = {
    provider: { id: 2, code: 'pathao', name: 'Pathao', name_bn: 'পাঠাও' },
    zones: 1323,
    areas: 38520,
    mapped: 0,
    mapped_inactive: 0,
    last_sync: { cities: 64, zones: 1323, areas: 38520, created: 0, deactivated: 0, mapped_inactive: 0, finished_at: '2026-11-13T05:37:00+00:00' },
    cities: [
        { id: '1', name: 'Dhaka', zones: 367, mapped: 0 },
        { id: '13', name: 'Cumilla', zones: 18, mapped: 0 },
    ],
};

/** Bangla and English names side by side (rule 34). */
const savar: CourierPlace = { district_id: 47, district: 'Dhaka', district_bn: 'ঢাকা', upazila_id: 365, upazila: 'Savar', upazila_bn: 'সাভার', waiting_orders: 3, mapped_count: 0 };

const zone: CourierLocation = {
    id: 9,
    level: 'zone',
    city_id: '1',
    city_name: 'Dhaka',
    zone_id: '1538',
    zone_name: 'Ashulia Bazar',
    area_id: null,
    area_name: null,
    home_delivery_available: null,
    pickup_available: null,
    is_mapped: false,
    is_active: true,
    district: null,
    upazila: null,
    mapped_at: null,
    synced_at: '2026-11-13T05:37:00+00:00',
};

const suggestion: ZoneSuggestion = { reason: 'name_similarity', score: 0.94, location: { ...zone, id: 10, zone_id: '99001', zone_name: 'Savar' } };

function setup(me: Me) {
    return mockApi((url, init) => {
        if (url.endsWith('/auth/me')) return { status: 200, body: me };
        if (url.includes('/courier-locations/pathao/needs-mapping')) return { status: 200, body: { data: [savar] } };
        if (url.includes('/courier-locations/pathao/suggestions')) return { status: 200, body: { data: [suggestion] } };
        if (url.includes('/locations/10/mapping') && init?.method === 'PUT') return { status: 200, body: { ...suggestion.location, is_mapped: true } };
        if (url.includes('/courier-locations/pathao/locations')) return { status: 200, body: { data: [zone], meta: { next_cursor: null } } };
        if (url.endsWith('/courier-locations/pathao')) return { status: 200, body: overview };
        return { status: 404, body: { type: 'about:blank', title: 'Not found', status: 404 } };
    });
}

describe('Location mapping', () => {
    it('lists places waiting orders need, and maps only on an explicit confirm of a labelled suggestion', async () => {
        const fetchMock = setup(manager);
        renderApp('/shipments/locations');

        expect(await screen.findByRole('heading', { name: 'Location mapping' })).toBeTruthy();
        expect(await screen.findByText('Savar, Dhaka')).toBeTruthy();
        expect(screen.getByText('1,323')).toBeTruthy();

        fireEvent.click(screen.getByRole('button', { name: 'Map' }));
        const dialog = await screen.findByRole('dialog');
        expect(await within(dialog).findByText('Suggestion')).toBeTruthy();
        expect(within(dialog).getByText(/name match 94%/)).toBeTruthy();

        // Opening the dialog and seeing a suggestion wrote nothing.
        expect(fetchMock.mock.calls.some(([, init]) => (init as RequestInit | undefined)?.method === 'PUT')).toBe(false);

        fireEvent.click(within(dialog).getByRole('button', { name: 'Confirm' }));
        await waitFor(() => {
            const put = fetchMock.mock.calls.find(([, init]) => (init as RequestInit | undefined)?.method === 'PUT');
            expect(put?.[0]).toContain('/courier-locations/pathao/locations/10/mapping');
            expect(JSON.parse(String((put?.[1] as RequestInit).body))).toEqual({ district_id: 47, upazila_id: 365 });
        });
    });

    it('shows the courier zones with their mapping state', async () => {
        setup(manager);
        renderApp('/shipments/locations?tab=zones');

        expect(await screen.findByText('Ashulia Bazar')).toBeTruthy();
        expect(within(screen.getByRole('table')).getByText('Not mapped')).toBeTruthy();
        expect(screen.getByRole('option', { name: 'Cumilla (0/18)' })).toBeTruthy();
    });

    it('hides map and sync from staff without shipments.manage_locations', async () => {
        setup(operator);
        renderApp('/shipments/locations');

        expect(await screen.findByText('Savar, Dhaka')).toBeTruthy();
        expect(screen.queryByRole('button', { name: 'Map' })).toBeNull();
        expect(screen.queryByRole('button', { name: /Sync from/ })).toBeNull();
    });

    it('renders in Bangla with Western digits', async () => {
        setup(manager);
        renderApp('/shipments/locations', { locale: 'bn' });

        expect(await screen.findByText('সাভার, ঢাকা')).toBeTruthy();
        expect(screen.getByText('1,323')).toBeTruthy();
        expect(screen.getByRole('heading', { name: 'লোকেশন ম্যাপিং' })).toBeTruthy();
    });
});
