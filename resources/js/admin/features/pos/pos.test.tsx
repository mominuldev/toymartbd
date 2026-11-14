import { cleanup, fireEvent, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { mockApi, renderApp, viewer } from '@/test/renderApp';
import type { Me } from '@/features/auth/api';
import type { PosCatalogItem, PosContext, PosQuote, PosSale, PosSession } from './api';

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
    totals: { sales_count: 0, sales_total: '0.00', expected_cash: '5000.00', by_method: [] },
};

const context = (open: PosSession | null): PosContext => ({
    locations: [{ id: 2, code: 'ctg', name: 'Chattogram · Agrabad', name_bn: null, type: 'retail' }],
    tenders: [
        { method: 'cash', requires_reference: false, gives_change: true },
        { method: 'bkash', requires_reference: true, gives_change: false },
        { method: 'nagad', requires_reference: true, gives_change: false },
        { method: 'card', requires_reference: true, gives_change: false },
    ],
    categories: [{ id: 5, name: 'Building', name_bn: null }],
    session: open,
    can_manage: false,
    whatsapp_receipt: { enabled: false, reason: 'no_provider' },
});

const items: PosCatalogItem[] = [
    { variant_id: 11, product_id: 1, name: 'Brick Box 500', name_bn: null, variant_name: null, sku: 'BB-500', barcode: '8901', price: '1850.00', available: 7, image_url: null },
    { variant_id: 12, product_id: 2, name: 'Foam Blaster X2', name_bn: null, variant_name: null, sku: 'FB-2', barcode: null, price: '1750.00', available: 0, image_url: null },
];

/** The server's quote. The total is deliberately NOT the sum of the lines: the register must show it as given (rule 1). */
const quote = (cash: string | null): PosQuote => ({
    lines: [{ variant_id: 11, name: 'Brick Box 500', name_bn: null, variant_name: null, quantity: 1, unit_price: '1850.00', subtotal: '1850.00', discount: '0.00', total: '1850.00', available: 7, image_url: null }],
    rows: [{ type: 'subtotal', label: null, label_bn: null, amount: '1850.00' }],
    subtotal: '1850.00',
    discount_total: '0.00',
    grand_total: '3455.00',
    item_count: 1,
    blockers: [],
    chargeable: cash !== null,
    tenders: { tendered: cash ?? '0.00', cash_tendered: cash ?? '0.00', remaining: cash ? '0.00' : '3455.00', change: cash ? '545.00' : '0.00', errors: [] },
});

const sale: PosSale = { id: 40, order_id: 900, order_number: 'TMB-26-00042', grand_total: '3455.00', tendered_total: '4000.00', cash_tendered: '4000.00', change_due: '545.00', created_at: '2026-11-15T05:00:00+00:00' };

function posApi(me: Me, open: PosSession | null = session) {
    return mockApi((url, init) => {
        const method = init?.method ?? 'GET';
        const path = new URL(url, 'http://x').pathname.replace('/api/v1/admin', '');
        if (path === '/auth/me') return { status: 200, body: me };
        if (path === '/pos/context') return { status: 200, body: context(open) };
        if (path === '/pos/sessions/1/catalog') return { status: 200, body: { data: items } };
        if (path === '/pos/sessions/1/holds' && method === 'GET') return { status: 200, body: { data: [] } };
        if (path === '/pos/sessions/1/quote') {
            const body = JSON.parse(String(init?.body)) as { tenders?: { amount: string }[] };
            return { status: 200, body: quote(body.tenders?.[0]?.amount ?? null) };
        }
        if (path === '/pos/sessions/1/sales' && method === 'POST') return { status: 201, body: sale };
        if (path === '/pos/sessions' && method === 'POST') return { status: 201, body: session };
        return { status: 404 };
    });
}

function call(calls: ReturnType<typeof mockApi>, method: string, suffix: string): RequestInit | undefined {
    const found = calls.mock.calls.filter(([url, init]) => String(url).includes(suffix) && ((init as RequestInit | undefined)?.method ?? 'GET') === method).at(-1);
    return found?.[1] as RequestInit | undefined;
}

