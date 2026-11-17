import { cleanup, fireEvent, screen, waitFor, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { printLabelsRequestSchema, updatePrintSettingsRequestSchema } from '@toymart/api-types/zod';
import { mockApi, renderApp, viewer } from '@/test/renderApp';
import type { Me } from '@/features/auth/api';
import { listItem, product, variant } from '@/features/catalog/products/fixtures';
import type { Product } from '@/features/catalog/products/api';
import type { LabelsPrint, PrintSettings } from './api';

const defaults: LabelsPrint = { template: 'roll_50x25', show_name: true, show_options: true, show_price: true, show_store: true, show_digits: true, generate_missing: true };

const settings = (labels: LabelsPrint = defaults): PrintSettings =>
    ({
        invoice: { paper: 'a4', language: 'en', colour: 'brand', show_bin: true, show_barcode: true, show_phone: true, show_email: false, show_sku: true, show_options: true, show_payment_stamp: true, show_track_qr: true, show_signatures: true },
        receipt: { paper: '80', language: 'ask', text_size: 'normal', auto_print: true, show_logo: true, show_branch: true, show_bin: true, show_cashier: true, show_customer_name: false, show_sku: false, show_barcode: true, show_whatsapp_qr: true, show_cut_line: true, footer_en: null, footer_bn: null },
        labels,
        saved: [],
    }) as PrintSettings;

const owner: Me = { ...viewer, roles: ['super_admin'], permissions: ['dashboard.view', 'settings.view', 'settings.update', 'catalog.view', 'catalog.update'] };
const reader: Me = { ...viewer, roles: ['custom'], permissions: ['dashboard.view', 'settings.view'] };

/** Kids Tricycle: Red has a barcode, Blue does not; an archived variant never prints. */
const tricycle: Product = {
    ...product,
    id: 42,
    name: 'Kids Tricycle',
    name_bn: 'বাচ্চাদের ট্রাইসাইকেল',
    variants: [
        variant(100, [10, 20], { name: 'Red', name_bn: 'লাল', barcode: '2000000001173' }),
        variant(101, [11, 20], { name: 'Blue', name_bn: 'নীল', barcode: null }),
        variant(102, [12, 20], { name: 'Green', status: 'archived', barcode: '2000000001180' }),
    ],
};

function labelsApi(me: Me, labels: LabelsPrint = defaults) {
    return mockApi((url, init) => {
        if (url.endsWith('/auth/me')) return { status: 200, body: me };
        if (url.endsWith('/settings/print') && init?.method === 'PUT') {
            const sent = JSON.parse(String(init.body)) as { labels: LabelsPrint };
            return { status: 200, body: { ...settings(sent.labels), saved: ['labels'] } };
        }
        if (url.endsWith('/settings/print')) return { status: 200, body: settings(labels) };
        if (url.includes('/products?')) return { status: 200, body: { data: [{ ...listItem, id: 42, name: 'Kids Tricycle', name_bn: 'বাচ্চাদের ট্রাইসাইকেল' }], meta: { next_cursor: null } } };
        if (/\/products\/42$/.test(url)) return { status: 200, body: tricycle };
        if (url.endsWith('/products/labels')) return { status: 200, body: {} };
        return { status: 404 };
    });
}

const sent = (calls: ReturnType<typeof mockApi>, path: string, method: string) =>
    calls.mock.calls.filter(([url, init]) => String(url).endsWith(path) && (init as RequestInit | undefined)?.method === method).map(([, init]) => JSON.parse(String((init as RequestInit).body)) as Record<string, unknown>);

const preview = () => screen.getByRole('img', { name: /^(Sample label|নমুনা লেবেল)$/ });
const part = (name: string) => preview().querySelector(`[data-part="${name}"]`);

beforeEach(() => {
    Object.assign(URL, { createObjectURL: vi.fn(() => 'blob:labels'), revokeObjectURL: vi.fn() });
    vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => undefined);
});

afterEach(() => {
    cleanup();
    localStorage.clear();
    vi.restoreAllMocks();
});

