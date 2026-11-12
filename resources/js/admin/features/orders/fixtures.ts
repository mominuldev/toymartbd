/**
 * Test fixtures for the order board and detail (Vitest only). Bangla names in every fixture (rule 34).
 */
import { mockApi, viewer } from '@/test/renderApp';
import type { Me } from '@/features/auth/api';
import type { BulkResult, Order, OrderRow, OrdersPage } from './api';

/** Order operator: confirms, moves and cancels orders; sees masked PII only. */
export const operator: Me = {
    ...viewer,
    roles: ['order_operator'],
    permissions: ['dashboard.view', 'orders.view', 'orders.confirm', 'orders.update', 'orders.cancel'],
};

export const pendingRow: OrderRow = {
    id: 126,
    number: 'TMB-26-00126',
    status: 'pending',
    payment_status: 'unpaid',
    fulfillment_status: 'unfulfilled',
    payment_method: 'cod',
    source: 'web',
    customer_id: 9,
    customer_name: 'Shahriar Kabir',
    customer_phone_masked: '01712•••318',
    district: 'Dhaka',
    items_count: 2,
    grand_total: '6260.00',
    due_total: '6260.00',
    paid_total: '0.00',
    refunded_total: '0.00',
    risk_score: 80,
    risk_level: 'high',
    verification_status: 'pending',
    anomaly: null,
    placed_at: '2026-11-07T14:08:00Z',
    created_at: '2026-11-07T14:08:00Z',
};

export const banglaRow: OrderRow = {
    ...pendingRow,
    id: 127,
    number: 'TMB-26-00127',
    customer_name: 'রিফাত চৌধুরী',
    customer_phone_masked: '01876•••912',
    district: 'Keraniganj',
    items_count: 3,
    grand_total: '1910.00',
    due_total: '1910.00',
    risk_score: 10,
    risk_level: 'low',
    verification_status: 'not_required',
    source: 'facebook',
};

export const refundRow: OrderRow = {
    ...pendingRow,
    id: 128,
    number: 'TMB-26-00128',
    status: 'cancelled',
    payment_status: 'partially_paid',
    payment_method: 'bkash',
    customer_name: 'Tanzila Akter',
    grand_total: '1880.00',
    paid_total: '200.00',
    due_total: '0.00',
    risk_score: 0,
    risk_level: 'low',
    verification_status: 'not_required',
    anomaly: 'refund_pending',
};

export const page: OrdersPage = {
    data: [pendingRow, banglaRow, refundRow],
    meta: {
        next_cursor: 'abc',
        prev_cursor: null,
        counts: { all: 12, pending: 2, verify: 1, on_hold: 0, confirmed: 0, processing: 0, ready_to_ship: 3, shipped: 1, delivered: 3, returned: 1, cancelled: 1, anomalies: 1 },
    },
};

