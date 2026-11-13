import type { Me } from '@/features/auth/api';
import { mockApi, viewer } from '@/test/renderApp';
import type { HeldOrderDetail, PhoneRisk, QueueRow, RiskRules } from './api';

export const operator: Me = {
    ...viewer,
    roles: ['order_operator'],
    permissions: ['dashboard.view', 'orders.view', 'orders.cancel', 'orders.confirm', 'risk.view', 'risk.verify', 'customers.view_pii'],
};
export const manager: Me = {
    ...operator,
    roles: ['manager'],
    permissions: [...operator.permissions, 'risk.manage_lists', 'risk.manage_rules', 'settings.manage_integrations'],
};

const breakdown = [
    { code: 'cancellation_rate', weight: 25, value: '2/3', contribution: 17 },
    { code: 'courier_return_rate', weight: 25, value: '0/1', contribution: 0 },
    { code: 'failed_delivery', weight: 15, value: '0', contribution: 0 },
    { code: 'first_order', weight: 10, value: '3', contribution: 0 },
];
export const ours = {
    stored: true,
    internal_score: 17,
    level: 'low',
    breakdown,
    score_computed_at: '2026-11-08T08:18:00Z',
    counts: {
        total_orders: 3,
        delivered: 1,
        cancelled: 2,
        returned: 0,
        failed_delivery: 0,
        refused: 0,
    },
    last_order_at: '2026-11-08T08:00:00Z',
};
const notConnected = {
    connected: false,
    total_parcels: null,
    total_delivered: null,
    total_cancelled: null,
    total_fraud_reports: null,
    delivery_ratio: null,
    cancellation_ratio: null,
    fetched_at: null,
};
export const phoneRisk: PhoneRisk = {
    phone: '+8801712046318',
    pii_masked: false,
    ours,
    status: { value: 'normal', reason: null, set_by: null, set_at: null },
    courier: {
        checked_at: '2026-11-08T08:20:00Z',
        providers: [
            {
                provider: 'steadfast',
                name: 'Steadfast',
                name_bn: 'স্টেডফাস্ট',
                reason: 'no_merchant_account',
                ...notConnected,
            },
            {
                provider: 'pathao',
                name: 'Pathao',
                name_bn: 'পাঠাও',
                reason: 'no_official_api',
                ...notConnected,
            },
        ],
    },
    recent_orders: [
        {
            id: 126,
            number: 'TMB-26-00126',
            status: 'on_hold',
            grand_total: '6260.00',
            placed_at: '2026-11-08T08:00:00Z',
        },
    ],
};
export const row: QueueRow = {
    id: 126,
    number: 'TMB-26-00126',
    placed_at: '2026-11-08T08:00:00Z',
    grand_total: '6260.00',
    payment_method: 'cod',
    customer_name: 'শাহরিয়ার কবির',
    phone: '+8801712046318',
    district: 'Dhaka',
    order_risk: {
        score: 80,
        level: 'high',
        verification_status: 'pending',
        hold_reasons: ['high_risk_score', 'first_order_above_threshold'],
        signals: [
            {
                code: 'cancellation_rate',
                weight: 25,
                value: '4/7',
                contribution: 25,
            },
        ],
        computed_at: '2026-11-08T08:00:00Z',
    },
    ours,
    status: { value: 'normal', reason: null, set_by: null, set_at: null },
    calls: { count: 0, last_outcome: null },
};
export const detail: HeldOrderDetail = {
    order: row,
    call_log: [],
    phone: phoneRisk,
};
export const rules: RiskRules = {
    weights: {
        cancellation_rate: 25,
        courier_return_rate: 25,
        failed_delivery: 15,
        first_order: 10,
        value_above_norm: 10,
        address_quality: 5,
        rapid_repeat: 5,
    },
    medium_threshold: 40,
    high_threshold: 70,
    first_order_hold_threshold: '3000.00',
    value_norm_multiplier: '2',
    rapid_repeat_minutes: 10,
    can_edit: true,
};

export function riskApi(me: Me, handler?: (url: string, init?: RequestInit) => { status: number; body?: unknown } | undefined) {
    return mockApi((url, init) => {
        const custom = handler?.(url, init);
        if (custom) return custom;
        if (url.endsWith('/auth/me')) return { status: 200, body: me };
        if (url.includes('/risk/queue'))
            return {
                status: 200,
                body: {
                    data: [row],
                    links: {},
                    meta: { next_cursor: null, count: 1 },
                },
            };
        if (/\/risk\/orders\/126$/.test(url)) return { status: 200, body: detail };
        if (url.includes('/risk/lookup')) return { status: 200, body: phoneRisk };
        if (url.endsWith('/risk/rules')) return { status: 200, body: rules };
        if (url.includes('/risk/orders/126/'))
            return {
                status: 200,
                body: {
                    id: 126,
                    status: 'pending',
                    verification_status: 'verified',
                },
            };
        return { status: 404 };
    });
}
