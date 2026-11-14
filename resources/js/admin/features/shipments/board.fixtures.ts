/**
 * Test fixtures for the shipments board (Vitest only). Bangla names in every fixture (rule 34).
 */
import { mockApi, viewer } from '@/test/renderApp';
import type { Me } from '@/features/auth/api';
import type { BoardIssues, BoardRow, BoardStats, ShipmentTracking } from './boardApi';

/** Dispatcher: sees shipments, not customer PII. */
export const dispatcher: Me = {
    ...viewer,
    roles: ['dispatcher'],
    permissions: ['dashboard.view', 'shipments.view'],
};
/** Owner: shipments.view + customers.view_pii (labels). */
export const owner: Me = {
    ...viewer,
    roles: ['super_admin'],
    permissions: ['dashboard.view', 'shipments.view', 'customers.view_pii'],
};

const steadfast = {
    code: 'steadfast',
    name: 'Steadfast',
    name_bn: 'স্টেডফাস্ট',
};

export const submittedRow: BoardRow = {
    id: 97,
    number: 'SHP-26-00097',
    order: {
        id: 124,
        number: 'TMB-26-00124',
        customer_name: 'সুমাইয়া ইসলাম',
        phone: '017****5678',
        phone_masked: true,
    },
    provider: steadfast,
    consignment_id: null,
    status: 'submitted',
    provider_status_raw: null,
    last_event: null,
    submitted_at: '2026-11-08T03:05:00Z',
    cod_amount: '1930.00',
    cost: { amount: '99.00', basis: 'estimate' },
    delivery_attempts: 0,
};

export const transitRow: BoardRow = {
    id: 96,
    number: 'SHP-26-00096',
    order: {
        id: 123,
        number: 'TMB-26-00123',
        customer_name: 'ইমরান হোসেন',
        phone: '018****1234',
        phone_masked: true,
    },
    provider: steadfast,
    consignment_id: 'DT261107FJ2D9Q',
    status: 'in_transit',
    provider_status_raw: 'in_review',
    last_event: {
        id: 5,
        status: 'in_transit',
        provider_status: 'in_review',
        message: 'Reached Sylhet hub',
        location: null,
        occurred_at: '2026-11-07T12:40:00Z',
        received_at: '2026-11-07T12:41:00Z',
        source: 'poll',
    },
    submitted_at: null,
    cod_amount: '2570.00',
    cost: { amount: null, basis: null },
    delivery_attempts: 0,
};

export const deliveredRow: BoardRow = {
    ...transitRow,
    id: 93,
    number: 'SHP-26-00093',
    consignment_id: 'DT261104KM9T3P',
    status: 'delivered',
    last_event: {
        id: 9,
        status: 'delivered',
        provider_status: 'delivered',
        message: null,
        location: null,
        occurred_at: '2026-11-06T06:40:00Z',
        received_at: '2026-11-06T06:41:00Z',
        source: 'webhook',
    },
    cod_amount: '4550.00',
    cost: { amount: '246.00', basis: 'settled' },
};

export const counts = {
    all: 3,
    submitted: 1,
    in_transit: 1,
    delivered: 1,
    returned: 0,
    failed_attempts: 0,
};

export const stats: BoardStats = {
    from: '2026-11-02',
    to: '2026-11-08',
    in_transit: { count: 1, cod_on_road: '2570.00' },
    submitted: {
        count: 2,
        oldest_at: '2026-11-07T23:00:00Z',
        oldest_age_minutes: 325,
        chase_after_minutes: 360,
        overdue: false,
    },
    success: { delivered: 5, returned: 1, rate: '83.3' },
    days_to_deliver: {
        average: '1.8',
        timed: 5,
        by_zone: [
            { zone: 'inside_dhaka_metro', average: '1.1', timed: 3 },
            { zone: 'outside_dhaka', average: '2.6', timed: 2 },
        ],
    },
};

export const emptyStats: BoardStats = {
    ...stats,
    in_transit: { count: 0, cod_on_road: '0.00' },
    submitted: {
        count: 0,
        oldest_at: null,
        oldest_age_minutes: null,
        chase_after_minutes: 360,
        overdue: false,
    },
    success: { delivered: 0, returned: 0, rate: null },
    days_to_deliver: { average: null, timed: 0, by_zone: [] },
};

