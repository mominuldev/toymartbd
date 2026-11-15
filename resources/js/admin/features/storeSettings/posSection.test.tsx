import { cleanup, fireEvent, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { mockApi, renderApp, viewer } from '@/test/renderApp';
import type { Me } from '@/features/auth/api';
import type { PosSettings } from './posSettingsApi';

afterEach(() => {
    cleanup();
    localStorage.clear();
});

const owner: Me = { ...viewer, roles: ['super_admin'], permissions: ['dashboard.view', 'settings.view', 'settings.update'] };
const reader: Me = { ...viewer, roles: ['custom'], permissions: ['dashboard.view', 'settings.view'] };

const unsaved: PosSettings = { discount_max_percent: '10', discount_max_percent_default: '10', discount_saved: false, due_limit: '5000.00', due_limit_default: '5000.00', due_saved: false };

function posApi(me: Me) {
    return mockApi((url, init) => {
        if (url.endsWith('/auth/me')) return { status: 200, body: me };
        if (url.endsWith('/settings/pos') && init?.method === 'PUT') {
            const sent = JSON.parse(String(init.body)) as { discount_max_percent: string; due_limit: string };
            if (sent.discount_max_percent === '150') {
                return { status: 422, body: { type: 'validation', title: 'Invalid', status: 422, errors: [{ field: 'discount_max_percent', message: 'The discount max percent may not be greater than 100.' }] } };
            }
            return { status: 200, body: { ...unsaved, discount_max_percent: sent.discount_max_percent, discount_saved: true, due_limit: `${sent.due_limit}.00`, due_saved: true } };
        }
        if (url.endsWith('/settings/pos')) return { status: 200, body: unsaved };
        return { status: 404 };
    });
}

describe('store settings → point of sale', () => {
    it('shows the default cap and due limit, saves both with the generated-schema body', async () => {
        const calls = posApi(owner);
        renderApp('/settings/pos');

        const input = (await screen.findByLabelText('Maximum cashier discount')) as HTMLInputElement;
        expect(input.value).toBe('10');
        expect(screen.getByText('Not saved yet — the store default of 10% applies. 0 turns cashier discounts off.')).toBeTruthy();
        const due = screen.getByLabelText('Due limit per customer') as HTMLInputElement;
        expect(due.value).toBe('5000');
        expect(screen.getByText('Not saved yet — the store default of ৳5000 applies. 0 stops new sales on due.')).toBeTruthy();
        expect(screen.getByRole('link', { name: 'Point of sale' })).toBeTruthy();
        const save = screen.getByRole('button', { name: 'Save' });
        expect(save).toHaveProperty('disabled', true);

        fireEvent.change(input, { target: { value: '12.5' } });
        fireEvent.change(due, { target: { value: '7500' } });
        fireEvent.click(save);
        await waitFor(() => expect(screen.getByText('Point of sale settings saved')).toBeTruthy());
        const put = calls.mock.calls.find(([url, init]) => String(url).endsWith('/settings/pos') && (init as RequestInit | undefined)?.method === 'PUT');
        expect(JSON.parse(String((put?.[1] as RequestInit).body))).toEqual({ discount_max_percent: '12.5', due_limit: '7500' });
        expect(due.value).toBe('7500');
    });

    it('checks the shape before sending and shows the server’s range error', async () => {
        posApi(owner);
        renderApp('/settings/pos');

        const input = await screen.findByLabelText('Maximum cashier discount');
        fireEvent.change(input, { target: { value: 'ten' } });
        fireEvent.click(screen.getByRole('button', { name: 'Save' }));
        expect(await screen.findByText('Enter a percent from 0 to 100, at most two decimals.')).toBeTruthy();
        fireEvent.change(input, { target: { value: '10' } });
        fireEvent.change(screen.getByLabelText('Due limit per customer'), { target: { value: '5,000' } });
        fireEvent.click(screen.getByRole('button', { name: 'Save' }));
        expect(await screen.findByText('Enter an amount in taka, at most two decimals.')).toBeTruthy();
        fireEvent.change(screen.getByLabelText('Due limit per customer'), { target: { value: '5000' } });

        fireEvent.change(input, { target: { value: '150' } });
        fireEvent.click(screen.getByRole('button', { name: 'Save' }));
        expect(await screen.findByText('The discount max percent may not be greater than 100.')).toBeTruthy();
    });

    it('is read-only without settings.update', async () => {
        posApi(reader);
        renderApp('/settings/pos');

        expect(((await screen.findByLabelText('Maximum cashier discount')) as HTMLInputElement).disabled).toBe(true);
        expect((screen.getByLabelText('Due limit per customer') as HTMLInputElement).disabled).toBe(true);
        expect(screen.queryByRole('button', { name: 'Save' })).toBeNull();
    });
});
