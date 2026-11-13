/**
 * Test fixtures for the inventory screens (Vitest only). Bangla names in every fixture (rule 34).
 */
import { mockApi, viewer } from '@/test/renderApp';
import type { Me } from '@/features/auth/api';
import type { Adjustment, InventoryAlert, InventorySummary, Movement, Reconciliation, StockRow, Transfer } from './api';

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
    reorder_quantity: 12,
    state: 'low',
    unit_cost: '310.0000',
    value: '2480.00',
    elsewhere: [],
    transfer_from: null,
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
    reorder_quantity: 6,
    state: null,
    unit_cost: '1060.0000',
    value: '26500.00',
};

export const summary: InventorySummary = {
    location_id: 1,
    counts: { all: 2, low: 1, out: 0, overstock: 0, idle: 0 },
    on_hand: 33,
    reserved: 2,
    value: '28980.00',
    total_value: '28980.00',
    locations: [
        {
            id: 1,
            code: 'dhk',
            name: 'Dhaka HQ · Mirpur',
            name_bn: 'ঢাকা এইচকিউ · মিরপুর',
            description: 'Main warehouse · online orders',
            description_bn: 'প্রধান গুদাম · অনলাইন অর্ডার',
            is_default: true,
            on_hand: 33,
            value: '28980.00',
        },
    ],
};

/** Three branches (Figma B · 14): Chattogram holds 4 of the low puzzle. */
export const ctg = { id: 2, code: 'ctg', name: 'Chattogram · Agrabad', name_bn: 'চট্টগ্রাম · আগ্রাবাদ', description: 'Retail branch · POS + pickup', description_bn: 'খুচরা শাখা · POS + পিকআপ', is_default: false, on_hand: 74, value: '38210.00' };
export const syl = { id: 3, code: 'syl', name: 'Sylhet pickup point', name_bn: 'সিলেট পিকআপ পয়েন্ট', description: 'Pickup only · no POS', description_bn: 'শুধু পিকআপ · POS নেই', is_default: false, on_hand: 19, value: '9369.00' };

export const branchSummary: InventorySummary = {
    ...summary,
    counts: { all: 2, low: 1, out: 0, overstock: 5, idle: 11 },
    total_value: '76559.00',
    locations: [...summary.locations, ctg, syl],
};

export const branchRows: StockRow[] = [
    {
        ...puzzle,
        elsewhere: [
            { location_id: 2, code: 'ctg', on_hand: 4, available: 4 },
            { location_id: 3, code: 'syl', on_hand: 0, available: 0 },
        ],
        transfer_from: { location_id: 2, code: 'ctg', available: 4 },
    },
    {
        ...blaster,
        elsewhere: [
            { location_id: 2, code: 'ctg', on_hand: 1, available: 0 },
            { location_id: 3, code: 'syl', on_hand: 0, available: 0 },
        ],
    },
];

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
    adjustment_reason: 'damage',
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
    location_code: 'main',
    reason: 'damage',
    note: null,
    status: 'applied',
    moved_stock: true,
    requested_units: 2,
    requested_value: '620.00',
    requested_by: 'Rina',
    approved_by: null,
    approved_at: null,
    rejected_by: null,
    rejected_at: null,
    decision_note: null,
    created_at: '2026-11-08T06:20:00Z',
    items: [
        {
            variant_id: 11,
            sku: 'TMB-WAP-BN',
            product_name: 'Wooden Alphabet Puzzle BN+EN',
            product_name_bn: 'কাঠের বর্ণমালা পাজল',
            variant_name: null,
            image_url: null,
            requested_change: -2,
            counted: null,
            quantity_before: 8,
            quantity_after: 6,
            change: -2,
            unit_cost: '310.0000',
        },
    ],
};

export const pendingAdjustment: Adjustment = {
    ...adjustment,
    id: 13,
    number: 'ADJ-2026-0013',
    reason: 'theft',
    status: 'pending',
    moved_stock: false,
    requested_units: 30,
    requested_value: '9300.00',
    items: [{ ...adjustment.items[0]!, requested_change: -30, quantity_after: -22, change: -30 }],
};

export const driftAlert: InventoryAlert = {
    id: 7,
    kind: 'ledger_drift',
    status: 'open',
    variant_id: 11,
    product_id: 1,
    sku: 'TMB-WAP-BN',
    product_name: 'Wooden Alphabet Puzzle BN+EN',
    product_name_bn: 'কাঠের বর্ণমালা পাজল',
    variant_name: null,
    image_url: null,
    location_id: 1,
    location_code: 'main',
    expected: 12,
    actual: 9,
    difference: -3,
    alert_date: '2026-11-13',
    first_seen_at: '2026-11-12T21:30:00Z',
    last_seen_at: '2026-11-12T21:30:00Z',
    acknowledged_by: null,
    acknowledged_at: null,
    note: null,
};

export const transferDone: Transfer = {
    id: 3,
    number: 'TRF-2026-0003',
    from_location_id: 2,
    from_location_code: 'ctg',
    to_location_id: 1,
    to_location_code: 'main',
    status: 'completed',
    note: null,
    user_name: 'Rina',
    created_at: '2026-11-12T06:00:00Z',
    items: [{ variant_id: 11, sku: 'TMB-WAP-BN', product_name: 'Wooden Alphabet Puzzle BN+EN', product_name_bn: null, variant_name: null, quantity: 4 }],
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
        if (url.includes('/inventory/low-stock-count')) return { status: 200, body: { location_id: 1, count: 1 } };
        if (url.includes('/inventory/stock')) return { status: 200, body: page([puzzle, blaster].map((r) => (cost ? r : costless(r, ['unit_cost', 'value'])))) };
        if (url.includes('/inventory/movements/reconciliation')) return { status: 200, body: cost ? reconciliation : costless(reconciliation, ['stock_value']) };
        if (url.includes('/inventory/movements')) return { status: 200, body: page([cost ? movement : costless(movement, ['unit_cost'])]) };
        if (url.endsWith('/inventory/adjustments') && init?.method === 'POST') return { status: 201, body: adjustment };
        if (url.includes('/inventory/adjustments/') && url.endsWith('/approve')) return { status: 200, body: { ...pendingAdjustment, status: 'approved', moved_stock: true } };
        if (url.includes('/inventory/adjustments/') && url.endsWith('/reject')) return { status: 200, body: { ...pendingAdjustment, status: 'rejected' } };
        if (url.includes('/inventory/adjustments')) return { status: 200, body: { ...page([pendingAdjustment]), meta: { next_cursor: null, counts: { pending: 1 } } } };
        if (url.endsWith('/inventory/transfers') && init?.method === 'POST') return { status: 201, body: transferDone };
        if (url.includes('/acknowledge')) return { status: 200, body: { ...driftAlert, status: 'acknowledged' } };
        if (url.includes('/inventory/alerts'))
            return { status: 200, body: { ...page([driftAlert]), meta: { next_cursor: null, counts: { ledger_drift: 1, reserved_drift: 0, out_of_stock: 0, low_stock: 0 } } } };
        return { status: 404 };
    });
}

export function sentBody(calls: ReturnType<typeof mockApi>): { body: Record<string, unknown>; headers: Record<string, string> } | null {
    const call = calls.mock.calls.find(([, init]) => (init as RequestInit | undefined)?.method === 'POST');
    if (!call) return null;
    const init = call[1] as RequestInit;
    return { body: JSON.parse(String(init.body)) as Record<string, unknown>, headers: init.headers as Record<string, string> };
}
