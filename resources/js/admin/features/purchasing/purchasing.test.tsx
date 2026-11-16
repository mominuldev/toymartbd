import { cleanup, fireEvent, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { mockApi, renderApp, viewer } from '@/test/renderApp';
import type { Me } from '@/features/auth/api';
import type { Purchase, PurchaseListItem, PurchaseQuote, PurchaseSummary, ReceiptQuote, Supplier, SupplierPayment } from './api';
import { formatMoney, formatTaka } from './parts';

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
    name: 'Bangshal Kids Imports',
    contact_person: 'শহিদুল ইসলাম',
    phone: '+8801819503746',
    alt_phone: null,
    email: null,
    address: 'বংশাল, ঢাকা',
    district_id: null,
    tin: null,
    payment_terms_days: 15,
    status: 'active',
    note: null,
    balance: { opening: '0.00', received: '38900.00', paid: '0.00', balance: '38900.00' },
    created_at: '2026-11-12T04:00:00Z',
};

const row = (over: Partial<PurchaseListItem>): PurchaseListItem => ({
    id: 8,
    number: 'PO-2026-0008',
    status: 'partially_received',
    supplier: { id: 3, name: 'Bangshal Kids Imports', payment_terms_days: 15 },
    location_id: 1,
    order_date: '2026-11-03',
    expected_date: '2026-11-19',
    lines_count: 3,
    units_ordered: 40,
    units_received: 18,
    is_late: false,
    is_paid: false,
    total: '52000.00',
    due_amount: '23400.00',
    created_at: '2026-11-03T04:00:00Z',
    ...over,
});
const rows: PurchaseListItem[] = [
    row({}),
    row({ id: 9, number: 'PO-2026-0009', status: 'ordered', units_ordered: 30, units_received: 0, is_late: true, expected_date: '2026-11-14', total: '31500.00', due_amount: '0.00' }),
    row({ id: 6, number: 'PO-2026-0006', status: 'received', units_ordered: 120, units_received: 120, is_paid: true, total: '60450.00', due_amount: '0.00' }),
];

const summaryKpi: PurchaseSummary = {
    today: '2026-11-16',
    week_ends: '2026-11-20',
    open_count: 4,
    open_value: '124300',
    arriving_count: 2,
    late_count: 1,
    owed: '109450.00',
    owed_suppliers: 3,
};

const line = (id: number, name: string, sku: string, ordered: number, received: number, unit: string, total: string) => ({
    id,
    variant_id: id + 10,
    product_id: id,
    product_name: name,
    product_name_bn: null,
    variant_name: null,
    variant_name_bn: null,
    sku,
    image_url: null,
    quantity_ordered: ordered,
    quantity_received: received,
    quantity_outstanding: ordered - received,
    unit_cost: unit,
    discount: '0.00',
    line_total: total,
    landed_unit_cost: received > 0 ? '737.7777' : null,
});

