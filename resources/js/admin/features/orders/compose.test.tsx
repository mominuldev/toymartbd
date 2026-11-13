import { cleanup, fireEvent, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { renderApp } from '@/test/renderApp';
import type { Quote } from './composeApi';
import { detail, operator, ordersApi, postedTo } from './fixtures';

/*
 * Create order (P4-ORD-06), edit after placement (P4-ORD-07) and the board's bulk pack /
 * courier placeholder (P4-ORD-08). The client sends lines and an address and shows the
 * server's quote; it never computes a figure (rule 1).
 */

afterEach(() => {
    cleanup();
    localStorage.clear();
});

const quote: Quote = {
    lines: [{ variant_id: 41, product_name: 'Fashion Doll Deluxe Set', product_name_bn: null, variant_name: null, sku: 'TMB-FDS-01', quantity: 1, unit_price: '2350.00', subtotal: '2350.00', discount: '0.00', total: '2350.00', available: 5 }],
    unavailable_variant_ids: [],
    subtotal: '2350.00',
    promotion_discount: '0.00',
    coupon: null,
    delivery: { serviceable: true, zone: 'inside_dhaka_metro', weight_grams: 1500, free_shipping: false, cod_advance: null },
    shipping_charge: '80.00',
    discount_total: '0.00',
    grand_total: '2430.00',
    item_count: 1,
    ready: true,
    blockers: [],
};

const districts = {
    data: [{ id: 47, name: 'Dhaka', name_bn: 'ঢাকা', division: { id: 6, name: 'Dhaka', name_bn: 'ঢাকা' }, upazila_required: true, city_option: true }],
};

function composeApi() {
    return ordersApi(operator, (url, init) => {
        if (url.includes('/orders/compose/customer')) {
            return {
                status: 200,
                body: {
                    phone: '+8801985164739',
                    pii_masked: false,
                    customer: { id: 3, name: 'Sumaiya Islam', preferred_language: 'bn', orders_count: 1, delivered_orders_count: 0, total_spent: '0.00', last_order_at: null },
                    addresses: [{ id: 1, recipient_name: 'Sumaiya Islam', division_id: 6, district_id: 47, upazila_id: null, division: 'Dhaka', district: 'Dhaka', upazila: null, area: 'Mirpur 10', address_line: 'House 14, Road 3, Block C', landmark: 'Opposite Mirpur 10 metro station, gate 2', is_default: true }],
                    open_orders: [{ id: 124, number: 'TMB-26-00124', status: 'ready_to_ship', grand_total: '1300.00', placed_at: '2026-11-10T09:00:00Z' }],
                },
            };
        }
        if (url.includes('/orders/compose/districts/47/upazilas')) return { status: 200, body: { data: [] } };
        if (url.includes('/orders/compose/districts')) return { status: 200, body: districts };
        if (url.includes('/orders/compose/variants')) {
            return { status: 200, body: { data: [{ variant_id: 41, product_id: 4, product_name: 'Fashion Doll Deluxe Set', product_name_bn: null, variant_name: null, sku: 'TMB-FDS-01', price: '2350.00', available: 5, image_url: null }] } };
        }
        if (url.endsWith('/orders/quote') && init?.method === 'POST') return { status: 200, body: quote };
        if (url.endsWith('/orders/126/edit-quote') && init?.method === 'POST') return { status: 200, body: { ...quote, grand_total: '6340.00' } };
        if (url.endsWith('/orders/126') && init?.method === 'PUT') return { status: 200, body: detail };
        if (url.endsWith('/orders') && init?.method === 'POST') return { status: 201, body: { ...detail, id: 200, number: 'TMB-26-00200', source: 'phone' } };
        if (url.endsWith('/orders/bulk/pack')) {
            return {
                status: 200,
                body: {
                    data: [
                        { order_id: 126, number: 'TMB-26-00126', outcome: 'conflict', status: 'pending', detail: 'Order status cannot move from pending to ready_to_ship.' },
                        { order_id: 127, number: 'TMB-26-00127', outcome: 'done', status: 'ready_to_ship', detail: null },
                    ],
                    meta: { done: 1, failed: 1 },
                },
            };
        }
        return undefined;
    });
}

describe('create order', () => {
    it('looks the phone up, prefills the saved address, shows the server quote and posts it with an idempotency key', async () => {
        const calls = composeApi();
        renderApp('/orders/new');
        expect(await screen.findByRole('heading', { level: 1, name: 'New order' })).toBeTruthy();

        fireEvent.change(screen.getByLabelText(/^Phone/), { target: { value: '01985164739' } });
        expect(await screen.findByText('Sumaiya Islam · returning customer')).toBeTruthy();
        expect(screen.getByText('Same phone has an open order')).toBeTruthy();
        await waitFor(() => expect((screen.getByLabelText(/^Landmark/) as HTMLInputElement).value).toBe('Opposite Mirpur 10 metro station, gate 2'));
        expect((screen.getByLabelText(/^Customer name/) as HTMLInputElement).value).toBe('Sumaiya Islam');

        fireEvent.change(screen.getByRole('combobox', { name: 'Search name, SKU or scan barcode' }), { target: { value: 'doll' } });
        fireEvent.click(await screen.findByRole('button', { name: 'Add Fashion Doll Deluxe Set' }));

        const summary = screen.getByRole('region', { name: 'Summary' });
        await waitFor(() => expect(within(summary).getByText('Delivery · Inside Dhaka metro, 1500 g')).toBeTruthy());
        expect(within(summary).getByText('2,430')).toBeTruthy();

        const submit = await screen.findByRole('button', { name: 'Create order · ৳2,430' });
        await waitFor(() => expect((submit as HTMLButtonElement).disabled).toBe(false));
        fireEvent.click(submit);

        await waitFor(() => expect(postedTo(calls, '/api/v1/admin/orders')).not.toBeNull());
        const body = postedTo(calls, '/api/v1/admin/orders');
        expect(body).toMatchObject({ lines: [{ variant_id: 41, quantity: 1 }], source: 'phone', phone: '01985164739', district_id: 47, upazila_id: null, expected_total: '2430.00' });
        const post = calls.mock.calls.find(([url, init]) => String(url).endsWith('/api/v1/admin/orders') && init?.method === 'POST');
        expect(new Headers(post?.[1]?.headers).get('Idempotency-Key')).toBeTruthy();
        expect(await screen.findByText('Order TMB-26-00200 created.')).toBeTruthy();
    });

    it('shows online payment and drafts as not yet available', async () => {
        composeApi();
        renderApp('/orders/new');
        await screen.findByRole('heading', { level: 1, name: 'New order' });
        expect(screen.getByRole('radio', { name: /Cash on delivery/ }).getAttribute('aria-checked')).toBe('true');
        expect(screen.getByRole('radio', { name: /bKash advance/ }).getAttribute('aria-disabled')).toBe('true');
        expect(screen.getByRole('button', { name: 'Save as draft' }).getAttribute('aria-disabled')).toBe('true');
    });
});

describe('edit order', () => {
    it('starts from the order, re-quotes on change and saves with the new total', async () => {
        const calls = composeApi();
        renderApp('/orders/126/edit');
        expect(await screen.findByRole('heading', { level: 1, name: 'Edit TMB-26-00126' })).toBeTruthy();

        fireEvent.change(screen.getByLabelText(/^Area/), { target: { value: 'Bashundhara R/A' } });
        fireEvent.change(screen.getByLabelText(/^Address line/), { target: { value: 'House 7, Road 2' } });
        fireEvent.change(screen.getByLabelText(/^Landmark/), { target: { value: 'Near the mosque' } });
        const save = await screen.findByRole('button', { name: 'Save changes · ৳6,340' });
        await waitFor(() => expect((save as HTMLButtonElement).disabled).toBe(false));
        expect(screen.getByText('was ৳6,260')).toBeTruthy();
        fireEvent.click(save);

        await waitFor(() => expect(calls.mock.calls.some(([url, init]) => String(url).endsWith('/orders/126') && init?.method === 'PUT')).toBe(true));
        const put = calls.mock.calls.find(([url, init]) => String(url).endsWith('/orders/126') && init?.method === 'PUT');
        expect(JSON.parse(String(put?.[1]?.body))).toMatchObject({ lines: [{ variant_id: 11, quantity: 2 }], expected_total: '6340.00', area: 'Bashundhara R/A' });
    });

    it('explains instead of showing the form when the order is not editable', async () => {
        ordersApi(operator, (url) => (url.endsWith('/orders/126') ? { status: 200, body: { ...detail, status: 'shipped', editable: false } } : undefined));
        renderApp('/orders/126/edit');
        expect(await screen.findByText(/can no longer be edited/)).toBeTruthy();
    });

    it('offers Edit order and Print invoice on the detail page from the server flags', async () => {
        ordersApi(operator);
        renderApp('/orders/126');
        await screen.findByRole('heading', { level: 1 });
        expect(screen.getByRole('link', { name: 'Edit order' }).getAttribute('href')).toBe('/orders/126/edit');
        expect(screen.getByRole('button', { name: 'Print invoice' })).toBeTruthy();
    });
});

describe('bulk processing', () => {
    it('marks packed per order, keeps the failure selected, and offers courier booking only with shipments.book', async () => {
        const calls = composeApi();
        renderApp('/orders');
        const table = await screen.findByRole('table');
        fireEvent.click(within(table).getByRole('checkbox', { name: 'Select TMB-26-00126' }));
        fireEvent.click(within(table).getByRole('checkbox', { name: 'Select TMB-26-00127' }));
        const bar = screen.getByRole('region', { name: 'Bulk actions' });

        // P6: booking needs orders.update + shipments.book; this user has no shipments.book.
        expect(within(bar).queryByRole('button', { name: 'Book courier' })).toBeNull();

        fireEvent.click(within(bar).getByRole('button', { name: 'Mark packed' }));
        await waitFor(() => expect(postedTo(calls, '/orders/bulk/pack')).toEqual({ order_ids: [126, 127] }));
        expect(await within(bar).findByText(/cannot move from pending to ready_to_ship/)).toBeTruthy();
        expect((within(table).getByRole('checkbox', { name: 'Select TMB-26-00126' }) as HTMLInputElement).checked).toBe(true);
        // This fixture user lacks orders.create, so the board offers no "New order".
        expect(screen.queryByRole('link', { name: 'New order' })).toBeNull();
    });
});