export const detail: Order = {
    id: 126,
    number: 'TMB-26-00126',
    status: 'pending',
    payment_status: 'unpaid',
    fulfillment_status: 'unfulfilled',
    source: 'web',
    payment_method: 'cod',
    currency: 'BDT',
    pii_masked: true,
    anomaly: null,
    customer: { id: 9, name: 'Shahriar Kabir', phone: '01712•••318', orders_count: 1, delivered_orders_count: 0, total_spent: '0.00' },
    shipping: { name: 'Shahriar Kabir', phone: '01712•••318', alt_phone: null, division_id: 6, district_id: 47, upazila_id: 410, division: 'Dhaka', district: 'Dhaka', upazila: 'Bashundhara', area: null, address: null, landmark: null, postcode: null },
    totals: {
        subtotal: '6200.00',
        discount_total: '0.00',
        coupon_code: null,
        coupon_discount: '0.00',
        promotion_discount: '0.00',
        shipping_charge: '60.00',
        shipping_discount: '0.00',
        tax_total: '0.00',
        grand_total: '6260.00',
        paid_total: '0.00',
        due_total: '6260.00',
        refunded_total: '0.00',
    },
    items: [
        {
            id: 1,
            variant_id: 11,
            product_id: 1,
            product_name: 'Plush Teddy Bear 45cm',
            product_name_bn: 'টেডি বিয়ার 45 সেমি',
            variant_name: null,
            sku: 'TMB-PTB-45',
            unit_price: '3100.00',
            quantity: 2,
            discount: '0.00',
            line_total: '6200.00',
            cost_price_snapshot: null,
            quantity_shipped: 0,
            quantity_returned: 0,
        },
    ],
    payments: [],
    timeline: [
        { id: 1, type: 'placed', field: null, from: null, to: 'pending', description: null, user_id: null, user_name: null, is_customer_visible: true, created_at: '2026-11-07T14:08:00Z' },
    ],
    risk: {
        score: 80,
        level: 'high',
        verification_status: 'pending',
        flags: { signals: [{ code: 'first_order', weight: 80 }], hold_reasons: ['first_order_above_threshold'], computed_at: '2026-11-07T14:08:00Z' },
    },
    notes: [{ id: 5, body: 'কল করেছি, ধরেননি', is_customer_visible: false, user_id: 7, user_name: 'Rina Akter', created_at: '2026-11-07T15:00:00Z' }],
    available_actions: ['confirm', 'hold', 'cancel', 'add_note'],
    editable: true,
    can_print_invoice: true,
    customer_note: null,
    internal_note: null,
    cancel_reason: null,
    cancel_note: null,
    placed_at: '2026-11-07T14:08:00Z',
    confirmed_at: null,
    shipped_at: null,
    delivered_at: null,
    cancelled_at: null,
};

type Reply = { status: number; body?: unknown };
type Override = (url: string, init?: RequestInit) => Reply | undefined;

/** The order API, with an optional per-test override checked first. */
export function ordersApi(me: Me, override?: Override) {
    return mockApi((url, init) => {
        const custom = override?.(url, init);
        if (custom) return custom;
        if (url.endsWith('/auth/me')) return { status: 200, body: me };
        if (url.includes('/orders/bulk/confirm')) {
            const results: BulkResult[] = [
                { order_id: 126, number: 'TMB-26-00126', outcome: 'done', status: 'confirmed', detail: null },
                { order_id: 127, number: 'TMB-26-00127', outcome: 'conflict', status: 'cancelled', detail: 'A cancelled order cannot be confirmed.' },
            ];
            return { status: 200, body: { data: results, meta: { done: 1, failed: 1 } } };
        }
        const move = url.match(/\/orders\/126\/(confirm|hold|cancel|release|processing|ready-to-ship)$/);
        if (move && init?.method === 'POST') {
            const next = move[1] === 'confirm' ? 'confirmed' : move[1] === 'hold' ? 'on_hold' : move[1] === 'cancel' ? 'cancelled' : 'processing';
            return { status: 200, body: { ...detail, status: next, available_actions: ['add_note'] } };
        }
        if (url.endsWith('/orders/126/notes') && init?.method === 'POST') {
            return { status: 201, body: { id: 6, body: 'x', is_customer_visible: false, user_id: 7, user_name: 'Rina Akter', created_at: '2026-11-08T08:00:00Z' } };
        }
        if (url.endsWith('/orders/126')) return { status: 200, body: detail };
        if (/\/orders(\?|$)/.test(url)) return { status: 200, body: page };
        return { status: 404, body: { type: 'https://toymartbd.com/problems/not-found', title: 'Not found', status: 404 } };
    });
}

/** The JSON body of the last POST to a path ending in `suffix`, or null. */
export function postedTo(calls: ReturnType<typeof ordersApi>, suffix: string): Record<string, unknown> | null {
    const call = [...calls.mock.calls].reverse().find(([url, init]) => String(url).endsWith(suffix) && init?.method === 'POST');
    return call?.[1]?.body ? (JSON.parse(String(call[1].body)) as Record<string, unknown>) : null;
}