const partial: Purchase = {
    id: 8,
    number: 'PO-2026-0008',
    status: 'partially_received',
    supplier: { id: 3, name: 'Bangshal Kids Imports', payment_terms_days: 15, status: 'active' },
    location_id: 1,
    order_date: '2026-11-03',
    expected_date: '2026-11-19',
    ordered_at: '2026-11-03T04:00:00Z',
    received_at: null,
    cancelled_at: null,
    closed_at: null,
    closed_by: null,
    close_reason: null,
    supplier_invoice_no: 'BKI-4471',
    note: null,
    subtotal: '52000.00',
    discount: '0.00',
    shipping_cost: '0.00',
    customs_duty: '0.00',
    other_cost: '0.00',
    tax: '0.00',
    total: '52000.00',
    received_value: '23400.00',
    received_goods: '23000.00',
    payable_total: '23400.00',
    extra_costs: { freight: '400.00', customs_duty: '0.00', other: '0.00', total: '400.00', charged_by_supplier: '400.00', paid_elsewhere: '0.00' },
    paid_amount: '0.00',
    due_amount: '23400.00',
    is_late: false,
    is_paid: false,
    payment_due_date: '2026-11-27',
    units_ordered: 40,
    units_received: 18,
    can: { edit: false, place: false, cancel: false, receive: true, close: true },
    items: [
        line(101, 'Wooden Alphabet Puzzle BN+EN', 'TMB-WAP-BN', 20, 12, '720.0000', '14400.00'),
        line(102, 'Kids Tricycle with Push Handle', 'TMB-TRC-PH', 8, 6, '2900.0000', '23200.00'),
        line(103, 'Brick Box 500 · Classic', 'TMB-BRK-500-CL', 12, 0, '1200.0000', '14400.00'),
    ],
    receipts: [
        {
            id: 12,
            number: 'GRN-2026-0012',
            purchase_id: 8,
            location_id: 1,
            received_at: '2026-11-12T05:00:00Z',
            apportion_method: 'value',
            costs_paid_to_supplier: true,
            costs_vendor: null,
            costs_reference: null,
            freight: '400.00',
            customs_duty: '0.00',
            other_cost: '0.00',
            goods_value: '23000.00',
            payable_amount: '23400.00',
            note: null,
            items: [
                { purchase_item_id: 101, variant_id: 111, quantity: 12, line_value: '8640.00', allocated_cost: '213.33', landed_unit_cost: '737.7777', avg_cost_before: '875.0000', avg_cost_after: '760.5000' },
                { purchase_item_id: 102, variant_id: 112, quantity: 6, line_value: '17400.00', allocated_cost: '186.67', landed_unit_cost: '2931.1117', avg_cost_before: '3250.0000', avg_cost_after: '3100.0000' },
            ],
        },
    ],
    created_at: '2026-11-03T04:00:00Z',
};

const draft: Purchase = {
    ...partial,
    id: 10,
    number: 'PO-2026-0010',
    status: 'draft',
    ordered_at: null,
    received_value: '0.00',
    due_amount: '0.00',
    payment_due_date: null,
    units_received: 0,
    can: { edit: true, place: true, cancel: true, receive: false, close: false },
    items: partial.items.map((l) => ({ ...l, quantity_received: 0, quantity_outstanding: l.quantity_ordered, landed_unit_cost: null })),
    receipts: [],
};

const received: Purchase = {
    ...partial,
    id: 7,
    number: 'PO-2026-0007',
    status: 'received',
    units_received: 40,
    can: { edit: false, place: false, cancel: false, receive: false, close: false },
    items: partial.items.map((l) => ({ ...l, quantity_received: l.quantity_ordered, quantity_outstanding: 0 })),
    // A C&F agent's customs, paid by the shop: listed, never owed to the supplier.
    extra_costs: { freight: '400.00', customs_duty: '150.00', other: '0.00', total: '550.00', charged_by_supplier: '400.00', paid_elsewhere: '150.00' },
};

const receiptQuote: ReceiptQuote = {
    location_id: 1,
    apportion_method: 'value',
    units: 22,
    goods_value: '31000.00',
    freight: '400.00',
    customs_duty: '0.00',
    other_cost: '0.00',
    landed_total: '400.00',
    payable_amount: '31400.00',
    lines: [
        { purchase_item_id: 101, variant_id: 111, quantity: 8, quantity_outstanding_after: 0, unit_cost: '720.0000', line_value: '5760.00', allocated_cost: '74.32', landed_unit_cost: '729.2903', on_hand: 12, avg_cost_before: '875.0000', avg_cost_after: '832.1429' },
        { purchase_item_id: 102, variant_id: 112, quantity: 2, quantity_outstanding_after: 0, unit_cost: '2900.0000', line_value: '5800.00', allocated_cost: '74.84', landed_unit_cost: '2937.4194', on_hand: 6, avg_cost_before: '3250.0000', avg_cost_after: '3121.4048' },
        { purchase_item_id: 103, variant_id: 113, quantity: 12, quantity_outstanding_after: 0, unit_cost: '1200.0000', line_value: '14400.00', allocated_cost: '250.84', landed_unit_cost: '1220.9032', on_hand: 4, avg_cost_before: '1120.0000', avg_cost_after: '1168.9516' },
    ],
};

