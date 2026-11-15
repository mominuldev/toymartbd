import { cleanup, fireEvent, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { mockApi, renderApp, viewer } from '@/test/renderApp';
import type { Me } from '@/features/auth/api';
import type { PosCatalogItem, PosContext, PosCustomer, PosCustomerMatch, PosDueCollection, PosDueQuote, PosHeldSale, PosQuote, PosReceipt, PosSale, PosSession } from './api';

afterEach(() => {
    cleanup();
    localStorage.clear();
    vi.unstubAllGlobals();
});

const cashier: Me = { ...viewer, id: 3, name: 'Sadia Haque', roles: ['order_operator'], permissions: ['dashboard.view', 'orders.create', 'pos.use'] };

const session: PosSession = {
    id: 1,
    status: 'open',
    location: { id: 2, code: 'ctg', name: 'Chattogram · Agrabad', name_bn: 'চট্টগ্রাম · আগ্রাবাদ' },
    register: 'Register 1',
    cashier: { id: 3, name: 'Sadia Haque' },
    opened_at: '2026-11-15T04:02:00+00:00',
    opening_float: '5000.00',
    closed_at: null,
    expected_cash: null,
    counted_cash: null,
    over_short: null,
    close_note: null,
    totals: { sales_count: 0, sales_total: '0.00', expected_cash: '5000.00', due_sold: '0.00', due_collected: '0.00', due_collections_count: 0, by_method: [] },
};

const context = (open: PosSession | null): PosContext => ({
    locations: [{ id: 2, code: 'ctg', name: 'Chattogram · Agrabad', name_bn: null, type: 'retail' }],
    tenders: [
        { method: 'cash', requires_reference: false, gives_change: true, requires_customer: false },
        { method: 'bkash', requires_reference: true, gives_change: false, requires_customer: false },
        { method: 'nagad', requires_reference: true, gives_change: false, requires_customer: false },
        { method: 'card', requires_reference: true, gives_change: false, requires_customer: false },
        { method: 'due', requires_reference: false, gives_change: false, requires_customer: true },
    ],
    categories: [{ id: 5, name: 'Building', name_bn: null }],
    session: open,
    can_manage: false,
    due_limit: '5000.00',
    whatsapp_receipt: { enabled: false, reason: 'no_provider' },
});

const items: PosCatalogItem[] = [
    { variant_id: 11, product_id: 1, name: 'Brick Box 500', name_bn: null, variant_name: null, sku: 'BB-500', barcode: '8901', price: '1850.00', available: 7, image_url: null },
    { variant_id: 12, product_id: 2, name: 'Foam Blaster X2', name_bn: null, variant_name: null, sku: 'FB-2', barcode: null, price: '1750.00', available: 0, image_url: null },
];

type SentTender = { method: string; amount: string };

/** The server's quote. The total is deliberately NOT the sum of the lines: the register must show it as given (rule 1). */
const quote = (tenders: SentTender[]): PosQuote => {
    const cash = tenders.find((d) => d.method === 'cash')?.amount ?? null;
    const due = tenders.find((d) => d.method === 'due')?.amount ?? null;
    const paid = cash !== null || due !== null;
    return {
        lines: [{ variant_id: 11, name: 'Brick Box 500', name_bn: null, variant_name: null, quantity: 1, unit_price: '1850.00', subtotal: '1850.00', discount: '0.00', total: '1850.00', available: 7, image_url: null }],
        rows: [{ type: 'subtotal', label: null, label_bn: null, amount: '1850.00' }],
        subtotal: '1850.00',
        discount_total: '0.00',
        grand_total: '3455.00',
        item_count: 1,
        blockers: [],
        chargeable: paid,
        tenders: { tendered: cash ?? '0.00', cash_tendered: cash ?? '0.00', remaining: paid ? '0.00' : '3455.00', change: cash ? '545.00' : '0.00', due: due ?? '0.00', errors: [] },
    };
};

const sale: PosSale = { id: 40, order_id: 900, order_number: 'TMB-26-00042', grand_total: '3455.00', tendered_total: '4000.00', cash_tendered: '4000.00', change_due: '545.00', due_amount: '0.00', created_at: '2026-11-15T05:00:00+00:00' };

const receipt: PosReceipt = {
    store: { name_en: 'Toy Mart BD', name_bn: 'টয় মার্ট বিডি', address_en: null, address_bn: null, hotline: null, bin: null, trade_licence: null, footer_en: null, footer_bn: null, placeholders: [] },
    branch: { name: 'Chattogram · Agrabad', name_bn: null, address: null },
    register: 'Register 1',
    cashier: 'Sadia Haque',
    order_number: 'TMB-26-00042',
    sold_at: '2026-11-15T05:00:00+00:00',
    customer: { name: null, phone_masked: null },
    lines: [{ name: 'Brick Box 500', name_bn: null, variant_name: null, quantity: 1, unit_price: '1850.00', discount: '0.00', total: '1850.00' }],
    subtotal: '1850.00',
    promotion_discount: '0.00',
    coupon_discount: '0.00',
    coupon_code: null,
    grand_total: '3455.00',
    payments: [{ method: 'cash', amount: '4000.00', reference: null }],
    cash_tendered: '4000.00',
    change_due: '545.00',
    due_amount: '0.00',
    whatsapp: { enabled: false, reason: 'no_provider' },
};

const rubina: PosCustomerMatch = { id: 7, name: 'Rubina Akter', phone: '01819552310', orders_count: 12, open_due: '1200.00', due_limit: '5000.00', due_available: '3800.00' };
const rubinaLookup: PosCustomer = { phone: '01819552310', customer: { id: 7, name: 'Rubina Akter', orders_count: 12, open_due: '1200.00', due_limit: '5000.00', due_available: '3800.00' } };

const held: PosHeldSale = { id: 9, label: 'Karim — school order', lines: [{ variant_id: 11, quantity: 2 }], item_count: 2, coupon_code: null, customer_phone: null, held_at: '2026-11-15T07:05:00+00:00', resumed_at: null };

const dueQuote = (tenders: SentTender[]): PosDueQuote => {
    const paid = tenders[0]?.amount ?? null;
    return {
        customer: { name: 'Rubina Akter', phone: '01819552310' },
        open_due: '1200.00',
        orders: [
            { order_id: 31, order_number: 'TMB-26-00031', due: '800.00', applied: paid ? '800.00' : '0.00' },
            { order_id: 38, order_number: 'TMB-26-00038', due: '400.00', applied: paid ? '400.00' : '0.00' },
        ],
        tenders: { tendered: paid ?? '0.00', cash_tendered: paid ?? '0.00', remaining: paid ? '0.00' : '1200.00', change: '0.00', errors: [] },
        chargeable: paid !== null,
    };
};

const collection: PosDueCollection = {
    id: 3,
    customer: { name: 'Rubina Akter', phone: '01819552310' },
    collected: '1200.00',
    tendered_total: '1200.00',
    cash_tendered: '1200.00',
    change_due: '0.00',
    remaining_due: '0.00',
    orders: [{ order_number: 'TMB-26-00031', applied: '800.00' }],
    created_at: '2026-11-15T08:00:00+00:00',
};

function posApi(me: Me, open: PosSession | null = session) {
    return mockApi((url, init) => {
        const method = init?.method ?? 'GET';
        const path = new URL(url, 'http://x').pathname.replace('/api/v1/admin', '');
        const sent = () => JSON.parse(String(init?.body)) as { tenders?: SentTender[] };
        if (path === '/auth/me') return { status: 200, body: me };
        if (path === '/pos/context') return { status: 200, body: context(open) };
        if (path === '/pos/sessions/1/catalog') return { status: 200, body: { data: items } };
        if (path === '/pos/sessions/1/holds' && method === 'GET') return { status: 200, body: { data: [held] } };
        if (path === '/pos/sessions/1/holds' && method === 'POST') return { status: 201, body: { ...held, id: 10 } };
        if (path === '/pos/holds/9' && method === 'DELETE') return { status: 204 };
        if (path === '/pos/sessions/1/quote') return { status: 200, body: quote(sent().tenders ?? []) };
        if (path === '/pos/sessions/1/sales' && method === 'POST') return { status: 201, body: sale };
        if (path === '/pos/sales/40/receipt') return { status: 200, body: receipt };
        if (path === '/pos/customers/search') return { status: 200, body: { data: [rubina] } };
        if (path === '/pos/customers') return { status: 200, body: rubinaLookup };
        if (path === '/pos/sessions/1/due-collections/quote') return { status: 200, body: dueQuote(sent().tenders ?? []) };
        if (path === '/pos/sessions/1/due-collections' && method === 'POST') return { status: 201, body: collection };
        if (path === '/pos/sessions' && method === 'POST') return { status: 201, body: session };
        return { status: 404 };
    });
}

function call(calls: ReturnType<typeof mockApi>, method: string, suffix: string): RequestInit | undefined {
    const found = calls.mock.calls.filter(([url, init]) => String(url).includes(suffix) && ((init as RequestInit | undefined)?.method ?? 'GET') === method).at(-1);
    return found?.[1] as RequestInit | undefined;
}

const cartPanel = () => screen.getByRole('complementary', { name: 'Current sale' });

/** Ring up the one tile and open Collect payment with Charge. */
async function toPayment() {
    fireEvent.click(await screen.findByRole('button', { name: /^Add Brick Box 500/ }));
    const charge = await screen.findByRole('button', { name: 'Charge ৳3,455' });
    await waitFor(() => expect(charge).toHaveProperty('disabled', false));
    fireEvent.click(charge);
    return screen.findByRole('dialog', { name: 'Collect payment' });
}

/** F9 → search "Rub" → pick Rubina. */
async function pickRubina() {
    fireEvent.keyDown(window, { key: 'F9' });
    const dialog = await screen.findByRole('dialog', { name: 'Customer' });
    fireEvent.change(within(dialog).getByLabelText('Search by name or phone'), { target: { value: 'Rub' } });
    fireEvent.click(await within(dialog).findByRole('button', { name: /^Rubina Akter/ }));
    await within(cartPanel()).findByText('Due ৳1,200 · limit ৳5,000');
}

describe('POS register', () => {
    it('shows the POS bar and the grid with stock at this branch', async () => {
        posApi(cashier);
        renderApp('/pos');

        expect(await screen.findByRole('heading', { name: 'Chattogram · Agrabad · Register 1' })).toBeTruthy();
        expect(screen.getByText('Drawer since 10:02 am · float ৳5,000')).toBeTruthy();
        expect(screen.getByRole('link', { name: 'Dashboard' })).toBeTruthy();
        const brick = await screen.findByRole('button', { name: 'Add Brick Box 500, BB-500, ৳1,850, 7 in stock' });
        expect(within(brick).getByText('7 in stock')).toBeTruthy();
        expect(within(brick).getByText('BB-500')).toBeTruthy();
        // None at this branch: shown, but cannot be added.
        expect((screen.getByRole('button', { name: 'Add Foam Blaster X2, FB-2, ৳1,750, Out of stock' }) as HTMLButtonElement).disabled).toBe(true);
        expect(screen.getByRole('button', { name: 'Building' })).toBeTruthy();
    });

    it('badges a tile with its quantity in the cart, and the delete button removes the line', async () => {
        posApi(cashier);
        renderApp('/pos');

        const brick = await screen.findByRole('button', { name: /^Add Brick Box 500/ });
        fireEvent.click(brick);
        fireEvent.click(brick);
        expect(within(brick).getByText('2 in cart')).toBeTruthy();
        expect(within(screen.getByRole('list', { name: 'Cart' })).getByText('2')).toBeTruthy();

        fireEvent.click(screen.getByRole('button', { name: 'Remove Brick Box 500' }));
        expect(await screen.findByText('Scan or tap a product to start a sale.')).toBeTruthy();
    });

    it("shows the server's totals and change, never its own sums", async () => {
        posApi(cashier);
        renderApp('/pos');

        fireEvent.click(await screen.findByRole('button', { name: /^Add Brick Box 500/ }));
        // The quote's total, though the one line is ৳1,850.
        const total = await within(cartPanel()).findByText('Total payable');
        await waitFor(() => expect(total.nextElementSibling?.textContent).toBe('৳3,455'));

        const dialog = await toPayment();
        expect(within(dialog).getByText('Remaining').nextElementSibling?.textContent).toBe('৳3,455');
        expect(within(dialog).getByRole('button', { name: 'Complete sale' })).toHaveProperty('disabled', true);

        fireEvent.change(within(dialog).getByLabelText('Cash received'), { target: { value: '4000' } });
        fireEvent.click(within(dialog).getByRole('button', { name: 'Add payment' }));
        await waitFor(() => expect(within(dialog).getByText('Change to give').nextElementSibling?.textContent).toBe('৳545'));
        await waitFor(() => expect(within(dialog).getByRole('button', { name: 'Complete sale' })).toHaveProperty('disabled', false));
    });

    it('opens Collect payment with F12', async () => {
        posApi(cashier);
        renderApp('/pos');

        fireEvent.click(await screen.findByRole('button', { name: /^Add Brick Box 500/ }));
        await within(cartPanel()).findByText('Total payable');
        fireEvent.keyDown(window, { key: 'F12' });
        expect(await screen.findByRole('dialog', { name: 'Collect payment' })).toBeTruthy();
    });

    it('charges with an Idempotency-Key, shows Sale complete, and prints the receipt in the language picked', async () => {
        const calls = posApi(cashier);
        renderApp('/pos');

        const dialog = await toPayment();
        fireEvent.click(within(dialog).getByRole('button', { name: '৳1,000' }));
        fireEvent.change(within(dialog).getByLabelText('Cash received'), { target: { value: '4000' } });
        fireEvent.click(within(dialog).getByRole('button', { name: 'Update payment' }));
        const complete = within(dialog).getByRole('button', { name: 'Complete sale' });
        await waitFor(() => expect(complete).toHaveProperty('disabled', false));
        fireEvent.click(complete);

        expect(await screen.findByRole('heading', { name: 'Sale complete' })).toBeTruthy();
        expect(screen.getByText(/^TMB-26-00042 · Walk-in customer · /)).toBeTruthy();
        const init = call(calls, 'POST', '/pos/sessions/1/sales')!;
        expect(JSON.parse(String(init.body))).toEqual({
            lines: [{ variant_id: 11, quantity: 1 }],
            coupon_code: null,
            customer_phone: null,
            customer_name: null,
            tenders: [{ method: 'cash', amount: '4000', reference: null }],
            expected_total: '3455.00',
        });
        expect((init.headers as Record<string, string>)['Idempotency-Key']).toMatch(/^[0-9a-f-]{36}$/);
        await waitFor(() => expect(screen.getByRole('button', { name: 'Print receipt' })).toHaveProperty('disabled', false));
        expect(screen.getByRole('button', { name: 'WhatsApp receipt' })).toHaveProperty('disabled', true);
        expect(screen.getByText('WhatsApp receipts need a WhatsApp provider — none is connected yet.')).toBeTruthy();

        // The receipt follows the UI until the cashier picks the customer's language.
        const paper = screen.getByTestId('pos-receipt');
        expect(within(paper).getByText('Total')).toBeTruthy();
        fireEvent.click(within(screen.getByRole('group', { name: 'Receipt language' })).getByRole('button', { name: 'বাংলা' }));
        expect(paper.getAttribute('lang')).toBe('bn');
        expect(within(paper).getByText('মোট')).toBeTruthy();
        expect(within(paper).getByText('৳3,455')).toBeTruthy();

        fireEvent.click(screen.getByRole('button', { name: 'Start new sale' }));
        expect(await screen.findByText('Scan or tap a product to start a sale.')).toBeTruthy();
    });

    it('asks bKash for a transaction ID, one method at a time', async () => {
        posApi(cashier);
        renderApp('/pos');

        const dialog = await toPayment();
        expect(within(dialog).getByRole('button', { name: 'Cash' }).getAttribute('aria-pressed')).toBe('true');
        fireEvent.click(within(dialog).getByRole('button', { name: 'bKash' }));
        expect(await within(dialog).findByLabelText('bKash transaction ID')).toBeTruthy();
        expect(within(dialog).getByLabelText('Amount for bKash')).toBeTruthy();
        expect(within(dialog).getByRole('button', { name: 'bKash' }).getAttribute('aria-pressed')).toBe('true');
        expect(within(dialog).getByRole('button', { name: 'Cash' }).getAttribute('aria-pressed')).toBe('false');
    });

    it('keeps Due shut until a named customer is on the sale, then sells on due', async () => {
        const calls = posApi(cashier);
        renderApp('/pos');

        let dialog = await toPayment();
        expect(within(dialog).getByRole('button', { name: 'Due' })).toHaveProperty('disabled', true);
        expect(within(dialog).getByText('Add a customer (F9) with a name to sell on due.')).toBeTruthy();
        fireEvent.click(within(dialog).getAllByRole('button', { name: 'Back to cart' }).at(-1)!);

        await pickRubina();
        fireEvent.click(within(cartPanel()).getByRole('button', { name: /^Charge ৳/ }));
        dialog = await screen.findByRole('dialog', { name: 'Collect payment' });
        fireEvent.click(within(dialog).getByRole('button', { name: 'Due' }));
        fireEvent.click(within(dialog).getByRole('button', { name: 'Fill remaining' }));
        fireEvent.click(within(dialog).getByRole('button', { name: 'Add payment' }));
        expect(await within(dialog).findByText('On credit · ৳3,800 available')).toBeTruthy();
        const complete = within(dialog).getByRole('button', { name: 'Complete sale' });
        await waitFor(() => expect(complete).toHaveProperty('disabled', false));
        fireEvent.click(complete);

        await screen.findByRole('heading', { name: 'Sale complete' });
        expect(JSON.parse(String(call(calls, 'POST', '/pos/sessions/1/sales')!.body))).toMatchObject({
            customer_phone: '01819552310',
            customer_name: 'Rubina Akter',
            tenders: [{ method: 'due', amount: '3455.00', reference: null }],
        });
    });

    it("collects a customer's due against their oldest orders", async () => {
        const calls = posApi(cashier);
        renderApp('/pos');

        await screen.findByRole('heading', { name: 'Chattogram · Agrabad · Register 1' });
        await pickRubina();
        fireEvent.click(within(cartPanel()).getByRole('button', { name: 'Collect due' }));
        const dialog = await screen.findByRole('dialog', { name: 'Collect due' });
        expect(await within(dialog).findByText('TMB-26-00031')).toBeTruthy();
        fireEvent.click(within(dialog).getByRole('button', { name: 'All due' }));
        await within(dialog).findByText('All due cleared');
        const collect = within(dialog).getByRole('button', { name: 'Collect ৳1,200' });
        await waitFor(() => expect(collect).toHaveProperty('disabled', false));
        fireEvent.click(collect);

        await waitFor(() => expect(call(calls, 'POST', '/pos/sessions/1/due-collections')).toBeTruthy());
        const init = call(calls, 'POST', '/pos/sessions/1/due-collections')!;
        expect(JSON.parse(String(init.body))).toEqual({ customer_phone: '01819552310', tenders: [{ method: 'cash', amount: '1200.00', reference: null }] });
        expect((init.headers as Record<string, string>)['Idempotency-Key']).toMatch(/^[0-9a-f-]{36}$/);
        expect(await screen.findByText('Collected ৳1,200 from Rubina Akter. Due left ৳0.')).toBeTruthy();
    });

    it('holds the current sale under a label, and discards a held one (F8)', async () => {
        const calls = posApi(cashier);
        renderApp('/pos');

        fireEvent.click(await screen.findByRole('button', { name: /^Add Brick Box 500/ }));
        fireEvent.keyDown(window, { key: 'F8' });
        const dialog = await screen.findByRole('dialog', { name: 'Held sales' });
        expect(await within(dialog).findByText('Karim — school order')).toBeTruthy();
        // A cart with items cannot be replaced by a resume.
        expect(within(dialog).getByRole('button', { name: 'Resume' })).toHaveProperty('disabled', true);

        fireEvent.change(within(dialog).getByLabelText('Label (optional)'), { target: { value: 'Rubina — fetching cash' } });
        fireEvent.click(within(dialog).getByRole('button', { name: 'Hold current sale' }));
        await waitFor(() => expect(call(calls, 'POST', '/pos/sessions/1/holds')).toBeTruthy());
        expect(JSON.parse(String(call(calls, 'POST', '/pos/sessions/1/holds')!.body))).toMatchObject({ label: 'Rubina — fetching cash', lines: [{ variant_id: 11, quantity: 1 }] });

        fireEvent.click(within(dialog).getByRole('button', { name: 'Discard Karim — school order' }));
        await waitFor(() => expect(call(calls, 'DELETE', '/pos/holds/9')).toBeTruthy());
    });

    it('adds a new customer by name and phone (F9)', async () => {
        posApi(cashier);
        renderApp('/pos');

        await screen.findByRole('heading', { name: 'Chattogram · Agrabad · Register 1' });
        fireEvent.click(within(cartPanel()).getByRole('button', { name: /^Add customer/ }));
        const dialog = await screen.findByRole('dialog', { name: 'Customer' });
        fireEvent.click(within(dialog).getByRole('button', { name: 'Add to sale' }));
        expect(within(dialog).getByRole('alert').textContent).toBe("Enter the customer's name.");
        fireEvent.change(within(dialog).getByLabelText('Name'), { target: { value: 'Rubina Akter' } });
        fireEvent.change(within(dialog).getByLabelText('Phone'), { target: { value: '01819552310' } });
        fireEvent.click(within(dialog).getByRole('button', { name: 'Add to sale' }));
        expect(await within(cartPanel()).findByText('Rubina Akter')).toBeTruthy();
    });

    it('asks to open a drawer when none is open, and opens one', async () => {
        const calls = posApi(cashier, null);
        renderApp('/pos');

        expect(await screen.findByRole('heading', { name: 'Open a drawer' })).toBeTruthy();
        fireEvent.change(screen.getByLabelText('Opening float'), { target: { value: '5000' } });
        fireEvent.click(screen.getByRole('button', { name: 'Open drawer' }));

        await waitFor(() => expect(call(calls, 'POST', '/pos/sessions')).toBeTruthy());
        expect(JSON.parse(String(call(calls, 'POST', '/pos/sessions')!.body))).toEqual({ location_id: 2, register: 'Register 1', opening_float: '5000' });
    });

    it('shows nothing of the register without pos.use', async () => {
        const calls = posApi(viewer);
        renderApp('/pos');

        expect(await screen.findByText('You do not have access to the register.')).toBeTruthy();
        expect(calls.mock.calls.some(([url]) => String(url).includes('/pos/sessions/'))).toBe(false);
    });

    it('renders in Bangla with Western digits', async () => {
        posApi(cashier);
        renderApp('/pos', { locale: 'bn' });

        expect(await screen.findByRole('heading', { name: 'চট্টগ্রাম · আগ্রাবাদ · Register 1' })).toBeTruthy();
        expect(await screen.findByText('স্টকে 7')).toBeTruthy();
    });
});