describe('settings › invoices, receipts & labels › barcode labels', () => {
    it('shows the saved paper as a radio group and the sample label at its proportions', async () => {
        labelsApi(owner);
        renderApp('/settings/invoice/labels');

        const group = await screen.findByRole('radiogroup', { name: 'Label paper' });
        expect(screen.getByRole('link', { name: 'Barcode labels' }).getAttribute('aria-current')).toBe('page');
        const radios = within(group).getAllByRole('radio') as HTMLInputElement[];
        expect(radios.map((r) => r.value)).toEqual(['roll_38x25', 'roll_50x25', 'a4_65', 'a4_24']);
        expect((within(group).getByRole('radio', { name: /Roll 50 × 25 mm/ }) as HTMLInputElement).checked).toBe(true);
        expect(within(group).getByText('70 × 37 mm each · office printer')).toBeTruthy();
        expect(within(group).getByText('38.1 × 21.2 mm each · office printer')).toBeTruthy();
        expect(screen.getByText('EAN-13. Products without one get an in-store code (starts with 20) when you print.')).toBeTruthy();

        expect(preview().getAttribute('data-paper')).toBe('roll_50x25');
        expect(preview().style.width).toBe('calc(75.000 * var(--spacing))');
        expect(preview().style.height).toBe('calc(37.500 * var(--spacing))');
        for (const name of ['store', 'name', 'options', 'digits', 'price']) expect(part(name)).toBeTruthy();
        expect(part('price')?.textContent).toBe('৳4,900');

        fireEvent.click(within(group).getByRole('radio', { name: /A4 sheet · 24 labels/ }));
        expect(preview().getAttribute('data-paper')).toBe('a4_24');
        expect(preview().style.width).toBe('calc(105.000 * var(--spacing))');
        expect(screen.getByText('A4 sheet · 24 labels · one label, 70 × 37 mm, actual proportions')).toBeTruthy();
        // No save bar until something changes; now one.
        expect(screen.getByRole('region', { name: 'Save changes to Barcode labels' }).textContent).toContain('Label paper changed');
    });

    it('shows and hides each line of the preview with its toggle', async () => {
        labelsApi(owner);
        renderApp('/settings/invoice/labels');
        await screen.findByRole('radiogroup', { name: 'Label paper' });

        const cases = [
            ['Product name', 'name'],
            ['Options (size, colour)', 'options'],
            ['Price', 'price'],
            ['Store name', 'store'],
            ['Barcode number under the bars', 'digits'],
        ] as const;
        for (const [label, name] of cases) {
            const toggle = screen.getByRole('switch', { name: label });
            expect(toggle.getAttribute('aria-checked')).toBe('true');
            fireEvent.click(toggle);
            expect(toggle.getAttribute('aria-checked')).toBe('false');
            expect(part(name)).toBeNull();
        }
        // The bars stay.
        expect(part('bars')).toBeTruthy();
    });

    it('saves the whole labels document with the generated-schema body', async () => {
        const calls = labelsApi(owner);
        renderApp('/settings/invoice/labels');

        fireEvent.click(within(await screen.findByRole('radiogroup', { name: 'Label paper' })).getByRole('radio', { name: /A4 sheet · 65 labels/ }));
        fireEvent.click(screen.getByRole('switch', { name: 'Store name' }));
        fireEvent.click(screen.getByRole('switch', { name: 'Create missing barcodes when printing' }));
        expect(screen.getByRole('region', { name: 'Save changes to Barcode labels' }).textContent).toContain('Label paper, what the label shows, and barcodes changed');
        fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));

        await waitFor(() => expect(screen.getByText('Label settings saved. The next labels you print use them.')).toBeTruthy());
        const body = sent(calls, '/settings/print', 'PUT')[0]!;
        expect(updatePrintSettingsRequestSchema.safeParse(body).success).toBe(true);
        expect(body).toEqual({ labels: { template: 'a4_65', show_name: true, show_options: true, show_price: true, show_store: false, show_digits: true, generate_missing: false } });
    });

    it('prints a job with the options as they stand: the exact POST body, copies counted', async () => {
        const calls = labelsApi(owner);
        renderApp('/settings/invoice/labels');
        await screen.findByRole('radiogroup', { name: 'Label paper' });

        const job = screen.getByRole('region', { name: 'Print labels' });
        expect(within(job).getByText('Add products to print their labels. Each copy is one label.')).toBeTruthy();
        fireEvent.click(within(job).getByRole('button', { name: 'Add products' }));
        fireEvent.change(within(job).getByRole('searchbox', { name: 'Search products to print' }), { target: { value: 'tricycle' } });
        fireEvent.click(await within(job).findByRole('button', { name: /Kids Tricycle/ }));

        const red = await within(job).findByRole('spinbutton', { name: 'Copies of Kids Tricycle · Red' });
        const blue = within(job).getByRole('spinbutton', { name: 'Copies of Kids Tricycle · Blue' });
        expect(within(job).queryByRole('spinbutton', { name: /Green/ })).toBeNull();
        fireEvent.change(red, { target: { value: '10' } });
        fireEvent.change(blue, { target: { value: '14' } });
        expect(within(job).getByText('24 labels · roll 50 × 25 mm')).toBeTruthy();

        // An unsaved toggle goes with the job.
        fireEvent.click(screen.getByRole('switch', { name: 'Price' }));
        fireEvent.click(within(job).getByRole('button', { name: 'Print 24 labels' }));

        await waitFor(() => expect(sent(calls, '/products/labels', 'POST')).toHaveLength(1));
        const body = sent(calls, '/products/labels', 'POST')[0]!;
        expect(printLabelsRequestSchema.safeParse(body).success).toBe(true);
        expect(body).toEqual({
            template: 'roll_50x25',
            items: [
                { variant_id: 100, copies: 10 },
                { variant_id: 101, copies: 14 },
            ],
            show_name: true,
            show_options: true,
            show_price: false,
            show_store: true,
            show_digits: true,
            locale: 'en',
            generate_missing: true,
        });
        expect(URL.createObjectURL).toHaveBeenCalled();
        // Printing is not saving.
        expect(sent(calls, '/settings/print', 'PUT')).toEqual([]);
    });

    it('leaves out variants without a barcode when creating them is off, and removes rows', async () => {
        const calls = labelsApi(owner, { ...defaults, generate_missing: false });
        renderApp('/settings/invoice/labels');
        await screen.findByRole('radiogroup', { name: 'Label paper' });

        const job = screen.getByRole('region', { name: 'Print labels' });
        fireEvent.click(within(job).getByRole('button', { name: 'Add products' }));
        fireEvent.change(within(job).getByRole('searchbox', { name: 'Search products to print' }), { target: { value: 'tricycle' } });
        fireEvent.click(await within(job).findByRole('button', { name: /Kids Tricycle/ }));
        await within(job).findByRole('spinbutton', { name: 'Copies of Kids Tricycle · Red' });
        expect(within(job).getByText('1 labels · roll 50 × 25 mm')).toBeTruthy();
        fireEvent.click(within(job).getByRole('button', { name: 'Remove Kids Tricycle · Blue' }));
        fireEvent.click(within(job).getByRole('button', { name: 'Print 1 labels' }));

        await waitFor(() => expect(sent(calls, '/products/labels', 'POST')).toHaveLength(1));
        expect(sent(calls, '/products/labels', 'POST')[0]).toMatchObject({ items: [{ variant_id: 100, copies: 1 }], generate_missing: false });
    });

    it('is read-only without settings.update, and offers no print job without catalog.view', async () => {
        const calls = labelsApi(reader);
        renderApp('/settings/invoice/labels');

        const group = await screen.findByRole('radiogroup', { name: 'Label paper' });
        for (const radio of within(group).getAllByRole('radio')) expect((radio as HTMLInputElement).disabled).toBe(true);
        expect((screen.getByRole('switch', { name: 'Store name' }).closest('fieldset') as HTMLFieldSetElement).disabled).toBe(true);
        expect(screen.queryByRole('region', { name: 'Print labels' })).toBeNull();
        expect(screen.queryByRole('button', { name: 'Save changes' })).toBeNull();
        expect(preview()).toBeTruthy();
        expect(sent(calls, '/settings/print', 'PUT')).toEqual([]);
    });

    it('is refused without settings.view', async () => {
        labelsApi({ ...viewer, permissions: ['dashboard.view', 'catalog.view'] });
        renderApp('/settings/invoice/labels');

        expect(await screen.findByText('You do not have access to this page.')).toBeTruthy();
        expect(screen.queryByRole('radiogroup')).toBeNull();
    });

    it('reads in Bangla with Western digits', async () => {
        labelsApi(owner);
        renderApp('/settings/invoice/labels', { locale: 'bn' });

        const group = await screen.findByRole('radiogroup', { name: 'লেবেলের কাগজ' });
        expect(within(group).getByText('রোল 50 × 25 মিমি')).toBeTruthy();
        expect(within(group).getByText('প্রতিটি 70 × 37 মিমি · অফিস প্রিন্টার')).toBeTruthy();
        expect(part('name')?.textContent).toBe('বাচ্চাদের ট্রাইসাইকেল');
        expect(part('price')?.textContent).toBe('৳4,900');
        expect(part('digits')?.textContent).toBe('2 000000 001173');
        expect(screen.getByText('0টি লেবেল · রোল 50 × 25 মিমি')).toBeTruthy();
        expect(document.body.textContent ?? '').not.toMatch(/[০-৯]/);
    });
});