const poQuote: PurchaseQuote = {
    products: 1,
    units: 20,
    subtotal: '14400.00',
    discount: '0.00',
    total: '14400.00',
    supplier_known: true,
    lines: [{ variant_id: 111, quantity: 20, line_total: '14400.00', last_unit_cost: '720.0000' }],
};

const payment: SupplierPayment = { id: 9, supplier_id: 3, purchase_id: null, purchase_number: null, amount: '600.00', method: 'bkash', reference: 'TXN-8ABC12', paid_at: '2026-11-11', note: null, created_at: '2026-11-11T04:00:00Z' };

const page = <T,>(data: T[]) => ({ data, meta: { next_cursor: null } });
const stockRow = {
    variant_id: 111,
    product_id: 101,
    product_name: 'Wooden Alphabet Puzzle BN+EN',
    product_name_bn: 'কাঠের বর্ণমালা পাজল',
    variant_name: null,
    variant_name_bn: null,
    sku: 'TMB-WAP-BN',
    image_url: null,
    reorder_quantity: 20,
    unit_cost: '700.0000',
};

function purchasingApi(me: Me) {
    const cost = me.permissions.includes('catalog.view_cost');
    const finance = me.permissions.includes('finance.view');
    return mockApi((url, init) => {
        const post = init?.method === 'POST';
        if (url.endsWith('/auth/me')) return { status: 200, body: me };
        if (url.includes('/purchases/counts')) return { status: 200, body: { all: 9, draft: 1, ordered: 1, partially_received: 1, received: 5, cancelled: 1, closed_short: 1, closed: 2 } };
        if (url.includes('/purchases/summary')) return { status: 200, body: { ...summaryKpi, open_value: cost ? summaryKpi.open_value : null, owed: finance ? summaryKpi.owed : null, owed_suppliers: finance ? 3 : null } };
        if (url.endsWith('/purchases/quote') && post) return { status: 200, body: poQuote };
        if (url.includes('/receipts/quote')) return { status: 200, body: receiptQuote };
        if (url.endsWith('/purchases/8/receipts') && post) return { status: 201, body: { ...received, id: 8 } };
        if (url.endsWith('/purchases/8/close') && post) return { status: 200, body: { ...partial, status: 'closed_short' } };
        if (url.endsWith('/purchases/10/order') && post) return { status: 200, body: { ...draft, status: 'ordered' } };
        if (url.endsWith('/purchases/77/order') && post) return { status: 200, body: { ...draft, id: 77, number: 'PO-2026-0077', status: 'ordered' } };
        if (url.endsWith('/purchases') && post) return { status: 201, body: { ...draft, id: 77, number: 'PO-2026-0077' } };
        if (url.includes('/purchases/8')) return { status: 200, body: partial };
        if (url.includes('/purchases/10')) return { status: 200, body: draft };
        if (url.includes('/purchases/77')) return { status: 200, body: { ...draft, id: 77, number: 'PO-2026-0077' } };
        if (url.includes('/purchases/7')) return { status: 200, body: received };
        if (url.includes('/purchases')) {
            const visible = rows.map(({ total: _t, due_amount: _d, is_paid: _p, ...rest }) => (cost ? { ...rest, total: _t, due_amount: _d, is_paid: _p } : rest));
            return { status: 200, body: page(visible) };
        }
        if (url.includes('/suppliers/3/payments') && post) return { status: 201, body: { ...payment, amount: '1500.00' } };
        if (url.includes('/suppliers/3/payments')) return { status: 200, body: page([payment]) };
        if (url.includes('/suppliers/3')) return { status: 200, body: finance ? supplier : { ...supplier, balance: null } };
        if (url.includes('/suppliers')) return { status: 200, body: page([finance ? supplier : { ...supplier, balance: null }]) };
        if (url.includes('/inventory/stock')) return { status: 200, body: page([stockRow]) };
        if (url.includes('/inventory/low-stock-count')) return { status: 200, body: { location_id: 1, count: 0 } };
        return { status: 404 };
    });
}

