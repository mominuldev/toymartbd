/**
 * Test fixtures for the inventory screens (Vitest only). Bangla names in every fixture (rule 34).
 */
import { mockApi, viewer } from '@/test/renderApp';
import type { Me } from '@/features/auth/api';
import type { Adjustment, InventorySummary, Movement, Reconciliation, StockRow } from './api';

export const inventoryStaff: Me = {
    ...viewer,
    roles: ['inventory_staff'],
    permissions: ['dashboard.view', 'catalog.view', 'catalog.view_cost', 'inventory.view', 'inventory.adjust'],
};

/** Order operator: sees units, never cost, cannot adjust. */
export const operator: Me = {
    ...viewer,
    roles: ['order_operator'],
    permissions: ['dashboard.view', 'catalog.view', 'inventory.view'],
};

export const puzzle: StockRow = {
    variant_id: 11,
    product_id: 1,
    product_name: 'Wooden Alphabet Puzzle',
    product_name_bn: 'কাঠের বর্ণমালা পাজল',
    variant_name: null,
    variant_name_bn: null,
    sku: 'TMB-WAP-BN',
    image_url: null,
    on_hand: 8,
    reserved: 2,
    available: 6,
    reorder_point: 12,
    state: 'low',
    unit_cost: '310.0000',
    value: '2480.00',
    elsewhere: [],
};

export const blaster: StockRow = {
    ...puzzle,
    variant_id: 12,
    product_id: 2,
    product_name: 'Foam Blaster X2',
    product_name_bn: 'ফোম ব্লাস্টার X2',
    sku: 'TMB-FBL-X2',
    on_hand: 25,
    reserved: 0,
    available: 25,
    reorder_point: 6,
    state: null,
    unit_cost: '1060.0000',
    value: '26500.00',
};

export const summary: InventorySummary = {
    location_id: 1,
    counts: { all: 2, low: 1, out: 0, idle: 0 },
    on_hand: 33,
    reserved: 2,
    value: '28980.00',
    total_value: '28980.00',
    locations: [{ id: 1, code: 'main', name: 'Dhaka HQ · Mirpur', name_bn: 'ঢাকা প্রধান · মিরপুর', is_default: true, on_hand: 33, value: '28980.00' }],
};

export const movement: Movement = {
    id: 501,
    created_at: '2026-11-08T06:20:00Z',
    location_id: 1,
    variant_id: 12,
    product_id: 2,
    product_name: 'Foam Blaster X2',
    product_name_bn: 'ফোম ব্লাস্টার X2',
    variant_name: null,
    variant_name_bn: null,
    sku: 'TMB-FBL-X2',
    image_url: null,
    type: 'damage_out',
    quantity: -2,
    balance_after: 25,
    unit_cost: '1060.0000',
    reference_type: 'stock_adjustment',
    reference_id: 12,
    reference_number: 'ADJ-2026-0012',
    user_name: 'Mominul',
    note: null,
};

export const reconciliation: Reconciliation = {
    opening: 210,
    in: 26,
    out: -20,
    closing: 216,
    variants_checked: 10,
    drift_count: 0,
    drift_variant_ids: [],
    balanced: true,
    stock_value: '136741.00',
};

export const adjustment: Adjustment = {
    id: 12,
    number: 'ADJ-2026-0012',
    location_id: 1,
    reason: 'damage',
    note: null,
    status: 'applied',
    created_at: '2026-11-08T06:20:00Z',
    items: [{ variant_id: 11, quantity_before: 8, quantity_after: 6, change: -2, unit_cost: '310.0000' }],
};

type Reply = { status: number; body?: unknown };

const page = <T>(data: T[]) => ({ data, links: { next: null }, meta: { next_cursor: null } });

/** Strip what the API omits without catalog.view_cost. */
function costless<T extends object>(value: T, keys: string[]): T {
    return Object.fromEntries(Object.entries(value).filter(([k]) => !keys.includes(k))) as T;
}

/** The inventory API with sensible defaults; `handler` answers first. */
export function inventoryApi(me: Me, handler?: (url: string, init?: RequestInit) => Reply | undefined) {
    const cost = me.permissions.includes('catalog.view_cost');
    return mockApi((url, init) => {
        const custom = handler?.(url, init);
        if (custom) return custom;
        if (url.endsWith('/auth/me')) return { status: 200, body: me };
        if (url.includes('/inventory/summary')) return { status: 200, body: cost ? summary : { ...costless(summary, ['value', 'total_value']), locations: summary.locations.map((l) => costless(l, ['value'])) } };
        if (url.includes('/inventory/stock')) return { status: 200, body: page([puzzle, blaster].map((r) => (cost ? r : costless(r, ['unit_cost', 'value'])))) };
        if (url.includes('/inventory/movements/reconciliation')) return { status: 200, body: cost ? reconciliation : costless(reconciliation, ['stock_value']) };
        if (url.includes('/inventory/movements')) return { status: 200, body: page([cost ? movement : costless(movement, ['unit_cost'])]) };
        if (url.endsWith('/inventory/adjustments') && init?.method === 'POST') return { status: 201, body: adjustment };
        return { status: 404 };
    });
}

export function sentBody(calls: ReturnType<typeof mockApi>): { body: Record<string, unknown>; headers: Record<string, string> } | null {
    const call = calls.mock.calls.find(([, init]) => (init as RequestInit | undefined)?.method === 'POST');
    if (!call) return null;
    const init = call[1] as RequestInit;
    return { body: JSON.parse(String(init.body)) as Record<string, unknown>, headers: init.headers as Record<string, string> };
}
