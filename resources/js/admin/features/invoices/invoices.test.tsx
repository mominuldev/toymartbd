import { cleanup, fireEvent, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { mockApi, renderApp, viewer } from '@/test/renderApp';
import type { Me } from '@/features/auth/api';
import type { InvoiceListItem, InvoiceSettings } from './api';
import { invoicesPath as invoicesPathFor } from './api';
import { formatMoney } from './parts';

afterEach(() => {
    cleanup();
    localStorage.clear();
});

const manager: Me = { ...viewer, roles: ['manager'], permissions: ['dashboard.view', 'orders.view', 'customers.view_pii', 'settings.view', 'settings.update'] };
const readOnly: Me = { ...viewer, roles: ['viewer'], permissions: ['dashboard.view', 'orders.view', 'settings.view'] };

const row: InvoiceListItem = {
    id: 7,
    type: 'sales',
    number: 'INV-26-000007',
    reference: 'TMB-26-00482',
    order_id: 482,
    purchase_id: null,
    issued_to_name: 'রাহেলা খাতুন',
    total: '2450.00',
    paid: '0.00',
    due: '2450.00',
    payment_method: 'cod',
    placeholders: [],
    issued_at: '2026-11-13T06:00:00+00:00',
    can_print: true,
};

const settings: InvoiceSettings = {
    name_en: 'Toy Mart BD',
    name_bn: null,
    address_en: null,
    address_bn: null,
    hotline: '09600 111222',
    email: null,
    website: null,
    trade_licence: null,
    bin: null,
    logo_media_id: 12,
    footer_en: null,
    footer_bn: null,
    placeholders: ['name_bn', 'address_en', 'address_bn', 'trade_licence', 'bin'],
};

function invoicesApi(me: Me) {
    return mockApi((url, init) => {
        if (url.endsWith('/auth/me')) return { status: 200, body: me };
        if (url.includes('/invoices?') || url.endsWith('/invoices')) return { status: 200, body: { data: [row], meta: { next_cursor: null } } };
        if (url.endsWith('/settings/invoice') && init?.method === 'PUT') return { status: 200, body: { ...settings, ...(JSON.parse(String(init.body)) as object), placeholders: [] } };
        if (url.endsWith('/settings/invoice')) return { status: 200, body: settings };
        return { status: 404 };
    });
}

describe('invoices', () => {
    it('lists issued invoices with the server figures and a link to the order', async () => {
        invoicesApi(manager);
        renderApp('/invoices');

        expect(await screen.findByText('INV-26-000007', {}, { timeout: 10_000 })).toBeTruthy();
        expect(screen.getByText('রাহেলা খাতুন')).toBeTruthy();
        expect(screen.getByRole('link', { name: 'TMB-26-00482' }).getAttribute('href')).toBe('/orders/482');
        expect(screen.getAllByText('2,450').length).toBe(2);
        expect(screen.getByRole('button', { name: 'Reprint invoice INV-26-000007' })).toBeTruthy();
    });

    it('sends type, search and dates to the server allowlist', async () => {
        const calls = invoicesApi(manager);
        renderApp('/invoices?type=purchase&from=2026-11-01&to=2026-11-13&q=INV-26');

        await screen.findByText('INV-26-000007');
        const urls = calls.mock.calls.map(([url]) => String(url)).filter((u) => u.includes('/invoices?'));
        expect(urls.some((u) => u.includes('filter%5Btype%5D=purchase') && u.includes('filter%5Bissued_from%5D=2026-11-01') && u.includes('filter%5Bissued_to%5D=2026-11-13') && u.includes('filter%5Bsearch%5D=INV-26'))).toBe(true);
        expect(invoicesPathFor({ type: '', search: ' ', from: '', to: '' }, null)).toBe('/invoices');
        expect(formatMoney('1250000.50')).toBe('1,250,000.5');
    });

    it('saves invoice details, keeping the logo and sending blanks as null', async () => {
        const calls = invoicesApi(manager);
        renderApp('/settings/invoice');

        const bin = await screen.findByLabelText(/BIN/);
        expect(screen.getByRole('status').textContent).toContain('Trade licence no.');
        fireEvent.change(bin, { target: { value: '000111222-0101' } });
        fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));

        await waitFor(() => expect(calls.mock.calls.some(([, init]) => (init as RequestInit | undefined)?.method === 'PUT')).toBe(true));
        const call = calls.mock.calls.find(([, init]) => (init as RequestInit | undefined)?.method === 'PUT')!;
        expect(JSON.parse(String((call[1] as RequestInit).body))).toMatchObject({ name_en: 'Toy Mart BD', bin: '000111222-0101', name_bn: null, logo_media_id: 12 });
        expect(await screen.findByText('Invoice details saved. New invoices will use them.')).toBeTruthy();
    });

    it('shows the details read-only without settings.update', async () => {
        invoicesApi(readOnly);
        renderApp('/settings/invoice');

        expect((await screen.findByLabelText(/BIN/)).matches(':disabled')).toBe(true);
        expect(screen.queryByRole('button', { name: 'Save changes' })).toBeNull();
    });
});