const posts = (calls: ReturnType<typeof mockApi>, fragment: string) =>
    calls.mock.calls.filter(([url, init]) => String(url).includes(fragment) && (init as RequestInit | undefined)?.method === 'POST').map(([url, init]) => ({ url: String(url), init: init as RequestInit }));
const gets = (calls: ReturnType<typeof mockApi>, fragment: string) =>
    calls.mock.calls.filter(([url, init]) => String(url).includes(fragment) && (init as RequestInit | undefined)?.method !== 'POST').map(([url]) => decodeURIComponent(String(url)));

describe('purchase orders list', () => {
    it('shows the server KPIs, tab counts and one row per PO with progress, Late and Paid', async () => {
        purchasingApi(manager);
        renderApp('/purchasing');

        const kpis = await screen.findByRole('region', { name: 'Purchasing at a glance' });
        expect(within(kpis).getAllByText((_, el) => el?.tagName === 'SPAN' && el.textContent === '৳1,24,300.00').length).toBeGreaterThan(0);
        expect(within(kpis).getByText('4 orders waiting for stock')).toBeTruthy();
        expect(within(kpis).getByText('Expected by Fri 20 Nov')).toBeTruthy();
        expect(within(kpis).getAllByText((_, el) => el?.tagName === 'SPAN' && el.textContent === '৳1,09,450.00').length).toBeGreaterThan(0);
        expect(within(kpis).getByText('3 suppliers · for stock already received')).toBeTruthy();

        expect(await screen.findByRole('button', { name: /^All\s*9$/ })).toBeTruthy();
        expect(screen.getByRole('button', { name: /^Waiting for stock\s*1$/ })).toBeTruthy();
        expect(screen.getByRole('button', { name: /^Closed\s*2$/ })).toBeTruthy();

        const table = await screen.findByRole('table');
        const partly = within(table).getByRole('link', { name: 'PO-2026-0008' }).closest('tr')!;
        expect(within(partly).getByText('18 of 40 units')).toBeTruthy();
        expect(within(partly).getByRole('progressbar').getAttribute('aria-valuenow')).toBe('18');
        expect(within(partly).getByText((_, el) => el?.tagName === 'TD' && el.textContent === 'Still to pay৳23,400.00')).toBeTruthy();
        const late = within(table).getByRole('link', { name: 'PO-2026-0009' }).closest('tr')!;
        expect(within(late).getByText('Late')).toBeTruthy();
        expect(within(late).getByText('Waiting for stock')).toBeTruthy();
        const paid = within(table).getByRole('link', { name: 'PO-2026-0006' }).closest('tr')!;
        expect(within(paid).getByText('Paid')).toBeTruthy();
    });

    it('sends every filter to the server: tabs, the Late and Arriving shortcuts, supplier and search', async () => {
        const calls = purchasingApi(manager);
        renderApp('/purchasing');

        fireEvent.click(await screen.findByRole('button', { name: /^Closed\s*2$/ }));
        await waitFor(() => expect(gets(calls, '/purchases?').some((u) => u.includes('filter[status]=closed'))).toBe(true));

        fireEvent.click(await screen.findByRole('button', { name: /^Late/ }));
        await waitFor(() => expect(gets(calls, '/purchases?').some((u) => u.includes('filter[expected]=late') && !u.includes('filter[status]'))).toBe(true));
        expect(screen.getByText('Showing late orders only')).toBeTruthy();

        fireEvent.change(screen.getByRole('combobox', { name: 'Filter by supplier' }), { target: { value: '3' } });
        await waitFor(() => expect(gets(calls, '/purchases?').some((u) => u.includes('filter[supplier_id]=3'))).toBe(true));

        fireEvent.change(screen.getByRole('searchbox', { name: 'Search purchase orders' }), { target: { value: 'BKI-4471' } });
        await waitFor(() => expect(gets(calls, '/purchases?').some((u) => u.includes('filter[search]=BKI-4471'))).toBe(true));
    });

    it('hides PO money and what is owed from a role without the rights, and offers no create', async () => {
        purchasingApi(readOnly);
        renderApp('/purchasing');

        const table = await screen.findByRole('table');
        expect(within(table).queryByRole('columnheader', { name: 'Total' })).toBeNull();
        expect(within(table).queryByRole('columnheader', { name: 'Still to pay' })).toBeNull();
        const kpis = await screen.findByRole('region', { name: 'Purchasing at a glance' });
        expect(within(kpis).queryByText('You owe suppliers')).toBeNull();
        expect(within(kpis).getByText('4')).toBeTruthy();
        expect(screen.queryByRole('link', { name: /New purchase order/ })).toBeNull();
    });

    it('receives from the row ⋯ menu in the drawer, not on the list', async () => {
        purchasingApi(manager);
        renderApp('/purchasing');

        expect(screen.queryByRole('region', { name: /^Receive PO/ })).toBeNull();
        fireEvent.click(await screen.findByRole('button', { name: 'Actions for PO-2026-0008' }));
        fireEvent.click(await screen.findByRole('menuitem', { name: 'Receive stock' }));
        const drawer = await screen.findByRole('dialog', { name: 'Receive stock' });
        expect(within(drawer).getByText('PO-2026-0008 · Bangshal Kids Imports · 22 units still to come')).toBeTruthy();
    });
});

