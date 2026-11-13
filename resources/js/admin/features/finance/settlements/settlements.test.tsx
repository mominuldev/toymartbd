import { cleanup, fireEvent, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import type { Me } from '@/features/auth/api';
import { mockApi, renderApp, viewer } from '@/test/renderApp';
import type { Settlement } from './api';

afterEach(() => {
    cleanup();
    localStorage.clear();
});

const accountant: Me = { ...viewer, roles: ['accountant'], permissions: ['dashboard.view', 'finance.view', 'finance.reconcile_cod', 'reports.view'] };

const base: Settlement = {
    id: 46,
    provider: { code: 'pathao', name: 'Pathao', name_bn: 'পাঠাও' },
    reference: 'PTH-STL-2026-46',
    period_start: '2026-11-07',
    period_end: '2026-11-08',
    received_at: '2026-11-10',
    totals: { cod_collected: '2330.00', delivery_charges: '260.00', cod_fees: '23.40', return_charges: '0.00', net_received: '2046.60' },
    status: 'disputed',
    source: 'csv',
    variance_count: 1,
    unresolved_count: 1,
    note: null,
    imported_by: 'Rina Akter',
    confirmed_by: null,
    confirmed_at: null,
    created_at: '2026-11-10T04:00:00Z',
    can_reconcile: true,
    items: [
        { id: 1, line_no: 2, consignment_id: 'DT261105NB8L5R', shipment_id: 9, order_id: 122, order_number: 'TMB-26-00122', cod_amount: '1170.00', charges: '130.00', cod_fee: '11.70', return_charge: '0.00', net: '1028.30', expected_cod: '1170.00', expected_net: '1028.30', variance: '0.00', variance_type: 'none', acceptable: false, is_matched: true, review_status: 'pending', review_note: null, needs_review: false, booked: false },
        { id: 2, line_no: 3, consignment_id: 'DT261103YV4B8X', shipment_id: 10, order_id: 119, order_number: 'TMB-26-00119', cod_amount: '1160.00', charges: '130.00', cod_fee: '11.70', return_charge: '0.00', net: '1018.30', expected_cod: '1170.00', expected_net: '1028.30', variance: '-10.00', variance_type: 'short', acceptable: true, is_matched: true, review_status: 'pending', review_note: null, needs_review: true, booked: false },
    ],
};
const receivables = { as_of: '2026-11-10', recognised: '5000.00', received: '4213.00', outstanding: '787.00', open_orders: 3, aging: { '0_7': '787.00', '8_15': '0.00', '16_30': '0.00', '31_plus': '0.00' } };

function api(settlement: Settlement = base) {
    return mockApi((url) => {
        if (url.endsWith('/auth/me')) return { status: 200, body: accountant };
        if (url.includes('/finance/receivables')) return { status: 200, body: receivables };
        if (/\/finance\/settlements\/46\/(items|confirm)/.test(url)) return { status: 200, body: { ...settlement, status: 'reconciled', unresolved_count: 0 } };
        if (/\/finance\/settlements\/46$/.test(url)) return { status: 200, body: settlement };
        if (url.includes('/finance/settlements')) return { status: 200, body: { data: [settlement], links: {}, meta: { next_cursor: null, providers: [{ code: 'pathao', name: 'Pathao', name_bn: 'পাঠাও' }] } } };
        return { status: 404 };
    });
}

const posts = (calls: ReturnType<typeof api>) =>
    calls.mock.calls
        .filter(([, init]) => (init as RequestInit | undefined)?.method === 'POST')
        .map(([url, init]) => ({ url: String(url), body: (init as RequestInit).body, headers: (init as RequestInit).headers as Record<string, string> }));

describe('COD settlements', () => {
    it('shows each line beside what we expected, flags the deliberate variance, and blocks reconcile until a person decides', async () => {
        const calls = api();
        renderApp('/finance/settlements');

        expect(await screen.findByText('Short by ৳10')).toBeTruthy();
        expect(screen.getByText('Matches our record')).toBeTruthy();
        expect(screen.getAllByText('PTH-STL-2026-46').length).toBeGreaterThan(0);
        const reconcile = screen.getByRole('button', { name: 'Reconcile PTH-STL-2026-46' }) as HTMLButtonElement;
        expect(reconcile.disabled).toBe(true);

        fireEvent.click(screen.getByRole('button', { name: 'Accept variance' }));
        await waitFor(() => expect(posts(calls).some((p) => p.url.endsWith('/finance/settlements/46/items/2/review'))).toBe(true));
        expect(JSON.parse(String(posts(calls)[0]!.body))).toEqual({ decision: 'accepted', note: null });
    });

    it('reconciles with an Idempotency-Key once every variance has a decision', async () => {
        const reviewed: Settlement = { ...base, status: 'received', unresolved_count: 0, items: base.items!.map((i) => (i.id === 2 ? { ...i, review_status: 'accepted', needs_review: false } : i)) };
        const calls = api(reviewed);
        renderApp('/finance/settlements?id=46');

        const reconcile = (await screen.findByRole('button', { name: 'Reconcile PTH-STL-2026-46' })) as HTMLButtonElement;
        expect(reconcile.disabled).toBe(false);
        fireEvent.click(reconcile);
        await waitFor(() => expect(posts(calls).some((p) => p.url.endsWith('/confirm'))).toBe(true));
        expect(posts(calls).find((p) => p.url.endsWith('/confirm'))!.headers['Idempotency-Key']).toMatch(/^[0-9a-f-]{36}$/);
    });

    it('needs a reason to exclude a line', async () => {
        const calls = api();
        renderApp('/finance/settlements');
        fireEvent.click(await screen.findByRole('button', { name: 'Exclude' }));
        const row = screen.getByRole('textbox', { name: 'Why exclude?' }).closest('form')!;
        expect((within(row).getByRole('button', { name: 'Exclude' }) as HTMLButtonElement).disabled).toBe(true);
        fireEvent.change(screen.getByRole('textbox', { name: 'Why exclude?' }), { target: { value: 'পাঠাওকে জানানো হয়েছে' } });
        fireEvent.click(within(row).getByRole('button', { name: 'Exclude' }));
        await waitFor(() => expect(posts(calls)).toHaveLength(1));
        expect(JSON.parse(String(posts(calls)[0]!.body))).toEqual({ decision: 'excluded', note: 'পাঠাওকে জানানো হয়েছে' });
    });
});
