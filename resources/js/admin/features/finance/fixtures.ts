/**
 * Test fixtures for the finance screens (Vitest only). Bangla names in every fixture (rule 34).
 */
import { mockApi, viewer } from '@/test/renderApp';
import type { Me } from '@/features/auth/api';
import type { Expense, ExpenseCategory, ExpenseSummary, LedgerEntry, Receivables } from './api';

type Reply = { status: number; body?: unknown };

export const accountant: Me = {
    ...viewer,
    roles: ['accountant'],
    permissions: ['dashboard.view', 'finance.view', 'finance.manage_expenses', 'finance.reconcile_cod', 'reports.view'],
};

/** A manager who did not enter the fixture expense — may approve it. */
export const manager: Me = { ...accountant, id: 9, roles: ['manager'] };

export const rent: ExpenseCategory = {
    id: 1,
    name: 'Rent',
    name_bn: 'ভাড়া',
    type: 'operating',
    ledger_type: 'rent',
    resolved_ledger_type: 'rent',
    is_fixed: true,
    is_active: true,
    parent_id: null,
    expenses_count: 1,
};

export const ads: ExpenseCategory = { ...rent, id: 2, name: 'Facebook ads', name_bn: 'ফেসবুক বিজ্ঞাপন', type: 'marketing', ledger_type: 'marketing', resolved_ledger_type: 'marketing', is_fixed: false };

export const pendingRent: Expense = {
    id: 41,
    category: rent,
    amount: '35000.00',
    expense_date: '2026-11-01',
    status: 'pending',
    payment_method: 'bank_transfer',
    paid_on: '2026-11-01',
    reference: 'RENT-NOV',
    vendor: 'মিরপুর গুদাম মালিক',
    description: 'Mirpur warehouse rent · November',
    receipt: { name: 'rent-nov.pdf', mime: 'application/pdf', size: 20480 },
    is_recurring: true,
    recurrence: 'monthly',
    recurrence_parent_id: null,
    recurrence_period: null,
    order_id: null,
    shipment_id: null,
    created_by: 7,
    approved_by: null,
    approved_at: null,
    rejected_reason: null,
    voided_at: null,
    void_reason: null,
    created_at: '2026-11-01T04:00:00Z',
    can: { update: true, delete: true, approve: true, reject: true, void: false, pay: false },
};

export const summary: ExpenseSummary = {
    from: '2026-11-02',
    to: '2026-11-08',
    period_total: '1900.00',
    period_top: [
        { category_id: 2, name: 'Facebook ads', name_bn: 'ফেসবুক বিজ্ঞাপন', amount: '1400.00' },
        { category_id: 3, name: 'SMS', name_bn: 'এসএমএস', amount: '500.00' },
    ],
    month_to_date: '3100.00',
    recurring_monthly: '68400.00',
    ad_spend_ratio: '10.10',
    pending_count: 1,
};

export const saleRevenue: LedgerEntry = {
    id: 501,
    type: 'sale_revenue',
    direction: 'in',
    amount: '1200.00',
    occurred_on: '2026-11-07',
    source_type: 'order',
    source_id: 1042,
    category_id: null,
    category_name: null,
    category_name_bn: null,
    description: 'Order TMB-26-01042 delivered',
    is_cash_movement: false,
    is_recognised_revenue: true,
    created_at: '2026-11-07T10:00:00Z',
};

export const codReceived: LedgerEntry = { ...saleRevenue, id: 502, type: 'cod_received', amount: '1000.00', source_type: 'manual_cod_receipt', source_id: 3, is_cash_movement: true, is_recognised_revenue: false, description: 'Remitted by Steadfast' };

export const receivables: Receivables = {
    as_of: '2026-11-08',
    recognised: '1170.00',
    received: '1000.00',
    outstanding: '170.00',
    open_orders: 1,
    aging: { '0_7': '170.00', '8_15': '0.00', '16_30': '0.00', '31_plus': '0.00' },
};

const page = <T,>(data: T[]) => ({ data, links: {}, meta: { next_cursor: null } });

export function financeApi(me: Me, handler?: (url: string, init?: RequestInit) => Reply | undefined) {
    return mockApi((url, init) => {
        const custom = handler?.(url, init);
        if (custom) return custom;
        if (url.endsWith('/auth/me')) return { status: 200, body: me };
        if (url.includes('/finance/expenses/summary')) return { status: 200, body: summary };
        if (url.includes('/finance/expense-categories')) return { status: 200, body: { data: [rent, ads] } };
        if (url.match(/\/finance\/expenses\/\d+\/approve$/)) return { status: 200, body: { ...pendingRent, status: 'approved' } };
        if (url.match(/\/finance\/expenses\/\d+\/reject$/)) return { status: 200, body: { ...pendingRent, status: 'rejected' } };
        if (url.endsWith('/finance/expenses') && init?.method === 'POST') return { status: 201, body: pendingRent };
        if (url.includes('/finance/expenses')) return { status: 200, body: page([pendingRent]) };
        if (url.includes('/finance/receivables')) return { status: 200, body: receivables };
        if (url.includes('/cod-receipts')) return { status: 201, body: { id: 3, order_id: 1042, amount: '100.00', received_on: '2026-11-08', reference: null, note: null, order_outstanding: '70.00' } };
        if (url.includes('/finance/ledger')) return { status: 200, body: page([saleRevenue, codReceived]) };
        return { status: 404 };
    });
}
