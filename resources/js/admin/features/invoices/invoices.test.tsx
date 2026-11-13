import { cleanup, fireEvent, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { mockApi, renderApp, viewer } from '@/test/renderApp';
import type { Me } from '@/features/auth/api';
import type { CreditNoteListItem, InvoiceListItem, InvoiceSettings } from './api';
import { creditNotesPath, invoicesPath as invoicesPathFor } from './api';
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
    kind: 'original',
    original_invoice_id: null,
    original_invoice_number: null,
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
    invoiced_total: '2450.00',
    credited: '0.00',
    balance: '2450.00',
    credit_status: 'none',
    credit_notes: [],
    supplementary_invoices: [],
};

const creditNote: CreditNoteListItem = {
    id: 3,
    number: 'CN-26-000003',
    invoice_id: 8,
    invoice_number: 'INV-26-000008',
    order_id: 483,
    reason: 'refund',
    source_reference: 'RMA-26-00012',
    issued_to_name: 'Nusrat Jahan',
    total: '1250.00',
    issued_at: '2026-11-13T07:00:00+00:00',
    can_print: true,
};

const credited: InvoiceListItem = {
    ...row,
    id: 8,
    number: 'INV-26-000008',
    reference: 'TMB-26-00483',
    order_id: 483,
    issued_to_name: 'Nusrat Jahan',
    total: '3070.00',
    due: '0.00',
    credited: '1250.00',
    balance: '1820.00',
    credit_status: 'partial',
    credit_notes: [creditNote],
};

/** P8 batch 3: an order edited upwards after issue — the original lists its supplementary invoice. */
const edited: InvoiceListItem = {
    ...row,
    id: 9,
    number: 'INV-26-000009',
    issued_to_name: 'Farhana Akter',
    reference: 'TMB-26-00484',
    order_id: 484,
    total: '1130.00',
    due: '1130.00',
    invoiced_total: '2330.00',
    balance: '1930.00',
    credited: '400.00',
    credit_status: 'partial',
    credit_notes: [{ ...creditNote, id: 4, number: 'CN-26-000004', invoice_id: 9, invoice_number: 'INV-26-000009', order_id: 484, reason: 'order_edited', source_reference: 'TMB-26-00484', total: '400.00' }],
    supplementary_invoices: [{ id: 10, number: 'INV-26-000010', total: '1200.00', issued_at: '2026-11-13T08:00:00+00:00', can_print: true }],
};

const supplementary: InvoiceListItem = {
    ...row,
    id: 10,
    kind: 'supplementary',
    issued_to_name: 'Farhana Akter',
    original_invoice_id: 9,
    original_invoice_number: 'INV-26-000009',
    number: 'INV-26-000010',
    reference: 'TMB-26-00484',
    order_id: 484,
    total: '1200.00',
    due: '1200.00',
    invoiced_total: '1200.00',
    balance: '1200.00',
};

const rto: CreditNoteListItem = { ...creditNote, id: 5, number: 'CN-26-000005', reason: 'courier_return', source_reference: 'TMB-26-00490' };

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
        if (url.includes('/credit-notes?') || url.endsWith('/credit-notes')) return { status: 200, body: { data: [creditNote, rto], meta: { next_cursor: null } } };
        if (url.includes('/invoices?') || url.endsWith('/invoices')) return { status: 200, body: { data: [row, credited, edited, supplementary], meta: { next_cursor: null } } };
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
        // Only the edited order's original shows the server's invoiced total.
        expect(screen.getAllByText((_, el) => el?.tagName === 'SPAN' && (el.textContent ?? '').startsWith('With supplementary'))).toHaveLength(1);
        expect(screen.queryByText('Credited in full')).toBeNull();
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

    it('shows what an invoice has been credited and prints its credit note', async () => {
        const calls = invoicesApi(manager);
        const open = vi.spyOn(window, 'open').mockReturnValue(null);
        renderApp('/invoices');

        expect(await screen.findByText('INV-26-000008', {}, { timeout: 10_000 })).toBeTruthy();
        expect(screen.getByText('1,250')).toBeTruthy();
        expect(screen.getAllByText('Partly credited')).toHaveLength(2);
        const list = screen.getByRole('list', { name: 'Credit notes for INV-26-000008' });
        fireEvent.click(within(list).getByRole('button', { name: 'Print credit note CN-26-000003' }));

        await waitFor(() => expect(calls.mock.calls.some(([url]) => String(url).endsWith('/credit-notes/3/pdf'))).toBe(true));
        open.mockRestore();
    });

    it('marks a supplementary invoice and links it from its original, with the server figures', async () => {
        const calls = invoicesApi(manager);
        const open = vi.spyOn(window, 'open').mockReturnValue(null);
        renderApp('/invoices');

        expect(await screen.findByText('INV-26-000009', {}, { timeout: 10_000 })).toBeTruthy();
        expect(screen.getByText('Supplementary')).toBeTruthy();
        expect(screen.getByText('Adds to INV-26-000009')).toBeTruthy();
        expect(screen.getByText((_, el) => el?.tagName === 'SPAN' && el.textContent === 'With supplementary ৳2,330')).toBeTruthy();
        expect(screen.queryByText('Order edited')).toBeNull(); // the reason shows on the Credit notes tab
        const list = screen.getByRole('list', { name: 'Supplementary invoices for INV-26-000009' });
        fireEvent.click(within(list).getByRole('button', { name: 'Print supplementary invoice INV-26-000010' }));
        await waitFor(() => expect(calls.mock.calls.some(([url]) => String(url).endsWith('/invoices/10/pdf'))).toBe(true));
        expect(screen.getByRole('list', { name: 'Credit notes for INV-26-000009' })).toBeTruthy();
        open.mockRestore();
    });

    it('labels the new credit-note reasons in English and Bangla', async () => {
        invoicesApi(manager);
        renderApp('/invoices?type=credit_notes');

        expect(await screen.findByText('CN-26-000005', {}, { timeout: 10_000 })).toBeTruthy();
        expect(screen.getByText('Courier return (RTO)')).toBeTruthy();
        cleanup();

        invoicesApi(manager);
        renderApp('/invoices?type=credit_notes', { locale: 'bn' });
        expect(await screen.findByText('কুরিয়ার ফেরত (RTO)', {}, { timeout: 10_000 })).toBeTruthy();
    }, 30_000);

    it('lists credit notes on their own tab from the credit-note endpoint', async () => {
        const calls = invoicesApi(manager);
        renderApp('/invoices?type=credit_notes&q=RMA-26');

        expect(await screen.findByText('CN-26-000003', {}, { timeout: 10_000 })).toBeTruthy();
        expect(screen.getByText('Refund paid')).toBeTruthy();
        expect(screen.getByRole('link', { name: 'RMA-26-00012' }).getAttribute('href')).toBe('/orders/483');
        expect(screen.getByRole('button', { name: 'Print credit note CN-26-000003' })).toBeTruthy();
        const urls = calls.mock.calls.map(([url]) => String(url));
        expect(urls.some((u) => u.includes('/credit-notes?') && u.includes('filter%5Bsearch%5D=RMA-26'))).toBe(true);
        expect(urls.some((u) => u.includes('/invoices?') || u.endsWith('/invoices'))).toBe(false);
        expect(creditNotesPath({ search: '', from: '2026-11-01', to: '' }, null)).toBe('/credit-notes?filter%5Bissued_from%5D=2026-11-01');
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