describe('purchase order page', () => {
    it('offers Place order as the one primary action on a draft', async () => {
        const calls = purchasingApi(manager);
        renderApp('/purchasing/10');

        fireEvent.click(await screen.findByRole('button', { name: 'Place order' }));
        await waitFor(() => expect(posts(calls, '/purchases/10/order')).toHaveLength(1));
        expect(screen.queryByRole('button', { name: 'Receive stock' })).toBeNull();
        expect(screen.getByRole('link', { name: 'Edit' })).toBeTruthy();
    });

    it('offers Receive stock while waiting, with the stepper, what you owe and the deliveries', async () => {
        purchasingApi(manager);
        renderApp('/purchasing/8');

        expect(await screen.findByRole('button', { name: 'Receive stock' })).toBeTruthy();
        expect(screen.queryByRole('button', { name: 'Place order' })).toBeNull();

        const progress = screen.getByRole('region', { name: 'Progress' });
        const current = within(progress).getAllByRole('listitem').find((li) => li.getAttribute('aria-current') === 'step')!;
        expect(current.textContent).toContain('Receiving');
        expect(current.textContent).toContain('18 of 40 units in');

        const owe = screen.getByRole('region', { name: 'What you owe' });
        // Order total = goods ordered; the receipt's freight on its own line; then goods received, paid, still to pay.
        const rows = within(owe).getAllByText((_, el) => el?.tagName === 'DIV' && el.className.includes('justify-between')).map((el) => el.textContent);
        expect(rows).toEqual(['Order total৳52,000.00', 'Freight৳400.00', 'Goods received so far৳23,000.00', 'Paid৳0.00', 'Still to pay৳23,400.00']);
        expect(within(owe).getByText('You only owe for stock that has arrived. Credit 15 days — due 27 Nov.')).toBeTruthy();
        expect(within(owe).getByRole('button', { name: 'Record payment' })).toBeTruthy();
        // One Record payment on the page: the rail's.
        expect(screen.getAllByRole('button', { name: 'Record payment' })).toHaveLength(1);

        const deliveries = screen.getByRole('region', { name: 'Deliveries received' });
        expect(within(deliveries).getByText(/18 units into Dhaka HQ/)).toBeTruthy();
        expect(within(deliveries).getByText('GRN-2026-0012 · goods ৳23,000.00 + freight ৳400.00 (spread by product value) = ৳23,400.00 added to what you owe')).toBeTruthy();
        fireEvent.click(within(deliveries).getByRole('button', { name: 'Details' }));
        expect(within(deliveries).getByText('Wooden Alphabet Puzzle BN+EN: 12 units at ৳737.78 each')).toBeTruthy();
    });

    it('offers Record payment as the primary once everything is in but something is owed', async () => {
        purchasingApi(manager);
        renderApp('/purchasing/7');

        const header = await screen.findByRole('heading', { level: 1, name: 'PO-2026-0007' });
        expect(header).toBeTruthy();
        expect(await screen.findAllByRole('button', { name: 'Record payment' })).toHaveLength(1);
        expect(screen.queryByRole('button', { name: 'Receive stock' })).toBeNull();
        const owe = screen.getByRole('region', { name: 'What you owe' });
        expect(within(owe).getByText((_, el) => el?.tagName === 'DIV' && el.textContent === 'Customs & duty৳150.00')).toBeTruthy();
        expect(within(owe).getByText('৳150.00 of these was paid to someone else — not owed to the supplier.')).toBeTruthy();
        fireEvent.click(screen.getByRole('button', { name: 'Record payment' }));
        expect(await screen.findByRole('dialog', { name: 'Record supplier payment' })).toBeTruthy();
    });

    it('closes a partly received PO short from the ⋯ menu with a required reason', async () => {
        const calls = purchasingApi(manager);
        renderApp('/purchasing/8');

        fireEvent.click(await screen.findByRole('button', { name: 'More actions for PO-2026-0008' }));
        fireEvent.click(await screen.findByRole('menuitem', { name: 'Close short' }));
        const dialog = await screen.findByRole('dialog', { name: 'Close PO-2026-0008 short?' });
        fireEvent.click(within(dialog).getByRole('button', { name: 'Close purchase order' }));
        expect(posts(calls, '/purchases/8/close')).toHaveLength(0);
        fireEvent.change(within(dialog).getByRole('textbox', { name: /Reason/ }), { target: { value: 'Supplier is out of stock' } });
        fireEvent.click(within(dialog).getByRole('button', { name: 'Close purchase order' }));
        await waitFor(() => expect(posts(calls, '/purchases/8/close')).toHaveLength(1));
    });
});

