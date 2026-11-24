import { cleanup, fireEvent, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { mockApi, renderApp, viewer } from '@/test/renderApp';
import type { Me } from '@/features/auth/api';
import type { BusinessDetails } from './api';
import type { PosSettings } from './posSettingsApi';

afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
    vi.useRealTimers();
    localStorage.clear();
});

const owner: Me = { ...viewer, roles: ['super_admin'], permissions: ['dashboard.view', 'settings.view', 'settings.update'] };
const readOnly: Me = { ...viewer, roles: ['viewer'], permissions: ['dashboard.view', 'settings.view'] };

const business: BusinessDetails = {
    name_en: 'Smart Junior',
    name_bn: 'Smart Junior',
    trade_licence: null,
    bin: null,
    hotline: null,
    whatsapp: null,
    messenger: null,
    default_names: { name_en: 'Smart Junior', name_bn: 'Smart Junior' },
    hotline_default: null,
    placeholders: [],
};
const pos: PosSettings = { discount_max_percent: '10', discount_max_percent_default: '10', discount_saved: true, due_limit: '5000.00', due_limit_default: '5000.00', due_saved: true };

function api(me: Me) {
    return mockApi((url, init) => {
        const path = new URL(url, 'http://x').pathname.replace('/api/v1/admin', '');
        if (path === '/auth/me') return { status: 200, body: me };
        if (path === '/settings/business' && init?.method === 'PUT') return { status: 200, body: { ...business, ...(JSON.parse(String(init.body)) as object) } };
        if (path === '/settings/business') return { status: 200, body: business };
        if (path === '/settings/pos' && init?.method === 'PUT') return { status: 200, body: { ...pos, ...(JSON.parse(String(init.body)) as object), due_limit: '7500.00' } };
        if (path === '/settings/pos') return { status: 200, body: pos };
        return { status: 404 };
    });
}

const region = (section: string) => screen.queryByRole('region', { name: `Save changes to ${section}` });

describe('store settings sticky save bar', () => {
    it('is not rendered while the section is clean, and appears with what changed', async () => {
        api(owner);
        renderApp('/settings/business');

        const licence = await screen.findByLabelText('Trade licence no.');
        expect(region('Business')).toBeNull();

        fireEvent.change(licence, { target: { value: 'TRAD/DNCC/045512/2025' } });
        const bar = region('Business');
        expect(bar).toBeTruthy();
        expect(within(bar!).getByRole('status').textContent).toContain('Unsaved changes in Business');
        expect(bar!.textContent).toContain('Trade licence no. changed');
        expect(within(bar!).getByRole('button', { name: 'Discard' })).toBeTruthy();
        // Typing back the saved value makes the section clean again.
        fireEvent.change(licence, { target: { value: '' } });
        expect(region('Business')).toBeNull();
    });

    it('Discard puts the saved values back and hides the bar', async () => {
        api(owner);
        renderApp('/settings/pos');

        const cap = (await screen.findByLabelText('Maximum cashier discount')) as HTMLInputElement;
        fireEvent.change(cap, { target: { value: '15' } });
        fireEvent.click(within(region('Point of sale')!).getByRole('button', { name: 'Discard' }));
        expect(cap.value).toBe('10');
        expect(region('Point of sale')).toBeNull();
    });

    it('Save submits the section, then says what was saved and slides out', async () => {
        const calls = api(owner);
        renderApp('/settings/pos');

        fireEvent.change(await screen.findByLabelText('Due limit per customer'), { target: { value: '7500' } });
        vi.useFakeTimers({ shouldAdvanceTime: true });
        fireEvent.click(within(region('Point of sale')!).getByRole('button', { name: 'Save changes' }));

        await waitFor(() => expect(region('Point of sale')!.textContent).toContain('Point of sale settings saved'));
        expect(calls.mock.calls.some(([url, init]) => String(url).endsWith('/settings/pos') && (init as RequestInit | undefined)?.method === 'PUT')).toBe(true);
        await vi.advanceTimersByTimeAsync(2500);
        await waitFor(() => expect(region('Point of sale')).toBeNull());
    });

    it('is never shown without settings.update', async () => {
        api(readOnly);
        renderApp('/settings/business');

        expect((await screen.findByLabelText('Trade licence no.')).matches(':disabled')).toBe(true);
        expect(region('Business')).toBeNull();
        expect(screen.queryByRole('button', { name: 'Save changes' })).toBeNull();
    });

    it('asks before leaving a section with unsaved changes', async () => {
        api(owner);
        renderApp('/settings/pos');

        fireEvent.change(await screen.findByLabelText('Maximum cashier discount'), { target: { value: '12' } });
        fireEvent.click(screen.getByRole('link', { name: 'Business' }));
        const dialog = await screen.findByRole('dialog', { name: 'Leave without saving?' });
        fireEvent.click(within(dialog).getByRole('button', { name: 'Keep editing' }));
        expect((screen.getByLabelText('Maximum cashier discount') as HTMLInputElement).value).toBe('12');
    });
});
