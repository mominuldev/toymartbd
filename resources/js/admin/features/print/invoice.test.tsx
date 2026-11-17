import { cleanup, fireEvent, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { mockApi, renderApp, viewer } from '@/test/renderApp';
import type { Me } from '@/features/auth/api';
import type { InvoiceSettings } from '@/features/invoices/api';
import type { PrintSettings } from './api';
import { invoicePreviewPath } from './invoiceApi';

afterEach(() => {
    cleanup();
    localStorage.clear();
    vi.unstubAllGlobals();
});

const manager: Me = { ...viewer, roles: ['manager'], permissions: ['dashboard.view', 'settings.view', 'settings.update'] };
const readOnly: Me = { ...viewer, roles: ['viewer'], permissions: ['dashboard.view', 'settings.view'] };

const company: InvoiceSettings = {
    name_en: 'Toy Mart BD',
    name_bn: 'টয় মার্ট বিডি',
    address_en: 'House 12, Road 5, Dhanmondi, Dhaka',
    address_bn: 'বাড়ি 12, রোড 5, ধানমন্ডি, ঢাকা',
    hotline: '09600 111222',
    email: null,
    website: null,
    trade_licence: 'TRAD/DSCC/111111',
    bin: '000111222-0101',
    logo_media_id: 12,
    logo_url: 'http://localhost/storage/media/logo/320.webp',
    footer_en: null,
    footer_bn: null,
    placeholders: [],
};

const invoice: PrintSettings['invoice'] = {
    paper: 'a4',
    language: 'en',
    colour: 'brand',
    show_bin: true,
    show_barcode: true,
    show_phone: true,
    show_email: false,
    show_sku: true,
    show_options: true,
    show_payment_stamp: true,
    show_track_qr: true,
    show_signatures: true,
};

const print: PrintSettings = {
    invoice,
    receipt: {
        paper: '80',
        language: 'ask',
        text_size: 'normal',
        auto_print: true,
        show_logo: true,
        show_branch: true,
        show_bin: true,
        show_cashier: true,
        show_customer_name: false,
        show_sku: false,
        show_barcode: true,
        show_whatsapp_qr: true,
        show_cut_line: true,
        footer_en: null,
        footer_bn: null,
    },
    labels: { template: 'roll_50x25', show_name: true, show_options: true, show_price: true, show_store: true, show_digits: true, generate_missing: true },
    saved: [],
} as PrintSettings;

function printApi(me: Me) {
    return mockApi((url, init) => {
        if (url.endsWith('/auth/me')) return { status: 200, body: me };
        if (url.endsWith('/settings/invoice') && init?.method === 'PUT') return { status: 200, body: { ...company, ...(JSON.parse(String(init.body)) as object), placeholders: [] } };
        if (url.endsWith('/settings/invoice')) return { status: 200, body: company };
        if (url.endsWith('/settings/print') && init?.method === 'PUT') {
            const body = JSON.parse(String(init.body)) as { invoice: PrintSettings['invoice'] };
            return { status: 200, body: { ...print, invoice: body.invoice, saved: ['invoice'] } };
        }
        if (url.endsWith('/settings/print')) return { status: 200, body: print };
        if (url.includes('/settings/print/invoice-preview')) return { status: 200, body: {} };
        return { status: 404 };
    });
}

/** A choice row on the tab (the app bar has its own "Language" switch). */
const group = (name: string) => within(document.getElementById('invoice-print-settings')!).getByRole('group', { name });

const puts = (calls: ReturnType<typeof printApi>, path: string) =>
    calls.mock.calls.filter(([url, init]) => String(url).endsWith(path) && (init as RequestInit | undefined)?.method === 'PUT').map(([, init]) => JSON.parse(String((init as RequestInit).body)) as unknown);

async function openTab(me: Me = manager, locale: 'en' | 'bn' = 'en') {
    const calls = printApi(me);
    renderApp('/settings/invoice', { locale });
    const preview = await screen.findByTestId('invoice-preview', {}, { timeout: 10_000 });
    return { calls, preview };
}

describe('A4 invoice print settings', () => {
    it('renders the options and a live preview that follows every choice', async () => {
        const { preview } = await openTab();

        expect(screen.getByRole('link', { name: 'A4 invoice' })).toBeTruthy();
        expect(screen.getByRole('heading', { name: 'Preview · A4 · English' })).toBeTruthy();
        const doc = within(preview);
        expect(doc.getByText('INV-26-000126')).toBeTruthy();
        expect(doc.getByText('TMB-RC-01')).toBeTruthy();
        expect(doc.getByText('Red · 1:16')).toBeTruthy();
        expect(doc.getByText('09600 111222', { exact: false })).toBeTruthy();
        expect(preview.querySelector('[data-part="barcode"]')).toBeTruthy();
        expect(preview.querySelector('[data-part="email"]')).toBeNull();

        fireEvent.click(screen.getByRole('switch', { name: 'SKU column' }));
        fireEvent.click(screen.getByRole('switch', { name: 'Product options (size, colour)' }));
        fireEvent.click(screen.getByRole('switch', { name: 'Order barcode' }));
        fireEvent.click(screen.getByRole('switch', { name: 'Customer email' }));
        fireEvent.click(screen.getByRole('switch', { name: 'Payment stamp' }));
        fireEvent.click(screen.getByRole('switch', { name: 'Show BIN and trade licence' }));
        fireEvent.click(screen.getByRole('switch', { name: 'Track-order QR code' }));
        fireEvent.click(screen.getByRole('switch', { name: 'Signature lines' }));
        fireEvent.click(screen.getByRole('switch', { name: 'Customer phone' }));
        expect(doc.queryByText('TMB-RC-01')).toBeNull();
        expect(doc.queryByText('Red · 1:16')).toBeNull();
        for (const part of ['barcode', 'stamp', 'bin', 'qr', 'signatures', 'phone']) expect(preview.querySelector(`[data-part="${part}"]`)).toBeNull();
        expect(doc.getByText('rahela@example.com')).toBeTruthy();

        fireEvent.click(within(group('Paper')).getByRole('button', { name: 'A5' }));
        fireEvent.click(within(group('Colour')).getByRole('button', { name: 'Black & white' }));
        fireEvent.click(within(group('Language')).getByRole('button', { name: 'বাংলা' }));
        expect(preview.dataset.paper).toBe('a5');
        expect(preview.dataset.colour).toBe('mono');
        expect(screen.getByRole('heading', { name: 'Preview · A5 · Bangla' })).toBeTruthy();
        expect(doc.getByText('ইনভয়েস')).toBeTruthy();
        expect(doc.getByText('রাহেলা খাতুন')).toBeTruthy();

        fireEvent.click(within(group('Language')).getByRole('button', { name: 'Both' }));
        // English with the Bangla label beneath.
        expect(doc.getByText('Subtotal')).toBeTruthy();
        expect(doc.getByText('সাবটোটাল')).toBeTruthy();
    });

    it('saves only the print settings when only they changed, as the whole invoice document', async () => {
        const { calls } = await openTab();

        fireEvent.click(within(group('Paper')).getByRole('button', { name: 'A5' }));
        fireEvent.click(screen.getByRole('switch', { name: 'SKU column' }));
        expect(screen.getByText('Paper and SKU column changed')).toBeTruthy();
        fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));

        await waitFor(() => expect(puts(calls, '/settings/print')).toHaveLength(1));
        expect(puts(calls, '/settings/print')[0]).toEqual({ invoice: { ...invoice, paper: 'a5', show_sku: false } });
        expect(puts(calls, '/settings/invoice')).toHaveLength(0);
        expect(await screen.findByText('Saved. Invoices print this way from now on.')).toBeTruthy();
    });

    it('saves store details and the layout together, keeping the Business identity and logo', async () => {
        const { calls, preview } = await openTab();

        const address = screen.getByLabelText(/^Address/, { selector: 'input:not([lang])' });
        fireEvent.change(address, { target: { value: 'House 7, Mirpur' } });
        fireEvent.change(screen.getByLabelText(/^English/, { selector: 'textarea' }), { target: { value: 'Exchange within 7 days.' } });
        // The preview reads the draft.
        expect(within(preview).getByText('House 7, Mirpur')).toBeTruthy();
        expect(within(preview).getByText('Exchange within 7 days.')).toBeTruthy();
        fireEvent.click(screen.getByRole('switch', { name: 'Customer email' }));
        fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));

        await waitFor(() => expect(puts(calls, '/settings/print')).toHaveLength(1));
        expect(puts(calls, '/settings/invoice')).toEqual([
            {
                name_en: 'Toy Mart BD',
                name_bn: 'টয় মার্ট বিডি',
                address_en: 'House 7, Mirpur',
                address_bn: 'বাড়ি 12, রোড 5, ধানমন্ডি, ঢাকা',
                trade_licence: 'TRAD/DSCC/111111',
                bin: '000111222-0101',
                hotline: '09600 111222',
                email: null,
                website: null,
                footer_en: 'Exchange within 7 days.',
                footer_bn: null,
                logo_media_id: 12,
            },
        ]);
        expect(puts(calls, '/settings/print')[0]).toEqual({ invoice: { ...invoice, show_email: true } });
    });

    it('removes the logo with the next save', async () => {
        const { calls } = await openTab();

        expect(screen.getByRole('button', { name: 'Replace logo' }).textContent).toBe('Change');
        fireEvent.click(screen.getByRole('button', { name: 'Remove logo' }));
        expect(screen.getByText('No logo — invoices print the business name only')).toBeTruthy();
        expect(screen.getByRole('link', { name: /Edit in Business/ }).getAttribute('href')).toBe('/settings/business');
        fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));

        await waitFor(() => expect(puts(calls, '/settings/invoice')).toHaveLength(1));
        expect(puts(calls, '/settings/invoice')[0]).toMatchObject({ logo_media_id: null });
        expect(puts(calls, '/settings/print')).toHaveLength(0);
    });

    it('opens the real PDF of the draft in a new tab', async () => {
        const { calls } = await openTab();
        const open = vi.spyOn(window, 'open').mockReturnValue(null);
        URL.createObjectURL = vi.fn(() => 'blob:preview');
        URL.revokeObjectURL = vi.fn();

        fireEvent.click(within(group('Language')).getByRole('button', { name: 'Both' }));
        fireEvent.click(screen.getByRole('button', { name: 'Open full size' }));

        const path = invoicePreviewPath({ ...invoice, language: 'both' });
        expect(path).toBe(
            '/settings/print/invoice-preview?paper=a4&language=both&colour=brand&show_bin=1&show_barcode=1&show_phone=1&show_email=0&show_sku=1&show_options=1&show_payment_stamp=1&show_track_qr=1&show_signatures=1',
        );
        await waitFor(() => expect(calls.mock.calls.some(([url]) => String(url) === `/api/v1/admin${path}`)).toBe(true));
        await waitFor(() => expect(open).toHaveBeenCalledWith('blob:preview', '_blank', 'noopener'));
        open.mockRestore();
    });

    it('is read-only without settings.update, and the preview still works', async () => {
        const { preview } = await openTab(readOnly);

        expect(screen.getByRole('switch', { name: 'SKU column' }).matches(':disabled')).toBe(true);
        expect(within(group('Paper')).getByRole('button', { name: 'A5' }).matches(':disabled')).toBe(true);
        expect(screen.getByLabelText(/^Address/, { selector: 'input:not([lang])' }).matches(':disabled')).toBe(true);
        expect(screen.getByRole('button', { name: 'Open full size' }).matches(':disabled')).toBe(false);
        expect(screen.queryByRole('button', { name: 'Save changes' })).toBeNull();
        expect(within(preview).getByText('TMB-RC-01')).toBeTruthy();
    });

    it('reads in Bangla with Western digits', async () => {
        const { preview } = await openTab(manager, 'bn');

        expect(screen.getByRole('heading', { name: 'প্রিভিউ · A4 · ইংরেজি' })).toBeTruthy();
        expect(screen.getByRole('switch', { name: 'SKU কলাম' })).toBeTruthy();
        expect(screen.getByText('“ক্যাশ অন ডেলিভারি · ৳6,260 বাকি” অথবা “পরিশোধিত”')).toBeTruthy();
        fireEvent.click(within(group('ভাষা')).getByRole('button', { name: 'বাংলা' }));
        expect(within(preview).getByText('ক্যাশ অন ডেলিভারি · ৳6,260 বাকি')).toBeTruthy();
        // Rule 36: every figure Western, in Bangla too.
        const text = preview.textContent ?? '';
        expect(text).not.toMatch(/[০-৯]/);
        expect(document.body.textContent).not.toMatch(/[০-৯]/);
        expect(text).toContain('৳6,260');
    });
});