describe('receive stock drawer', () => {
    it('quotes on the server, shows plain totals at 2 decimals and receives with an Idempotency-Key', async () => {
        const calls = purchasingApi(manager);
        renderApp('/purchasing/8');

        fireEvent.click(await screen.findByRole('button', { name: 'Receive stock' }));
        const drawer = await screen.findByRole('dialog', { name: 'Receive stock' });
        const puzzle = within(drawer).getByRole('textbox', { name: /Units received for Wooden Alphabet Puzzle/ });
        expect((puzzle as HTMLInputElement).value).toBe('8');
        fireEvent.change(puzzle, { target: { value: '0' } });
        fireEvent.click(within(drawer).getByRole('button', { name: 'Everything arrived' }));
        expect((puzzle as HTMLInputElement).value).toBe('8');
        fireEvent.change(within(drawer).getByRole('textbox', { name: 'Freight (৳)' }), { target: { value: '400' } });

        // Every figure is the quote's: nothing is computed in the browser (rule 1).
        await within(drawer).findByText((_, el) => el?.tagName === 'DIV' && el.textContent === 'You will owe the supplier৳31,400.00');
        expect(within(drawer).getByText(/Wooden Alphabet Puzzle BN\+EN ৳875\.00 → ৳832\.14/)).toBeTruthy();
        expect(within(drawer).getByText('Extra costs — on the supplier’s bill')).toBeTruthy();
        const lastQuote = () => JSON.parse(String(posts(calls, '/receipts/quote').at(-1)?.init.body)) as Record<string, unknown>;
        await waitFor(() => expect(lastQuote()).toMatchObject({ freight: '400', apportion_method: 'value', costs_paid_to_supplier: true }));

        fireEvent.click(within(drawer).getByRole('button', { name: 'By quantity' }));
        await waitFor(() => expect(lastQuote()).toMatchObject({ apportion_method: 'quantity' }));

        fireEvent.click(within(drawer).getByRole('button', { name: 'Receive 22 units' }));
        await waitFor(() => expect(posts(calls, '/purchases/8/receipts').filter((p) => p.url.endsWith('/receipts'))).toHaveLength(1));
        const receipt = posts(calls, '/purchases/8/receipts').find((p) => p.url.endsWith('/receipts'))!.init;
        expect((receipt.headers as Record<string, string>)['Idempotency-Key']).toBeTruthy();
        expect(JSON.parse(String(receipt.body))).toMatchObject({
            lines: [
                { purchase_item_id: 101, quantity: 8 },
                { purchase_item_id: 102, quantity: 2 },
                { purchase_item_id: 103, quantity: 12 },
            ],
            freight: '400',
            apportion_method: 'quantity',
        });
    });

    it('asks who charged the extra costs when they are not on the supplier’s bill', async () => {
        const calls = purchasingApi(manager);
        renderApp('/purchasing/8');

        fireEvent.click(await screen.findByRole('button', { name: 'Receive stock' }));
        const drawer = await screen.findByRole('dialog', { name: 'Receive stock' });
        fireEvent.click(within(drawer).getByRole('checkbox', { name: /Add these costs to what I owe the supplier/ }));
        const group = within(drawer).getByRole('group', { name: 'Who charged these costs' });
        fireEvent.change(within(group).getByRole('textbox', { name: /Paid to/ }), { target: { value: 'Sea Hawk Forwarders' } });
        fireEvent.change(within(group).getByRole('combobox', { name: /Paid via/ }), { target: { value: 'bkash' } });
        fireEvent.change(within(drawer).getByRole('textbox', { name: 'Freight (৳)' }), { target: { value: '500' } });

        await waitFor(() => {
            const last = JSON.parse(String(posts(calls, '/receipts/quote').at(-1)?.init.body)) as Record<string, unknown>;
            expect(last).toMatchObject({ costs_paid_to_supplier: false, costs_vendor: 'Sea Hawk Forwarders', costs_payment_method: 'bkash', freight: '500' });
        });
        expect(await within(drawer).findByText('Extra costs — paid by you, not owed to the supplier')).toBeTruthy();
    });
});