export const issues: BoardIssues = {
    from: '2026-10-10',
    to: '2026-11-08',
    returned: { count: 3, cost: '630.00', districts: ['কুমিল্লা', 'খুলনা'] },
    failed_attempts: { count: 1, latest_provider_status: 'Delivery_Failed' },
    unmapped: { count: 1, areas: ['খান জাহান আলী, খুলনা'] },
    by_courier: [
        {
            provider: steadfast,
            area: 'dhaka',
            delivered: 16,
            returned: 1,
            rate: '94.1',
        },
        {
            provider: steadfast,
            area: 'outside',
            delivered: 6,
            returned: 1,
            rate: '85.7',
        },
    ],
};

export const noIssues: BoardIssues = {
    ...issues,
    returned: { count: 0, cost: '0.00', districts: [] },
    failed_attempts: { count: 0, latest_provider_status: null },
    unmapped: { count: 0, areas: [] },
    by_courier: [],
};

export const tracking: ShipmentTracking = {
    id: 96,
    number: 'SHP-26-00096',
    status: 'partially_delivered',
    provider_status_raw: 'partial_delivered',
    provider: steadfast,
    consignment_id: 'DT261107FJ2D9Q',
    tracking_code: 'FJ2D9Q',
    tracking_url: 'https://steadfast.com.bd/t/FJ2D9Q',
    order: {
        id: 123,
        number: 'TMB-26-00123',
        customer_name: 'ইমরান হোসেন',
        phone: '018****1234',
        phone_masked: true,
    },
    weight_grams: 1600,
    item_count: 2,
    cod_amount: '3080.00',
    cod_collected: '1330.00',
    charges: {
        charge: '130.00',
        cod_fee: '13.00',
        return_charge: '70.00',
        total_cost: '213.00',
    },
    cost: { amount: '213.00', basis: 'recorded' },
    delivery_attempts: 1,
    submitted_at: null,
    booked_at: '2026-11-05T10:40:00Z',
    delivered_at: '2026-11-07T05:48:00Z',
    returned_at: null,
    can_print_label: true,
    events: [
        {
            id: 3,
            status: 'partially_delivered',
            from_status: 'out_for_delivery',
            provider_status: 'partial_delivered',
            message: null,
            location: null,
            occurred_at: '2026-11-07T05:48:00Z',
            received_at: '2026-11-07T05:49:00Z',
            source: 'webhook',
            payload_keys: ['status', 'cod_amount'],
        },
        {
            id: 2,
            status: 'in_transit',
            from_status: 'booked',
            provider_status: 'in_review',
            message: 'Reached Savar hub',
            location: 'সাভার হাব',
            occurred_at: '2026-11-06T14:05:00Z',
            received_at: '2026-11-06T14:06:00Z',
            source: 'poll',
            payload_keys: [],
        },
    ],
    outcome: {
        recorded: true,
        cod_collected: '1330.00',
        delivered: [
            {
                name: 'Plush Teddy Bear 45cm',
                name_bn: 'টেডি বেয়ার 45cm',
                quantity: 1,
                amount: '1200.00',
            },
        ],
        came_back: [
            {
                name: 'Foam Blaster X2',
                name_bn: 'ফোম ব্লাস্টার X2',
                quantity: 1,
                amount: '1750.00',
            },
        ],
    },
};

type Reply = { status: number; body?: unknown } | undefined;

export function boardApi(
    me: Me,
    opts: {
        rows?: BoardRow[];
        stats?: BoardStats;
        issues?: BoardIssues;
        tracking?: ShipmentTracking;
    } = {},
    handler?: (url: string) => Reply
) {
    return mockApi((url, init) => {
        const custom = handler?.(url);
        if (custom) return custom;
        if (url.endsWith('/auth/me')) return { status: 200, body: me };
        if (url.includes('/shipments/board/stats')) return { status: 200, body: opts.stats ?? stats };
        if (url.includes('/shipments/board/issues')) return { status: 200, body: opts.issues ?? issues };
        if (url.includes('/shipments/board')) {
            const rows = opts.rows ?? [submittedRow, transitRow, deliveredRow];
            return {
                status: 200,
                body: {
                    data: rows,
                    links: {},
                    meta: {
                        next_cursor: null,
                        prev_cursor: null,
                        counts,
                        from: '2026-11-02',
                        to: '2026-11-08',
                        providers: [steadfast],
                    },
                },
            };
        }
        if (/\/shipments\/\d+\/tracking$/.test(url)) return { status: 200, body: opts.tracking ?? tracking };
        void init;
        return { status: 404 };
    });
}
