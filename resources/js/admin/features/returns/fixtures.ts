/**
 * Test fixtures for the returns screens (Vitest only). Bangla names in every fixture (rule 34).
 */
import { mockApi, viewer } from '@/test/renderApp';
import type { Me } from '@/features/auth/api';
import type { OrderReturn, Refund, RefundPanel } from './api';

/** Order operator: opens and inspects returns, approves refunds up to the threshold, pays out. */
export const operator: Me = {
    ...viewer,
    roles: ['order_operator'],
    permissions: ['dashboard.view', 'orders.view', 'returns.view', 'returns.manage', 'refunds.view', 'refunds.create'],
};

export const refund: Refund = {
    id: 31,
    order_id: 120,
    amount: '1250.00',
    shipping_amount: '0.00',
    type: 'partial',
    method: 'cod',
    status: 'approved',
    reason: 'RMA-26-00042 · defective',
    gateway_reference: null,
    restock: true,
    note: null,
    needs_manager: false,
    approval_threshold: '2000.00',
    payout: 'manual',
    requested_by: 7,
    approved_by: 7,
    approved_at: '2026-11-10T09:00:00Z',
    processed_at: null,
    created_at: '2026-11-10T08:00:00Z',
    items: [{ order_item_id: 501, quantity: 1, amount: '1250.00' }],
    can: { approve: false, pay: true },
};

export const requested: OrderReturn = {
    id: 42,
    number: 'RMA-26-00042',
    status: 'requested',
    reason: 'damaged_in_transit',
    reason_note: 'বক্সটা ভাঙা ছিল',
    refund_shipping: false,
    note: null,
    value: '1250.00',
    order: {
        id: 120,
        number: 'TMB-26-00120',
        customer_name: 'রাকিবুল হাসান',
        status: 'delivered',
        payment_status: 'paid',
        payment_method: 'cod',
        delivered_at: '2026-11-06T10:00:00Z',
    },
    window_days: 7,
    within_window: true,
    items: [
        {
            id: 1,
            order_item_id: 501,
            product_name: 'Brick Box 500',
            product_name_bn: 'ব্রিক বক্স 500',
            variant_name: 'Classic',
            sku: 'TMB-BB-500',
            image_path: null,
            unit_price: '1250.00',
            quantity: 2,
            quantity_resellable: null,
            quantity_damaged: null,
        },
    ],
    has_refund: false,
    refund: null,
    received_at: null,
    inspected_at: null,
    closed_at: null,
    created_at: '2026-11-08T10:00:00Z',
    can: { manage: true },
};

export const inspected: OrderReturn = {
    ...requested,
    status: 'inspected',
    items: [{ ...requested.items[0]!, quantity_resellable: 1, quantity_damaged: 1 }],
    has_refund: true,
    refund,
    inspected_at: '2026-11-10T08:00:00Z',
};

export const panel: RefundPanel = {
    order_id: 120,
    accepts_returns: true,
    shipping_claimed: false,
    captured: '3070.00',
    refundable: '3070.00',
    lines: [{ order_item_id: 501, product_name: 'Brick Box 500', product_name_bn: 'ব্রিক বক্স 500', variant_name: 'Classic', sku: 'TMB-BB-500', quantity: 2, quantity_returned: 0, returnable: 2 }],
    returns: [],
    refunds: [],
    can: { create_return: true },
};

const counts = { requested: 1, received: 0, inspected: 1, closed: 3, rejected: 0 };

export function returnsApi(me: Me, handler?: (url: string, init?: RequestInit) => { status: number; body?: unknown } | undefined) {
    return mockApi((url, init) => {
        const custom = handler?.(url, init);
        if (custom) return custom;
        if (url.endsWith('/auth/me')) return { status: 200, body: me };
        if (url.includes('/returns?')) {
            const rows = url.includes('filter%5Bstatus%5D=inspected') ? [inspected] : url.includes('filter%5Bstatus%5D=requested') ? [requested] : [];
            return { status: 200, body: { data: rows, links: {}, meta: { next_cursor: null, prev_cursor: null, counts } } };
        }
        if (/\/returns\/42$/.test(url)) return { status: 200, body: url.includes('inspected') ? inspected : requested };
        return { status: 404 };
    });
}