describe('new purchase order', () => {
    async function fillNewPo() {
        const supplierSelect = await screen.findByRole('combobox', { name: /Supplier/ });
        await screen.findByRole('option', { name: 'Bangshal Kids Imports' });
        fireEvent.change(supplierSelect, { target: { value: '3' } });
        expect(await screen.findByText('Credit 15 days · you owe them ৳38,900.00')).toBeTruthy();
        fireEvent.click(await screen.findByRole('button', { name: /Wooden Alphabet Puzzle BN\+EN/ }));
        expect((screen.getByRole('textbox', { name: 'Quantity of Wooden Alphabet Puzzle BN+EN' }) as HTMLInputElement).value).toBe('20');
        fireEvent.change(screen.getByRole('textbox', { name: 'Unit cost of Wooden Alphabet Puzzle BN+EN' }), { target: { value: '720' } });
    }

    it('starts from Inventory’s ?sku=, prices lines on the server and saves a draft without placing it', async () => {
        const calls = purchasingApi(manager);
        renderApp('/purchasing/new?sku=TMB-WAP-BN');

        expect(((await screen.findByRole('searchbox', { name: /Add a product/ })) as HTMLInputElement).value).toBe('TMB-WAP-BN');
        await fillNewPo();

        expect(await screen.findByText(/last bought at ৳720\.00/)).toBeTruthy();
        await waitFor(() => expect(JSON.parse(String(posts(calls, '/purchases/quote').at(-1)?.init.body))).toEqual({ supplier_id: 3, lines: [{ variant_id: 111, quantity: 20, unit_cost: '720', discount: null }] }));
        const rail = screen.getByRole('complementary', { name: 'Summary' });
        await within(rail).findByText((_, el) => el?.tagName === 'DIV' && el.textContent === 'Order total৳14,400.00');

        fireEvent.click(within(rail).getByRole('button', { name: 'Save as draft' }));
        await waitFor(() => expect(posts(calls, '/purchases').filter((p) => p.url.endsWith('/purchases'))).toHaveLength(1));
        expect(posts(calls, '/order')).toHaveLength(0);
    });

    it('places the order: saves, then moves it to Waiting for stock', async () => {
        const calls = purchasingApi(manager);
        renderApp('/purchasing/new');

        fireEvent.change(await screen.findByRole('searchbox', { name: /Add a product/ }), { target: { value: 'puzzle' } });
        await fillNewPo();
        fireEvent.click(screen.getByRole('button', { name: 'Place order' }));
        await waitFor(() => expect(posts(calls, '/purchases/77/order')).toHaveLength(1));
        const created = posts(calls, '/purchases').find((p) => p.url.endsWith('/purchases'))!;
        expect(JSON.parse(String(created.init.body))).toMatchObject({ supplier_id: 3, lines: [{ variant_id: 111, quantity: 20, unit_cost: '720' }] });
    });

    it('refuses to save without a supplier or products, through the generated schema', async () => {
        const calls = purchasingApi(manager);
        renderApp('/purchasing/new');

        fireEvent.click(await screen.findByRole('button', { name: 'Place order' }));
        expect(await screen.findByText('Add at least one product.')).toBeTruthy();
        expect(posts(calls, '/purchases').filter((p) => p.url.endsWith('/purchases'))).toHaveLength(0);
    });
});

