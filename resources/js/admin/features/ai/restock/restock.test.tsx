import { cleanup, fireEvent, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { mockApi, renderApp, viewer } from '@/test/renderApp';
import type { Me } from '@/features/auth/api';
import type { RestockForecast, RestockRow } from './api';

/** Inventory staff: sees cost and may raise purchase orders. */
const staff: Me = {
    ...viewer,
    roles: ['inventory_staff'],
    permissions: ['dashboard.view', 'catalog.view', 'catalog.view_cost', 'inventory.view', 'purchasing.view', 'purchasing.create'],
};
/** Order operator: units only, no cost, no purchasing. */
const operator: Me = { ...viewer, roles: ['order_operator'], permissions: ['dashboard.view', 'catalog.view', 'inventory.view'] };

const tricycle: RestockRow = {
    variant_id: 21,
    product_id: 4,
    product_name: 'Kids Tricycle',
    product_name_bn: 'বাচ্চাদের ট্রাইসাইকেল',
    variant_name: 'Red',
    variant_name_bn: 'লাল',
    sku: 'TRI-RED',
    in_stock: 12,
    units_sold: 120,
    sells_per_day: '4.00',
    runs_out_in_days: 3,
    lead_time_days: 5,
    lead_time_estimated: false,
    open_po_quantity: 10,
    suggested_order: 54,
    units_short: 34,
    confidence: 'high',
    price: '1250.00',
    sales_at_risk: '42500.00',
    supplier: { id: 3, name: 'বংশাল কিডস · Bangshal Kids' },
    last_unit_cost: '700.5000',
    suggested_order_value: '37827.00',
};
const teddy: RestockRow = {
    ...tricycle,
    variant_id: 22,
    product_id: 5,
    product_name: 'Plush Teddy Bear 45 cm',
    product_name_bn: 'টেডি বিয়ার 45 সেমি',
    variant_name: null,
    variant_name_bn: null,
    sku: 'TED-45',
    in_stock: 18,
    sells_per_day: '2.60',
    runs_out_in_days: 9,
    lead_time_days: 8,
    open_po_quantity: 0,
    suggested_order: 60,
    confidence: 'medium',
    last_unit_cost: '420.0000',
    suggested_order_value: '25200.00',
};
const crayons: RestockRow = {
    ...tricycle,
    variant_id: 23,
    product_id: 6,
    product_name: 'Crayons 24 colours',
    product_name_bn: null,
    variant_name: null,
    variant_name_bn: null,
    sku: 'CRY-24',
    runs_out_in_days: 14,
    lead_time_days: 7,
    lead_time_estimated: true,
    open_po_quantity: 0,
    suggested_order: 20,
    confidence: 'low',
    supplier: null,
    last_unit_cost: null,
    suggested_order_value: null,
};

const forecast: RestockForecast = {
    horizon: 14,
    today: '2026-11-16',
    window: { from: '2026-10-18', to: '2026-11-16', days: 30 },
    kpis: { running_out: 9, before_arrival: 6, sales_at_risk: '112400.00', suggested_order_value: '84600.00' },
    rows: [tricycle, teddy, crayons],
    note: null,
    note_blocked: null,
};

type Reply = { status: number; body?: unknown };

function restockApi(me: Me, handler?: (url: string, init?: RequestInit) => Reply | undefined) {
    const cost = me.permissions.includes('catalog.view_cost');
    const body: RestockForecast = cost
        ? forecast
        : { ...forecast, kpis: { ...forecast.kpis, suggested_order_value: null }, rows: forecast.rows.map((r) => ({ ...r, last_unit_cost: null, suggested_order_value: null })) };
    return mockApi((url, init) => {
        const custom = handler?.(url, init);
        if (custom) return custom;
        if (url.endsWith('/auth/me')) return { status: 200, body: me };
        if (url.includes('/inventory/low-stock-count')) return { status: 200, body: { location_id: 1, count: 0 } };
        if (url.includes('/inventory/restock?')) return { status: 200, body };
        return { status: 404 };
    });
}

const posts = (calls: ReturnType<typeof mockApi>, path: string) =>
    calls.mock.calls.filter(([url, init]) => String(url).endsWith(path) && (init as RequestInit | undefined)?.method === 'POST').map(([, init]) => JSON.parse(String((init as RequestInit).body)) as unknown);

afterEach(() => {
    cleanup();
    localStorage.clear();
});

describe('restock forecast', () => {
    it('shows the server’s KPIs and rows, Western digits and lakh grouping', async () => {
        restockApi(staff);
        renderApp('/inventory/restock');

        const table = await screen.findByRole('table', { name: 'Products to restock' });
        expect(screen.getByRole('heading', { name: 'Restock forecast' })).toBeTruthy();
        expect(screen.getByText('9 products')).toBeTruthy();
        expect(screen.getByText('before new stock can arrive: 6')).toBeTruthy();
        expect(screen.getAllByText((_, el) => el?.tagName === 'SPAN' && el.textContent === '৳1,12,400').length).toBeGreaterThan(0);
        expect(screen.getAllByText((_, el) => el?.tagName === 'SPAN' && el.textContent === '৳84,600').length).toBeGreaterThan(0);

        const row = within(table).getByText('Kids Tricycle · Red').closest('tr') as HTMLElement;
        expect(within(row).getByText('TRI-RED')).toBeTruthy();
        expect(within(row).getByText('4.00')).toBeTruthy();
        expect(within(row).getByText('in 3 days')).toBeTruthy();
        expect(within(row).getByText('5 days')).toBeTruthy();
        expect(within(row).getByText('10 on order')).toBeTruthy();
        expect(within(row).getByText('High')).toBeTruthy();
        expect((within(row).getByRole('spinbutton') as HTMLInputElement).value).toBe('54');
        const crayonRow = within(table).getByText('Crayons 24 colours').closest('tr') as HTMLElement;
        expect(within(crayonRow).getByText('(est.)')).toBeTruthy();
        expect(within(crayonRow).getByText('Low · few sales')).toBeTruthy();
        expect(screen.getByText(/covers sales until the stock arrives plus 14 days/)).toBeTruthy();
    });

    it('asks the server for another horizon', async () => {
        const calls = restockApi(staff);
        renderApp('/inventory/restock');
        await screen.findByRole('table');

        fireEvent.click(screen.getByRole('button', { name: 'Next 30 days' }));
        await waitFor(() => expect(calls.mock.calls.some(([url]) => String(url).endsWith('/inventory/restock?horizon=30'))).toBe(true));
        expect(calls.mock.calls.some(([url]) => String(url).endsWith('/inventory/restock?horizon=14'))).toBe(true);
    });

    it('posts the selected rows with edited quantities to the existing POST /purchases as a draft', async () => {
        const calls = restockApi(staff, (url, init) => (url.endsWith('/purchases') && init?.method === 'POST' ? { status: 201, body: { id: 77 } } : undefined));
        const { router } = renderApp('/inventory/restock');
        const table = await screen.findByRole('table');

        const create = screen.getByRole('button', { name: 'Create draft purchase order' }) as HTMLButtonElement;
        expect(create.disabled).toBe(true);
        fireEvent.click(within(table).getByRole('checkbox', { name: 'Select Kids Tricycle · Red' }));
        fireEvent.click(within(table).getByRole('checkbox', { name: 'Select Plush Teddy Bear 45 cm' }));
        fireEvent.change(within(table).getByRole('spinbutton', { name: 'Order quantity for Plush Teddy Bear 45 cm' }), { target: { value: '70' } });

        expect(screen.getByText('2 selected · 124 units')).toBeTruthy();
        expect(screen.getByText('Supplier: বংশাল কিডস · Bangshal Kids · last costs used')).toBeTruthy();
        fireEvent.click(create);

        await waitFor(() => expect(router.state.location.pathname).toBe('/purchasing/77'));
        expect(posts(calls, '/purchases')).toEqual([
            {
                supplier_id: 3,
                order_date: '2026-11-16',
                lines: [
                    { variant_id: 21, quantity: 54, unit_cost: '700.5000' },
                    { variant_id: 22, quantity: 70, unit_cost: '420.0000' },
                ],
            },
        ]);
    });

    it('refuses a draft for a never-bought product or a zero quantity', async () => {
        restockApi(staff);
        renderApp('/inventory/restock');
        const table = await screen.findByRole('table');
        const create = screen.getByRole('button', { name: 'Create draft purchase order' }) as HTMLButtonElement;

        fireEvent.click(within(table).getByRole('checkbox', { name: 'Select Crayons 24 colours' }));
        expect(screen.getByText(/were never bought/)).toBeTruthy();
        expect(create.disabled).toBe(true);

        fireEvent.click(within(table).getByRole('checkbox', { name: 'Select Crayons 24 colours' }));
        fireEvent.click(within(table).getByRole('checkbox', { name: 'Select Kids Tricycle · Red' }));
        fireEvent.change(within(table).getByRole('spinbutton', { name: 'Order quantity for Kids Tricycle · Red' }), { target: { value: '0' } });
        expect(screen.getByText(/order quantity of 1 or more/)).toBeTruthy();
        expect(create.disabled).toBe(true);
    });

    it('hides cost and the create action without the permissions', async () => {
        restockApi(operator);
        renderApp('/inventory/restock');
        const table = await screen.findByRole('table');

        expect(screen.getByText('Cost is hidden for your role')).toBeTruthy();
        fireEvent.click(within(table).getByRole('checkbox', { name: 'Select Kids Tricycle · Red' }));
        expect(screen.getByText('You cannot create purchase orders.')).toBeTruthy();
        expect((screen.getByRole('button', { name: 'Create draft purchase order' }) as HTMLButtonElement).disabled).toBe(true);
    });

    it('writes the AI note on request and shows who wrote it', async () => {
        const calls = restockApi(staff, (url, init) =>
            url.endsWith('/inventory/restock/note') && init?.method === 'POST'
                ? { status: 200, body: { sentences: ['Order Kids Tricycle · Red first: it runs out in 3 days.', 'The supplier takes 5.'], model: 'claude-opus-5-5', generated_at: '2026-11-16T03:00:00Z', omitted: null } }
                : undefined,
        );
        renderApp('/inventory/restock');
        await screen.findByRole('table');

        fireEvent.click(screen.getByRole('button', { name: 'Write it in plain words' }));
        expect(await screen.findByText('Order Kids Tricycle · Red first: it runs out in 3 days. The supplier takes 5.')).toBeTruthy();
        expect(screen.getByText(/Written by Claude Opus 5.5/)).toBeTruthy();
        expect(posts(calls, '/inventory/restock/note')).toEqual([{ horizon: '14' }]);
    });

    it('shows a calm notice when AI is not set up', async () => {
        restockApi(staff, (url) =>
            url.endsWith('/inventory/restock/note')
                ? { status: 409, body: { type: 'https://toymartbd.com/problems/ai-not-configured', title: 'AI is not set up', status: 409 } }
                : undefined,
        );
        renderApp('/inventory/restock');
        await screen.findByRole('table');

        fireEvent.click(screen.getByRole('button', { name: 'Write it in plain words' }));
        expect(await screen.findByText('AI is not set up for this')).toBeTruthy();
        expect(screen.getByRole('link', { name: 'Open AI settings' })).toBeTruthy();
        // The forecast itself is untouched.
        expect(screen.getByRole('table')).toBeTruthy();
    });

    it('explains why there is no note when figures may not be shared', async () => {
        restockApi(staff, (url) => (url.includes('/inventory/restock?') ? { status: 200, body: { ...forecast, note_blocked: 'figures_not_shared' } } : undefined));
        renderApp('/inventory/restock');
        await screen.findByRole('table');

        expect(screen.getByText(/AI may not see report figures/)).toBeTruthy();
        expect(screen.queryByRole('button', { name: 'Write it in plain words' })).toBeNull();
    });

    it('speaks Bangla with Western digits and Bangla product names', async () => {
        restockApi(staff);
        renderApp('/inventory/restock', { locale: 'bn' });
        const table = await screen.findByRole('table');

        expect(screen.getByText('9টি পণ্য')).toBeTruthy();
        expect(within(table).getByText('বাচ্চাদের ট্রাইসাইকেল · লাল')).toBeTruthy();
        expect(within(table).getByText('3 দিনে')).toBeTruthy();
        expect(screen.getAllByText((_, el) => el?.tagName === 'SPAN' && el.textContent === '৳1,12,400').length).toBeGreaterThan(0);
        expect(document.body.textContent ?? '').not.toMatch(/[০-৯]/);
    });
});