describe('POS register', () => {
    it('shows the drawer header and the grid with stock at this branch', async () => {
        posApi(cashier);
        renderApp('/pos');

        expect(await screen.findByRole('heading', { name: 'POS · Chattogram · Agrabad' })).toBeTruthy();
        expect(screen.getByText(/Register 1 · cashier Sadia Haque · drawer opened 10:02 am with ৳5,000/)).toBeTruthy();
        const brick = await screen.findByRole('button', { name: 'Add Brick Box 500, ৳1,850, 7 here' });
        expect(within(brick).getByText('7 here')).toBeTruthy();
        // None at this branch: shown, but cannot be added.
        expect((screen.getByRole('button', { name: 'Add Foam Blaster X2, ৳1,750, 0 here' }) as HTMLButtonElement).disabled).toBe(true);
        expect(screen.getByRole('button', { name: 'Building' })).toBeTruthy();
    });

    it('shows the server\'s totals and change, never its own sums', async () => {
        posApi(cashier);
        renderApp('/pos');

        fireEvent.click(await screen.findByRole('button', { name: /^Add Brick Box 500/ }));
        // The quote's total, though the one line is ৳1,850.
        const total = await screen.findByText('Total');
        expect(total.nextElementSibling?.textContent).toBe('৳3,455');
        expect(screen.getByText('Still due').nextElementSibling?.textContent).toBe('৳3,455');
        expect(screen.getByRole('button', { name: 'Charge ৳3,455' })).toHaveProperty('disabled', true);

        fireEvent.change(screen.getByLabelText('Cash received'), { target: { value: '4000' } });
        await waitFor(() => expect(screen.getByText('Change to give').nextElementSibling?.textContent).toBe('৳545'));
        await waitFor(() => expect(screen.getByRole('button', { name: 'Charge ৳3,455' })).toHaveProperty('disabled', false));
    });

    it('charges with the lines, tenders, the quoted total and an Idempotency-Key, then shows the change', async () => {
        const calls = posApi(cashier);
        renderApp('/pos');

        fireEvent.click(await screen.findByRole('button', { name: /^Add Brick Box 500/ }));
        fireEvent.change(await screen.findByLabelText('Cash received'), { target: { value: '4000' } });
        const charge = await screen.findByRole('button', { name: 'Charge ৳3,455' });
        await waitFor(() => expect(charge).toHaveProperty('disabled', false));
        fireEvent.click(charge);

        expect(await screen.findByText('Sale complete · TMB-26-00042')).toBeTruthy();
        const init = call(calls, 'POST', '/pos/sessions/1/sales')!;
        expect(JSON.parse(String(init.body))).toEqual({
            lines: [{ variant_id: 11, quantity: 1 }],
            coupon_code: null,
            customer_phone: null,
            tenders: [{ method: 'cash', amount: '4000', reference: null }],
            expected_total: '3455.00',
            customer_name: null,
        });
        expect((init.headers as Record<string, string>)['Idempotency-Key']).toMatch(/^[0-9a-f-]{36}$/);
        expect(screen.getByRole('button', { name: 'Print receipt' })).toHaveProperty('disabled', false);
    });

    it('offers bKash with a transaction id field, split with cash', async () => {
        posApi(cashier);
        renderApp('/pos');

        fireEvent.click(await screen.findByRole('button', { name: /^Add Brick Box 500/ }));
        fireEvent.click(screen.getByRole('button', { name: 'bKash' }));
        expect(await screen.findByLabelText('bKash transaction ID')).toBeTruthy();
        expect(screen.getByRole('button', { name: 'bKash' }).getAttribute('aria-pressed')).toBe('true');
        expect(screen.getByRole('button', { name: 'Cash' }).getAttribute('aria-pressed')).toBe('true');
    });

    it('shows WhatsApp receipt disabled with the reason', async () => {
        posApi(cashier);
        renderApp('/pos');

        const whatsapp = await screen.findByRole('button', { name: 'WhatsApp receipt' });
        expect(whatsapp).toHaveProperty('disabled', true);
        expect(screen.getByText('WhatsApp receipts need a WhatsApp provider — none is connected yet.')).toBeTruthy();
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

        expect(await screen.findByRole('heading', { name: 'POS · চট্টগ্রাম · আগ্রাবাদ' })).toBeTruthy();
        expect(await screen.findByText('এখানে 7')).toBeTruthy();
    });
});