describe('suppliers', () => {
    it('shows the payable balance, purchase orders and payments for finance staff', async () => {
        purchasingApi(manager);
        renderApp('/purchasing/suppliers/3');

        const payables = await screen.findByRole('region', { name: 'Payables' });
        expect(within(payables).getAllByText((_, el) => el?.tagName === 'P' && el.textContent === '৳38,900')).toHaveLength(2);
        expect(await screen.findByText('bKash · TXN-8ABC12')).toBeTruthy();
    });

    it('hides payables from a role without finance.view', async () => {
        purchasingApi(readOnly);
        renderApp('/purchasing/suppliers/3');

        expect(await screen.findByRole('heading', { name: 'Bangshal Kids Imports' })).toBeTruthy();
        expect(screen.queryByRole('region', { name: 'Payables' })).toBeNull();
        expect(screen.queryByRole('button', { name: 'Record payment' })).toBeNull();
    });

    it('records a payment through the generated schema with an Idempotency-Key', async () => {
        const calls = purchasingApi(manager);
        renderApp('/purchasing/suppliers/3');

        fireEvent.click(await screen.findByRole('button', { name: 'Record payment' }));
        const dialog = await screen.findByRole('dialog', { name: 'Record supplier payment' });
        fireEvent.click(within(dialog).getByRole('button', { name: 'Record payment' }));
        expect(await within(dialog).findAllByText(/./, { selector: '[id$="-error"]' })).not.toHaveLength(0);
        expect(posts(calls, '/suppliers/3/payments')).toHaveLength(0);

        fireEvent.change(within(dialog).getByRole('textbox', { name: /Amount/ }), { target: { value: '1500' } });
        fireEvent.click(within(dialog).getByRole('button', { name: 'Record payment' }));
        await waitFor(() => expect(posts(calls, '/suppliers/3/payments')).toHaveLength(1));
        const sent = posts(calls, '/suppliers/3/payments')[0]!.init;
        expect(JSON.parse(String(sent.body))).toMatchObject({ amount: '1500', method: 'bank_transfer' });
        expect((sent.headers as Record<string, string>)['Idempotency-Key']).toBeTruthy();
    });
});

describe('money formatting', () => {
    it('formatMoney groups and trims the server decimal with a true minus sign', () => {
        expect(formatMoney('48600.00')).toBe('48,600');
        expect(formatMoney('-100.50')).toBe('−100.5');
    });

    it('formatTaka shows exactly 2 decimals, rounding the string half away from zero', () => {
        expect(formatTaka('23400')).toBe('23,400.00');
        expect(formatTaka('832.1429')).toBe('832.14');
        expect(formatTaka('737.7777')).toBe('737.78');
        expect(formatTaka('99.995')).toBe('100.00');
        expect(formatTaka('999999.9950')).toBe('10,00,000.00');
        expect(formatTaka('-0.004')).toBe('0.00');
        expect(formatTaka('-12.5')).toBe('−12.50');
        expect(formatTaka('0.00')).toBe('0.00');
    });
});
