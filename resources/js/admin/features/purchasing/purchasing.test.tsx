import { cleanup, fireEvent, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { mockApi, renderApp, viewer } from '@/test/renderApp';
import type { Me } from '@/features/auth/api';
import type { Purchase, PurchaseListItem, ReceiptQuote, Supplier, SupplierPayment } from './api';
import { formatMoney } from './parts';

afterEach(() => {
    cleanup();
    localStorage.clear();
});

const manager: Me = {
    ...viewer,
    roles: ['manager'],
    permissions: [
        'dashboard.view', 'catalog.view', 'catalog.view_cost', 'inventory.view', 'purchasing.view', 'purchasing.create', 'purchasing.receive',
        'purchasing.manage_suppliers', 'finance.view', 'finance.record_supplier_payments',
    ],
};
const readOnly: Me = { ...viewer, roles: ['viewer'], permissions: ['dashboard.view', 'purchasing.view', 'inventory.view'] };

/** Bangla and English names side by side (rule 34). */
const supplier: Supplier = {
    id: 3,
    name: 'Chittagong Toy Imports',
    contact_person: 'রহিম উদ্দিন',
    phone: '+8801712345678',
    alt_phone: null,
    email: null,
    address: 'আগ্রাবাদ, চট্টগ্রাম',
    district_id: null,
    tin: null,
    payment_terms_days: 30,
    status: 'active',
    note: null,
    balance: { opening: '1000.00', received: '25540.00', paid: '600.00', balance: '25940.00' },
    created_at: '2026-11-12T04:00:00Z',
};

const listItem: PurchaseListItem = {
    id: 44,
    number: 'PO-2026-0044',
    status: 'ordered',
    supplier: { id: 3, name: 'Chittagong Toy Imports', payment_terms_days: 30 },
    location_id: 1,
    order_date: '2026-11-10',
    expected_date: '2026-11-12',
    lines_count: 2,
    total: '48600.00',
    due_amount: '0.00',
    created_at: '2026-11-10T04:00:00Z',
};

const purchase: Purchase = {
    id: 44,
    number: 'PO-2026-0044',
    status: 'ordered',
    supplier: { id: 3, name: 'Chittagong Toy Imports', payment_terms_days: 30, status: 'active' },
    location_id: 1,
    order_date: '2026-11-10',
    expected_date: '2026-11-12',
    ordered_at: '2026-11-10T04:00:00Z',
    received_at: null,
    cancelled_at: null,
    supplier_invoice_no: null,
    note: null,
    subtotal: '25980.00',
    discount: '0.00',
    shipping_cost: '0.00',
    customs_duty: '0.00',
    other_cost: '0.00',
    tax: '0.00',
    total: '25980.00',
    received_value: '0.00',
    paid_amount: '0.00',
    due_amount: '0.00',
    units_ordered: 42,
    units_received: 0,
    can: { edit: false, place: false, cancel: true, receive: true, close: false },
    closed_at: null,
    closed_by: null,
    close_reason: null,
    items: [
        {
            id: 101, variant_id: 11, product_id: 1, product_name: 'Wooden Alphabet Puzzle', product_name_bn: 'কাঠের বর্ণমালা পাজল', variant_name: null, variant_name_bn: null,
            sku: 'TMB-WAP-BN', image_url: null, quantity_ordered: 30, quantity_received: 0, quantity_outstanding: 30, unit_cost: '298.0000', discount: '0.00', line_total: '8940.00', landed_unit_cost: null,
        },
        {
            id: 102, variant_id: 12, product_id: 2, product_name: 'Fashion Doll Deluxe Set', product_name_bn: 'ফ্যাশন ডল ডিলাক্স সেট', variant_name: null, variant_name_bn: null,
            sku: 'TMB-FDD-01', image_url: null, quantity_ordered: 12, quantity_received: 0, quantity_outstanding: 12, unit_cost: '1420.0000', discount: '0.00', line_total: '17040.00', landed_unit_cost: null,
        },
    ],
    receipts: [],
    created_at: '2026-11-10T04:00:00Z',
};

const quote: ReceiptQuote = {
    location_id: 1,
    apportion_method: 'value',
    units: 40,
    goods_value: '23140.00',
    freight: '2400.00',
    customs_duty: '0.00',
    other_cost: '0.00',
    landed_total: '2400.00',
    payable_amount: '25540.00',
    lines: [
        { purchase_item_id: 101, variant_id: 11, quantity: 30, quantity_outstanding_after: 0, unit_cost: '298.0000', line_value: '8940.00', allocated_cost: '927.23', landed_unit_cost: '328.9077', on_hand: 10, avg_cost_before: '300.0000', avg_cost_after: '321.6808' },
        { purchase_item_id: 102, variant_id: 12, quantity: 10, quantity_outstanding_after: 2, unit_cost: '1420.0000', line_value: '14200.00', allocated_cost: '1472.77', landed_unit_cost: '1567.2770', on_hand: 0, avg_cost_before: '1400.0000', avg_cost_after: '1567.2770' },
    ],
};

/** Partly received: 10 of 12 dolls arrived; the rest can be closed short. */
const partial: Purchase = {
    ...purchase,
    id: 45,
    number: 'PO-2026-0045',
    status: 'partially_received',
    can: { edit: false, place: false, cancel: false, receive: true, close: true },
    items: [{ ...purchase.items[1]!, quantity_received: 10, quantity_outstanding: 2 }],
};

const payment: SupplierPayment = { id: 9, supplier_id: 3, purchase_id: null, purchase_number: null, amount: '600.00', method: 'bkash', reference: 'TXN-8ABC12', paid_at: '2026-11-11', note: null, created_at: '2026-11-11T04:00:00Z' };

const page = <T,>(data: T[]) => ({ data, meta: { next_cursor: null } });
const summary = { location_id: 1, locations: [{ id: 1, code: 'main', name: 'Dhaka HQ · Mirpur', name_bn: 'ঢাকা HQ · মিরপুর', is_default: true }] };

function purchasingApi(me: Me) {
    const cost = me.permissions.includes('catalog.view_cost');
    return mockApi((url, init) => {
        if (url.endsWith('/auth/me')) return { status: 200, body: me };
        if (url.includes('/purchases/counts')) return { status: 200, body: { draft: 2, ordered: 3, partially_received: 1, received: 12, cancelled: 0, closed_short: 1 } };
        if (url.includes('/receipts/quote')) return { status: 200, body: quote };
        if (url.endsWith('/purchases/45/close') && init?.method === 'POST') return { status: 200, body: { ...partial, status: 'closed_short', can: { ...partial.can, close: false, receive: false } } };
        if (url.includes('/purchases/45')) return { status: 200, body: partial };
        if (url.endsWith('/purchases/44/receipts') && init?.method === 'POST') return { status: 201, body: { ...purchase, status: 'partially_received' } };
        if (url.includes('/purchases/44')) return { status: 200, body: purchase };
        if (url.includes('/purchases')) {
            const { total: _t, due_amount: _d, ...costless } = listItem;
            return { status: 200, body: page([cost ? listItem : costless]) };
        }
        if (url.includes('/suppliers/3/payments') && init?.method === 'POST') return { status: 201, body: { ...payment, amount: '1500.00' } };
        if (url.includes('/suppliers/3/payments')) return { status: 200, body: page([payment]) };
        if (url.includes('/suppliers/3')) return { status: 200, body: me.permissions.includes('finance.view') ? supplier : { ...supplier, balance: null } };
        if (url.includes('/inventory/summary')) return { status: 200, body: summary };
        if (url.includes('/inventory/low-stock-count')) return { status: 200, body: { location_id: 1, count: 0 } };
        return { status: 404 };
    });
}

const posts = (calls: ReturnType<typeof mockApi>, fragment: string) =>
    calls.mock.calls.filter(([url, init]) => String(url).includes(fragment) && (init as RequestInit | undefined)?.method === 'POST').map(([, init]) => init as RequestInit);

describe('purchase orders', () => {
    it('lists POs with server counts and totals, Open first', async () => {
        purchasingApi(manager);
        renderApp('/purchasing');

        const table = await screen.findByRole('table');
        expect(within(table).getByText('PO-2026-0044')).toBeTruthy();
        expect(within(table).getByText('Chittagong Toy Imports')).toBeTruthy();
        expect(within(table).getByText('Credit 30 days')).toBeTruthy();
        expect(within(table).getByText((_, el) => el?.tagName === 'TD' && el.textContent === 'Total৳48,600')).toBeTruthy();
        expect(await screen.findByRole('button', { name: /^Open\s*3$/ })).toBeTruthy();
        expect(screen.getByRole('link', { name: /New purchase order/ })).toBeTruthy();
    });

    it('opens on the first open PO with its receive card showing (Figma B · 16)', async () => {
        purchasingApi(manager);
        renderApp('/purchasing');

        expect(await screen.findByRole('region', { name: 'Receive PO-2026-0044' })).toBeTruthy();
        const table = screen.getByRole('table');
        expect(within(table).getByText('PO-2026-0044').closest('tr')?.getAttribute('aria-selected')).toBe('true');
    });

    it('hides PO money from a role without catalog.view_cost and offers no create', async () => {
        purchasingApi(readOnly);
        renderApp('/purchasing');

        const table = await screen.findByRole('table');
        expect(within(table).queryByRole('columnheader', { name: 'Total' })).toBeNull();
        expect(screen.queryByRole('link', { name: /New purchase order/ })).toBeNull();
    });

    it('shows the server’s landed costs in the receive card and receives with an Idempotency-Key', async () => {
        const calls = purchasingApi(manager);
        renderApp('/purchasing?po=44');

        const card = await screen.findByRole('region', { name: 'Receive PO-2026-0044' });
        const dolls = within(card).getByRole('textbox', { name: 'Units received for Fashion Doll Deluxe Set' });
        fireEvent.change(dolls, { target: { value: '10' } });
        fireEvent.change(within(card).getByRole('textbox', { name: /Inbound freight/ }), { target: { value: '2400' } });

        // Every figure is the quote's: nothing is computed in the browser (rule 1).
        await within(card).findByText('৳298 → landed ৳328.9077');
        expect(within(card).getByText((_, el) => el?.tagName === 'DIV' && el.textContent === 'Total payable৳25,540')).toBeTruthy();
        expect(within(card).getByText('2 units short — receive 40 now, keep 2 open on the PO')).toBeTruthy();
        const lastQuote = () => JSON.parse(String(posts(calls, '/receipts/quote').at(-1)?.body)) as { lines: { purchase_item_id: number; quantity: number }[]; freight: string };
        await waitFor(() => expect(lastQuote().freight).toBe('2400'));
        expect(lastQuote().lines).toEqual([{ purchase_item_id: 101, quantity: 30 }, { purchase_item_id: 102, quantity: 10 }]);

        fireEvent.click(within(card).getByRole('button', { name: 'Receive 40 units' }));
        const receipts = () => calls.mock.calls.filter(([url, init]) => String(url).endsWith('/purchases/44/receipts') && (init as RequestInit | undefined)?.method === 'POST');
        await waitFor(() => expect(receipts()).toHaveLength(1));
        const receipt = receipts()[0]![1] as RequestInit;
        expect((receipt.headers as Record<string, string>)['Idempotency-Key']).toBeTruthy();
        expect(JSON.parse(String(receipt.body))).toMatchObject({ freight: '2400', apportion_method: 'value', costs_paid_to_supplier: true });
    });
});

describe('closing short and third-party costs', () => {
    it('closes a partly received PO short with a required reason', async () => {
        const calls = purchasingApi(manager);
        renderApp('/purchasing/45');

        fireEvent.click(await screen.findByRole('button', { name: 'Close short' }));
        const dialog = await screen.findByRole('dialog', { name: 'Close PO-2026-0045 short?' });
        expect(within(dialog).getByText(/2 units were never received/)).toBeTruthy();
        fireEvent.click(within(dialog).getByRole('button', { name: 'Close purchase order' }));
        expect(posts(calls, '/purchases/45/close')).toHaveLength(0);

        fireEvent.change(within(dialog).getByRole('textbox', { name: /Reason/ }), { target: { value: 'Supplier is out of stock' } });
        fireEvent.click(within(dialog).getByRole('button', { name: 'Close purchase order' }));
        await waitFor(() => expect(posts(calls, '/purchases/45/close')).toHaveLength(1));
        expect(JSON.parse(String(posts(calls, '/purchases/45/close')[0]!.body))).toEqual({ reason: 'Supplier is out of stock' });
    });

    it('sends the third party who charged the landed costs when the supplier did not', async () => {
        const calls = purchasingApi(manager);
        renderApp('/purchasing?po=44');

        const card = await screen.findByRole('region', { name: 'Receive PO-2026-0044' });
        fireEvent.click(within(card).getByRole('checkbox', { name: 'The supplier charged these costs on its invoice' }));
        const group = within(card).getByRole('group', { name: 'Who charged these costs' });
        fireEvent.change(within(group).getByRole('textbox', { name: /Paid to/ }), { target: { value: 'Sea Hawk Forwarders' } });
        fireEvent.change(within(group).getByRole('combobox', { name: /Paid via/ }), { target: { value: 'bkash' } });
        fireEvent.change(within(card).getByRole('textbox', { name: /Inbound freight/ }), { target: { value: '500' } });

        await waitFor(() => {
            const last = JSON.parse(String(posts(calls, '/receipts/quote').at(-1)?.body)) as Record<string, unknown>;
            expect(last).toMatchObject({ costs_paid_to_supplier: false, costs_vendor: 'Sea Hawk Forwarders', costs_payment_method: 'bkash', freight: '500' });
        });
    });

    it('offers a Closed short tab with its server count', async () => {
        purchasingApi(manager);
        renderApp('/purchasing');
        expect(await screen.findByRole('button', { name: /^Closed short\s*1$/ })).toBeTruthy();
    });
});

describe('suppliers', () => {
    it('shows the payable balance, purchase orders and payments for finance staff', async () => {
        purchasingApi(manager);
        renderApp('/purchasing/suppliers/3');

        const payables = await screen.findByRole('region', { name: 'Payables' });
        expect(within(payables).getByText((_, el) => el?.tagName === 'P' && el.textContent === '৳25,940')).toBeTruthy();
        expect(await screen.findByRole('link', { name: 'PO-2026-0044' })).toBeTruthy();
        expect(await screen.findByText('bKash · TXN-8ABC12')).toBeTruthy();
    });

    it('hides payables from a role without finance.view', async () => {
        purchasingApi(readOnly);
        renderApp('/purchasing/suppliers/3');

        expect(await screen.findByRole('heading', { name: 'Chittagong Toy Imports' })).toBeTruthy();
        expect(screen.queryByRole('region', { name: 'Payables' })).toBeNull();
        expect(screen.queryByRole('button', { name: 'Record supplier payment' })).toBeNull();
    });

    it('records a payment through the generated schema with an Idempotency-Key', async () => {
        const calls = purchasingApi(manager);
        renderApp('/purchasing/suppliers/3');

        fireEvent.click(await screen.findByRole('button', { name: 'Record supplier payment' }));
        const dialog = await screen.findByRole('dialog', { name: 'Record supplier payment' });
        fireEvent.click(within(dialog).getByRole('button', { name: 'Record payment' }));
        expect(await within(dialog).findAllByText(/./, { selector: '[id$="-error"]' })).not.toHaveLength(0);
        expect(posts(calls, '/suppliers/3/payments')).toHaveLength(0);

        fireEvent.change(within(dialog).getByRole('textbox', { name: /Amount/ }), { target: { value: '1500' } });
        fireEvent.click(within(dialog).getByRole('button', { name: 'Record payment' }));
        await waitFor(() => expect(posts(calls, '/suppliers/3/payments')).toHaveLength(1));
        const sent = posts(calls, '/suppliers/3/payments')[0]!;
        expect(JSON.parse(String(sent.body))).toMatchObject({ amount: '1500', method: 'bank_transfer' });
        expect((sent.headers as Record<string, string>)['Idempotency-Key']).toBeTruthy();
    });
});

describe('formatMoney', () => {
    it('groups and trims the server decimal with a true minus sign', () => {
        expect(formatMoney('48600.00')).toBe('48,600');
        expect(formatMoney('328.9077')).toBe('328.9077');
        expect(formatMoney('-100.50')).toBe('−100.5');
    });
});
