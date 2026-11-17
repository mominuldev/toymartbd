import { cleanup, fireEvent, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { mockApi, renderApp, viewer } from '@/test/renderApp';
import type { Me } from '@/features/auth/api';
import type { PrintSettings, ReceiptPrint } from './api';

afterEach(() => {
    cleanup();
    localStorage.clear();
    vi.unstubAllGlobals();
});

const owner: Me = { ...viewer, roles: ['super_admin'], permissions: ['dashboard.view', 'settings.view', 'settings.update'] };
const reader: Me = { ...viewer, roles: ['custom'], permissions: ['dashboard.view', 'settings.view'] };

const receipt: ReceiptPrint = {
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
};

const settings: PrintSettings = {
    invoice: { paper: 'a4', language: 'en', colour: 'brand', show_bin: true, show_barcode: true, show_phone: true, show_email: false, show_sku: true, show_options: true, show_payment_stamp: true, show_track_qr: true, show_signatures: true },
    receipt,
    labels: { template: 'roll_50x25', show_name: true, show_options: true, show_price: true, show_store: true, show_digits: true, generate_missing: true },
    saved: [],
};

function printApi(me: Me) {
    return mockApi((url, init) => {
        if (url.endsWith('/auth/me')) return { status: 200, body: me };
        if (url.endsWith('/settings/print') && init?.method === 'PUT') {
            const sent = JSON.parse(String(init.body)) as { receipt: ReceiptPrint };
            return { status: 200, body: { ...settings, receipt: sent.receipt, saved: ['receipt'] } };
        }
        if (url.endsWith('/settings/print')) return { status: 200, body: settings };
        return { status: 404 };
    });
}

const paper = () => screen.getByTestId('pos-receipt');
const form = () => within(screen.getByRole('form', { name: 'Thermal receipt settings' }));

describe('settings → invoices, receipts & labels → thermal receipt', () => {
    it('renders the options and an 80 mm sample receipt', async () => {
        printApi(owner);
        renderApp('/settings/invoice/receipt');

        expect(await screen.findByRole('heading', { name: 'Printer and paper' })).toBeTruthy();
        expect(screen.getByRole('link', { name: 'Thermal receipt' }).getAttribute('aria-current')).toBe('page');
        for (const name of ['Header', 'What the receipt shows', 'Message at the bottom', 'Preview · actual size']) expect(screen.getByRole('heading', { name })).toBeTruthy();
        expect(within(screen.getByRole('group', { name: 'Paper width' })).getByRole('button', { name: '80 mm' }).getAttribute('aria-pressed')).toBe('true');
        expect(screen.getByRole('switch', { name: 'Print automatically after each sale' }).getAttribute('aria-checked')).toBe('true');
        expect(screen.getByRole('switch', { name: 'Customer name' }).getAttribute('aria-checked')).toBe('false');

        // The sample sale, figures as written (no client maths), in the UI language while "ask".
        expect(paper().getAttribute('data-paper')).toBe('80');
        expect(paper().className).toContain('w-[80mm]');
        expect(paper().getAttribute('lang')).toBe('en');
        expect(within(paper()).getByText('৳6,260')).toBeTruthy();
        expect(within(paper()).getByText('Cashier Rina · Counter 1')).toBeTruthy();
        expect(within(paper()).getByText('BIN 000000000-0000')).toBeTruthy();
        expect(within(paper()).getByRole('img', { name: 'Barcode for TMB-26-00221' })).toBeTruthy();
        expect(within(paper()).getByRole('img', { name: 'WhatsApp QR code' })).toBeTruthy();
        expect(within(paper()).getByText('01700-000000')).toBeTruthy();
        expect(within(paper()).getByText('— cut here —')).toBeTruthy();
        expect(within(paper()).getByText('Thank you for shopping with us.')).toBeTruthy();
        expect(screen.queryByRole('button', { name: 'Save changes' })).toBeNull();
    });

    it('updates the preview live as options change, including the roll width', async () => {
        printApi(owner);
        renderApp('/settings/invoice/receipt');
        await screen.findByRole('heading', { name: 'Printer and paper' });

        fireEvent.click(within(screen.getByRole('group', { name: 'Paper width' })).getByRole('button', { name: '58 mm' }));
        expect(paper().getAttribute('data-paper')).toBe('58');
        expect(paper().className).toContain('w-[58mm]');

        fireEvent.click(screen.getByRole('switch', { name: 'BIN' }));
        expect(within(paper()).queryByText('BIN 000000000-0000')).toBeNull();
        fireEvent.click(screen.getByRole('switch', { name: 'Cashier and counter' }));
        expect(within(paper()).queryByText('Cashier Rina · Counter 1')).toBeNull();
        fireEvent.click(screen.getByRole('switch', { name: 'Customer name' }));
        // Masked phone only, whatever is switched on (rule 20).
        expect(within(paper()).getByText('Customer Rubina Akter 018****2310')).toBeTruthy();
        fireEvent.click(screen.getByRole('switch', { name: 'SKU under each item' }));
        expect(within(paper()).getByText('SKU CRY-24')).toBeTruthy();
        fireEvent.click(screen.getByRole('switch', { name: 'Receipt barcode' }));
        expect(within(paper()).queryByRole('img', { name: 'Barcode for TMB-26-00221' })).toBeNull();
        fireEvent.click(screen.getByRole('switch', { name: 'WhatsApp QR code' }));
        expect(within(paper()).queryByRole('img')).toBeNull();
        fireEvent.click(screen.getByRole('switch', { name: '“Cut here” line' }));
        expect(within(paper()).queryByText('— cut here —')).toBeNull();
        fireEvent.click(screen.getByRole('switch', { name: 'Branch name and address' }));
        expect(within(paper()).queryByText('Dhaka HQ · Mirpur 10, Dhaka')).toBeNull();

        fireEvent.click(within(screen.getByRole('group', { name: 'Text size' })).getByRole('button', { name: 'Large' }));
        expect(paper().className).toContain('t-admin-body');

        fireEvent.change(screen.getByLabelText('In English'), { target: { value: 'Exchange within 7 days.' } });
        expect(within(paper()).getByText('Exchange within 7 days.')).toBeTruthy();
    });

    it('prints a Bangla receipt with Western digits when the language is fixed to Bangla', async () => {
        printApi(owner);
        renderApp('/settings/invoice/receipt');
        await screen.findByRole('heading', { name: 'Printer and paper' });

        fireEvent.click(within(form().getByRole('group', { name: 'Language' })).getByRole('button', { name: 'বাংলা' }));
        expect(paper().getAttribute('lang')).toBe('bn');
        expect(within(paper()).getByText('মোট')).toBeTruthy();
        expect(within(paper()).getByText('৳6,260')).toBeTruthy();
        expect(within(paper()).getByText('ক্যাশিয়ার Rina · Counter 1')).toBeTruthy();
        expect(paper().textContent).not.toMatch(/[০-৯]/);
    });

    it('prints the test receipt with the draft settings', async () => {
        const print = vi.fn();
        vi.stubGlobal('print', print);
        printApi(owner);
        renderApp('/settings/invoice/receipt');
        await screen.findByRole('heading', { name: 'Printer and paper' });

        fireEvent.click(within(screen.getByRole('group', { name: 'Paper width' })).getByRole('button', { name: '58 mm' }));
        fireEvent.click(screen.getByRole('button', { name: 'Print test receipt' }));
        expect(print).toHaveBeenCalledTimes(1);
        const printed = screen.getByTestId('pos-receipt-print');
        expect(printed.getAttribute('data-paper')).toBe('58');
        expect(document.getElementById('receipt-print')?.querySelector('style')?.textContent).toContain('size: 58mm auto');

        window.dispatchEvent(new Event('afterprint'));
        await waitFor(() => expect(screen.queryByTestId('pos-receipt-print')).toBeNull());
    });

    it('saves the whole receipt document in one PUT', async () => {
        const calls = printApi(owner);
        renderApp('/settings/invoice/receipt');
        await screen.findByRole('heading', { name: 'Printer and paper' });

        fireEvent.click(within(screen.getByRole('group', { name: 'Paper width' })).getByRole('button', { name: '58 mm' }));
        fireEvent.click(within(form().getByRole('group', { name: 'Language' })).getByRole('button', { name: 'বাংলা' }));
        fireEvent.click(screen.getByRole('switch', { name: 'Print automatically after each sale' }));
        fireEvent.click(screen.getByRole('switch', { name: 'Customer name' }));
        fireEvent.change(screen.getByLabelText('In Bangla'), { target: { value: '  আবার আসবেন  ' } });
        expect(screen.getByRole('region', { name: 'Save changes to Thermal receipt' }).textContent).toContain('Printer and paper, what the receipt shows, and bottom message changed');

        fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));
        await waitFor(() => expect(screen.getByText('Receipt settings saved — the next sale prints with them.')).toBeTruthy());
        const put = calls.mock.calls.find(([url, init]) => String(url).endsWith('/settings/print') && (init as RequestInit | undefined)?.method === 'PUT');
        expect(JSON.parse(String((put?.[1] as RequestInit).body))).toEqual({
            receipt: { ...receipt, paper: '58', language: 'bn', auto_print: false, show_customer_name: true, footer_bn: 'আবার আসবেন' },
        });
    });

    it('is read-only without settings.update', async () => {
        printApi(reader);
        renderApp('/settings/invoice/receipt');

        expect(await screen.findByText('Read only — you can see these settings but not change them.')).toBeTruthy();
        // Disabled through the options' <fieldset disabled>, and ignored if clicked anyway.
        const bin = await screen.findByRole('switch', { name: 'BIN' });
        expect(bin.closest('fieldset[disabled]')).not.toBeNull();
        const narrow = within(screen.getByRole('group', { name: 'Paper width' })).getByRole('button', { name: '58 mm' });
        expect(narrow.closest('fieldset[disabled]')).not.toBeNull();
        fireEvent.click(narrow);
        expect(paper().getAttribute('data-paper')).toBe('80');
        expect((screen.getByLabelText('In English') as HTMLInputElement).disabled).toBe(true);
        expect(screen.queryByRole('button', { name: 'Save changes' })).toBeNull();
        // The test print still works for a reader.
        expect((screen.getByRole('button', { name: 'Print test receipt' }) as HTMLButtonElement).disabled).toBe(false);
    });
});
