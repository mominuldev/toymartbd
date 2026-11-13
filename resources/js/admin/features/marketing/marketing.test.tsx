import { cleanup, fireEvent, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import type { Me } from '@/features/auth/api';
import { mockApi, renderApp, viewer } from '@/test/renderApp';
import type { CartRecovery, Coupon, Promotion, RecoverySummary } from './api';
import { fromDhakaInput, toDhakaInput } from './parts';
import { toDraft, toPromotionInput } from './promotions/PromotionFormPage';

afterEach(() => {
    cleanup();
    localStorage.clear();
});

// No orders.create: the flash-sale picker and scope names live under marketing permissions.
const marketer: Me = { ...viewer, roles: ['manager'], permissions: ['dashboard.view', 'marketing.view', 'marketing.manage'] };
const reader: Me = { ...viewer, roles: ['content_editor'], permissions: ['dashboard.view', 'marketing.view'] };

const coupon: Coupon = {
    id: 7,
    code: 'EID2026',
    description: 'ঈদের ছাড়',
    batch: null,
    type: 'percent',
    value: '10.00',
    max_discount: '500.00',
    min_order_amount: '1500.00',
    usage_limit_total: 1000,
    usage_limit_per_customer: 1,
    used_count: 12,
    starts_at: '2026-06-14T18:00:00Z',
    ends_at: '2026-06-22T17:59:00Z',
    applies_to: 'all',
    scope_ids: [],
    excludes: {},
    first_order_only: false,
    is_active: true,
    created_at: '2026-06-01T00:00:00Z',
    updated_at: '2026-06-01T00:00:00Z',
};

const flash: Promotion = {
    id: 3,
    name: '11.11 flash sale',
    name_bn: '১১.১১ ফ্ল্যাশ সেল',
    description: null,
    type: 'flash_sale',
    priority: 100,
    is_stackable: false,
    allows_coupons: true,
    rules: { scope: { applies_to: 'all' } },
    starts_at: '2026-11-11T06:00:00Z',
    ends_at: '2026-11-11T18:00:00Z',
    is_active: true,
    status: 'running',
    usage_count: 4,
    usage_limit_total: null,
    usage_limit_per_customer: 2,
    budget: null,
    discount_spent: '1200.00',
    flash_items: [
        {
            id: 1,
            variant_id: 40,
            sku: 'RCC-RED',
            product_name: 'Remote Control Car',
            product_name_bn: 'রিমোট কন্ট্রোল গাড়ি',
            variant_name: 'Red',
            regular_price: '1000.00',
            sale_price: '700.00',
            allotment: 10,
            claimed: 4,
            remaining: 6,
            per_order_limit: 2,
        },
    ],
    created_at: null,
    updated_at: null,
};

const summary: RecoverySummary = {
    from: '2026-11-07',
    to: '2026-11-13',
    abandoned: 46,
    messaged: 30,
    skipped: { no_consent: 16 },
    sent: 29,
    cost: '15.08',
    restored: 12,
    recovered: 9,
    recovered_delivered: 6,
    revenue_recovered: '24610.00',
    abandoned_value: '142800.00',
};

const recoveries: CartRecovery[] = [
    {
        id: 1,
        cart_id: 11,
        phone: '01755•••208',
        customer_name: 'নাদিয়া রহমান',
        status: 'messaged',
        skip_reason: null,
        locale: 'bn',
        cart_value: '7000.00',
        item_count: 2,
        abandoned_at: '2026-11-13T06:00:00Z',
        message_status: 'sent',
        message_cost: '0.52',
        message_segments: 2,
        sent_at: '2026-11-13T07:00:00Z',
        restored_at: '2026-11-13T07:30:00Z',
        recovered_order: { id: 501, number: 'TMB-26-00501', status: 'delivered', grand_total: '7130.00' },
        recovered_at: '2026-11-13T08:00:00Z',
    },
    {
        id: 2,
        cart_id: 12,
        phone: '01911•••774',
        customer_name: null,
        status: 'skipped',
        skip_reason: 'no_consent',
        locale: 'en',
        cart_value: '3300.00',
        item_count: 2,
        abandoned_at: '2026-11-13T04:00:00Z',
        message_status: null,
        message_cost: null,
        message_segments: null,
        sent_at: null,
        restored_at: null,
        recovered_order: null,
        recovered_at: null,
    },
];

function marketingApi(me: Me, couponOverride: Partial<Coupon> = {}) {
    return mockApi((url, init) => {
        const method = String(init?.method ?? 'GET');
        if (url.endsWith('/auth/me')) return { status: 200, body: me };
        if (url.includes('/marketing/lookups/names?kind=products&ids=3,5'))
            return { status: 200, body: { data: [{ id: 3, name: 'LEGO Classic', name_bn: 'লেগো ক্লাসিক' }, { id: 5, name: 'Rubik Cube', name_bn: null }] } };
        if (url.includes('/coupons/generate')) {
            const body = JSON.parse(String(init?.body)) as { count: number; batch: string; prefix: string | null };
            return { status: 201, body: { batch: body.batch, count: body.count, codes: Array.from({ length: body.count }, (_, i) => `${body.prefix}-CODE${i}`) } };
        }
        if (/\/coupons\/7\/report$/.test(url))
            return { status: 200, body: { summary: { orders: 12, discount_total: '2400.00', gross_sales: '36000.00', delivered_orders: 8, delivered_revenue: '24000.00' }, batch: null, recent: [] } };
        if (/\/coupons\/7$/.test(url)) return { status: 200, body: { ...coupon, ...couponOverride } };
        if (url.includes('/coupons') && method === 'POST') return { status: 201, body: { ...coupon, id: 8, code: 'NEWCODE' } };
        if (url.includes('/coupons')) return { status: 200, body: { data: [coupon, { ...coupon, id: 9, code: 'SMS-7K4MQ2PA', batch: 'SMSNOV', type: 'free_shipping', value: '0.00' }], links: {}, meta: { next_cursor: null } } };
        if (/\/promotions\/3\/report$/.test(url)) return { status: 200, body: { summary: { orders: 4, discount_total: '1200.00', gross_sales: '5000.00', delivered_orders: 0, delivered_revenue: '0.00' }, recent: [] } };
        if (/\/promotions\/3$/.test(url)) return { status: method === 'PUT' ? 200 : 200, body: flash };
        if (url.includes('/promotions') && method === 'POST') return { status: 201, body: { ...flash, id: 5, type: 'tiered' } };
        if (url.includes('/promotions')) return { status: 200, body: { data: [flash], links: {}, meta: { next_cursor: null } } };
        if (url.includes('/cart-recoveries/summary')) return { status: 200, body: summary };
        if (url.includes('/cart-recoveries')) return { status: 200, body: { data: recoveries, links: {}, meta: { next_cursor: null } } };
        if (url.includes('/orders/compose/variants')) return { status: 403 };
        if (url.includes('/marketing/lookups/variants'))
            return { status: 200, body: { data: [{ variant_id: 41, product_id: 9, product_name: 'Building Blocks', product_name_bn: 'বিল্ডিং ব্লক', variant_name: null, sku: 'BB-100', price: '2000.00', available: 12, image_url: null }] } };
        return { status: 404 };
    });
}

const writes = (calls: ReturnType<typeof marketingApi>) =>
    calls.mock.calls
        .filter(([, init]) => ['POST', 'PUT', 'DELETE'].includes(String((init as RequestInit | undefined)?.method)))
        .map(([url, init]) => ({ url: String(url), method: String((init as RequestInit).method), body: JSON.parse(String((init as RequestInit).body ?? 'null')) as Record<string, unknown> | null }));

describe('Dhaka time helpers', () => {
    it('round-trips a Dhaka wall time through +06:00', () => {
        expect(fromDhakaInput('2026-06-15T00:00')).toBe('2026-06-15T00:00:00+06:00');
        expect(toDhakaInput('2026-06-14T18:00:00Z')).toBe('2026-06-15T00:00');
        expect(fromDhakaInput('')).toBeNull();
    });
});

describe('coupons', () => {
    it('lists coupons with the server figures, batch tag and status', async () => {
        marketingApi(marketer);
        renderApp('/marketing/coupons');
        expect(await screen.findByText('EID2026')).toBeTruthy();
        expect(screen.getByText('10% off orders over ৳1,500 · max ৳500')).toBeTruthy();
        expect(screen.getAllByText('12 of 1000')).toHaveLength(2);
        expect(screen.getByText('Batch SMSNOV')).toBeTruthy();
        expect(screen.getByRole('link', { name: 'New coupon' })).toBeTruthy();
    });

    it('hides write actions without marketing.manage', async () => {
        marketingApi(reader);
        renderApp('/marketing/coupons');
        await screen.findByText('EID2026');
        expect(screen.queryByRole('link', { name: 'New coupon' })).toBeNull();
        expect(screen.queryByRole('link', { name: 'Bulk codes' })).toBeNull();
    });

    it('creates a coupon, sending Dhaka times with +06:00 and upper-cased code', async () => {
        const calls = marketingApi(marketer);
        renderApp('/marketing/coupons/new');
        fireEvent.change(await screen.findByRole('textbox', { name: /^Code/ }), { target: { value: 'puja10' } });
        fireEvent.change(screen.getByRole('textbox', { name: /^Discount/ }), { target: { value: '10' } });
        fireEvent.change(screen.getByLabelText(/^Starts/), { target: { value: '2026-10-01T00:00' } });
        fireEvent.change(screen.getByLabelText(/^Ends/), { target: { value: '2026-10-05T23:59' } });
        fireEvent.click(screen.getByRole('button', { name: 'Save & activate' }));
        await waitFor(() => expect(writes(calls).length).toBe(1));
        const body = writes(calls)[0]!.body!;
        expect(body).toMatchObject({ code: 'PUJA10', type: 'percent', value: '10', starts_at: '2026-10-01T00:00:00+06:00', ends_at: '2026-10-05T23:59:00+06:00', applies_to: 'all', usage_limit_per_customer: 1 });
    });

    it('refuses an invalid coupon with the generated schema before calling the API', async () => {
        const calls = marketingApi(marketer);
        renderApp('/marketing/coupons/new');
        fireEvent.click(await screen.findByRole('button', { name: 'Save & activate' }));
        await waitFor(() => expect(screen.getAllByRole('textbox', { name: /^Code/ })[0]!.getAttribute('aria-invalid')).toBe('true'));
        expect(writes(calls)).toEqual([]);
    });

    it('generates a batch of codes and offers copy and CSV', async () => {
        const calls = marketingApi(marketer);
        renderApp('/marketing/coupons/generate');
        fireEvent.change(await screen.findByRole('textbox', { name: /^Batch name/ }), { target: { value: 'smsnov' } });
        fireEvent.change(screen.getByRole('spinbutton', { name: /^How many/ }), { target: { value: '3' } });
        fireEvent.change(screen.getByRole('textbox', { name: /^Prefix/ }), { target: { value: 'sms' } });
        fireEvent.click(screen.getByRole('radio', { name: 'Free delivery' }));
        fireEvent.click(screen.getByRole('button', { name: 'Generate codes' }));
        await waitFor(() => expect(writes(calls).some((w) => w.url.endsWith('/coupons/generate'))).toBe(true));
        expect(writes(calls)[0]!.body).toMatchObject({ batch: 'SMSNOV', count: 3, prefix: 'SMS', code_length: 8, type: 'free_shipping' });
        const region = await screen.findByRole('region', { name: '3 codes created in batch SMSNOV' });
        expect(within(region).getByText('SMS-CODE0')).toBeTruthy();
        expect(within(region).getByRole('link', { name: 'Download CSV' }).getAttribute('download')).toBe('SMSNOV.csv');
    });

    it('names the saved scope chips from the server, never "#id"', async () => {
        const calls = marketingApi(marketer, { applies_to: 'products', scope_ids: [5, 3] });
        renderApp('/marketing/coupons/7');
        expect(await screen.findByText('LEGO Classic')).toBeTruthy();
        expect(screen.getByText('Rubik Cube')).toBeTruthy();
        expect(screen.queryByText('#3')).toBeNull();
        expect(calls.mock.calls.some(([url]) => String(url).includes('/marketing/lookups/names?kind=products&ids=3,5'))).toBe(true);
    });

    it('shows the usage report from the server on an existing coupon', async () => {
        marketingApi(marketer);
        renderApp('/marketing/coupons/7');
        const report = await screen.findByRole('region', { name: 'Usage report' });
        expect(within(report).getByText('৳24,000')).toBeTruthy();
        expect(within(report).getByText('8 delivered orders · revenue counts on delivery')).toBeTruthy();
    });
});

describe('promotions', () => {
    it('lists flash sales with status and priority', async () => {
        marketingApi(marketer);
        renderApp('/marketing/flash-sales');
        expect(await screen.findByText('11.11 flash sale')).toBeTruthy();
        const table = screen.getByRole('table');
        expect(within(table).getByText('Running')).toBeTruthy();
        expect(within(table).getByText('Exclusive')).toBeTruthy();
        expect(within(table).getByText('100')).toBeTruthy();
    });

    it('switches type and sends only that type’s rules (tiered)', async () => {
        const calls = marketingApi(marketer);
        renderApp('/marketing/promotions/new');
        fireEvent.change(await screen.findByRole('textbox', { name: /^Name \(English\)/ }), { target: { value: 'Spend more' } });
        fireEvent.click(screen.getByRole('radio', { name: 'Spend more, save more' }));
        fireEvent.change(screen.getByRole('textbox', { name: /^Spend at least/ }), { target: { value: '3000' } });
        fireEvent.change(screen.getByRole('textbox', { name: /^Value/ }), { target: { value: '5' } });
        expect(screen.getByRole('switch', { name: 'Coupons can also apply' }).getAttribute('aria-checked')).toBe('true');
        fireEvent.click(screen.getByRole('switch', { name: 'Coupons can also apply' }));
        fireEvent.click(screen.getByRole('button', { name: 'Save & activate' }));
        await waitFor(() => expect(writes(calls).length).toBe(1));
        const body = writes(calls)[0]!.body!;
        expect(body).toMatchObject({ type: 'tiered', allows_coupons: false, rules: { tiers: [{ min_subtotal: '3000', type: 'percent', value: '5' }] }, flash_items: [] });
        expect((body.rules as Record<string, unknown>).buy).toBeUndefined();
    });

    it('requires a hard window for a flash sale', async () => {
        const calls = marketingApi(marketer);
        renderApp('/marketing/flash-sales/new');
        fireEvent.change(await screen.findByRole('textbox', { name: /^Name \(English\)/ }), { target: { value: 'Midnight sale' } });
        fireEvent.click(screen.getByRole('button', { name: 'Save & activate' }));
        expect(await screen.findByText('A flash sale needs a start and an end')).toBeTruthy();
        expect(writes(calls)).toEqual([]);
    });

    it('edits flash items: shows claimed/remaining, adds a variant from search, sends sale price and quantity', async () => {
        const calls = marketingApi(marketer);
        renderApp('/marketing/flash-sales/3');
        expect(await screen.findByText(/4 claimed · 6 left/)).toBeTruthy();
        // A line with claimed units cannot be removed (its units are on open orders).
        expect((screen.getByRole('button', { name: /Remove Remote Control Car/ }) as HTMLButtonElement).disabled).toBe(true);

        fireEvent.change(screen.getByRole('textbox', { name: 'Add item' }), { target: { value: 'block' } });
        fireEvent.click(await screen.findByRole('button', { name: /Building Blocks/ }));
        const sale = screen.getAllByRole('textbox', { name: /^Sale price/ });
        fireEvent.change(sale[1]!, { target: { value: '1500' } });
        const qty = screen.getAllByRole('spinbutton', { name: /^Quantity/ });
        fireEvent.change(qty[1]!, { target: { value: '20' } });
        fireEvent.click(screen.getByRole('button', { name: 'Save' }));
        await waitFor(() => expect(writes(calls).some((w) => w.method === 'PUT')).toBe(true));
        expect(writes(calls)[0]!.body).toMatchObject({
            type: 'flash_sale',
            starts_at: '2026-11-11T12:00:00+06:00',
            ends_at: '2026-11-12T00:00:00+06:00',
            flash_items: [
                { variant_id: 40, sale_price: '700.00', allotment: 10, per_order_limit: 2 },
                { variant_id: 41, sale_price: '1500', allotment: 20, per_order_limit: null },
            ],
        });
    });

    it('maps every type to its own rules document', () => {
        const base = toDraft(undefined, 'discount');
        expect(toPromotionInput({ ...base, type: 'buy_x_get_y', buy: '2', get: '1', percent: '100' }).rules).toMatchObject({ buy: 2, get: 1, percent: 100 });
        expect(toPromotionInput({ ...base, type: 'bundle', kind: 'price', value: '2500', product_ids: [1, 2] }).rules).toMatchObject({ type: 'price', value: '2500', product_ids: [1, 2] });
        expect(toPromotionInput({ ...base, type: 'free_delivery', min_subtotal: '2000' }).rules).toMatchObject({ min_subtotal: '2000' });
        expect(toPromotionInput({ ...base, type: 'discount', kind: 'fixed', value: '50' }).rules).toMatchObject({ type: 'fixed', value: '50' });
    });
});

describe('abandoned carts', () => {
    it('shows the server summary (revenue from delivered orders) and each cart’s reminder state', async () => {
        marketingApi(marketer);
        renderApp('/marketing/abandoned-carts');
        expect(await screen.findByText('46')).toBeTruthy();
        expect(screen.getByText('৳142,800 left in carts')).toBeTruthy();
        expect(screen.getByText('৳24,610 delivered · 6 delivered orders')).toBeTruthy();
        expect(screen.getByText('৳15.08')).toBeTruthy();
        expect(await screen.findByText('নাদিয়া রহমান')).toBeTruthy();
        expect(screen.getByText('Sent')).toBeTruthy();
        expect(screen.getByText('Order TMB-26-00501')).toBeTruthy();
        expect(screen.getByText('Guest checkout')).toBeTruthy();
        expect(screen.getByText('No consent — not contacted')).toBeTruthy();
    });

    it('asks the server for the chosen Dhaka date range', async () => {
        const calls = marketingApi(marketer);
        renderApp('/marketing/abandoned-carts?from=2026-11-01&to=2026-11-10');
        await screen.findByText('46');
        expect(calls.mock.calls.some(([url]) => String(url).includes('/cart-recoveries/summary?from=2026-11-01&to=2026-11-10'))).toBe(true);
    });

    it('renders in Bangla with Western digits', async () => {
        marketingApi(marketer);
        renderApp('/marketing/abandoned-carts', { locale: 'bn' });
        expect(await screen.findByText('কার্টে ৳142,800 রয়ে গেছে')).toBeTruthy();
    });
});
