import { cleanup, fireEvent, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { renderApp } from '@/test/renderApp';
import { formatMoney } from './parts';
import { inspected, operator, panel, refund, requested, returnsApi } from './fixtures';

afterEach(() => {
    cleanup();
    localStorage.clear();
});

const posts = (calls: ReturnType<typeof returnsApi>) =>
    calls.mock.calls
        .filter(([, init]) => (init as RequestInit | undefined)?.method === 'POST')
        .map(([url, init]) => ({ url: String(url), body: JSON.parse(String((init as RequestInit).body ?? 'null')) as unknown, headers: (init as RequestInit).headers as Record<string, string> }));

describe('returns queue', () => {
    it('shows server counts per tab and the queue row, filtering on the server', async () => {
        const calls = returnsApi(operator);
        renderApp('/returns');

        const table = await screen.findByRole('table');
        expect(within(table).getByText('RMA-26-00042')).toBeTruthy();
        expect(within(table).getByText('TMB-26-00120')).toBeTruthy();
        expect(within(table).getByText('রাকিবুল হাসান')).toBeTruthy();
        expect(within(table).getByText('Damaged in transit')).toBeTruthy();
        expect(within(table).getByText('Refund')).toBeTruthy();
        expect(screen.getByRole('button', { name: /^Refund pending\s*1$/ })).toBeTruthy();
        expect(screen.getByRole('button', { name: /^Closed\s*3$/ })).toBeTruthy();

        fireEvent.click(screen.getByRole('button', { name: /^Refund pending\s*1$/ }));
        await waitFor(() => expect(calls.mock.calls.some(([u]) => String(u).includes('filter%5Bstatus%5D=inspected'))).toBe(true));
    });

    it('inspects a return with resellable and damaged counts that add up', async () => {
        const calls = returnsApi(operator, (url, init) => (init?.method === 'POST' && url.endsWith('/returns/42/inspect') ? { status: 200, body: inspected } : undefined));
        renderApp('/returns?rma=42');

        await screen.findByRole('heading', { name: 'RMA-26-00042 · Refund' });
        const damaged = screen.getByRole('spinbutton', { name: 'Damaged units of Brick Box 500' });
        fireEvent.change(damaged, { target: { value: '1' } });
        expect((screen.getByRole('spinbutton', { name: 'Resellable units of Brick Box 500' }) as HTMLInputElement).value).toBe('1');

        fireEvent.click(screen.getByRole('button', { name: 'Inspect & restock' }));
        await waitFor(() => expect(posts(calls)).toHaveLength(1));
        expect(posts(calls)[0]).toMatchObject({ url: '/api/v1/admin/returns/42/inspect', body: { items: [{ order_item_id: 501, resellable: 1, damaged: 1 }], note: null } });
    });

    it('records a hand-paid refund with method, reference and an Idempotency-Key — the amount is the server’s', async () => {
        const calls = returnsApi(operator, (url, init) => {
            if (/\/returns\/42$/.test(url)) return { status: 200, body: inspected };
            if (init?.method === 'POST' && url.endsWith('/refunds/31/pay')) return { status: 200, body: { ...refund, status: 'completed', method: 'bkash', gateway_reference: 'BK8XYZ' } };
            return undefined;
        });
        renderApp('/returns?tab=inspected&rma=42');

        await screen.findByRole('heading', { name: 'RMA-26-00042 · Refund' });
        expect(screen.getByText('1 restocked · 1 written off')).toBeTruthy();
        expect(screen.getByText('Approved — to pay')).toBeTruthy();
        const pay = screen.getByRole('button', { name: 'Record payout' }) as HTMLButtonElement;
        expect(pay.disabled).toBe(true); // a reference is required for bKash

        fireEvent.change(screen.getByRole('textbox', { name: /Transaction reference/ }), { target: { value: 'BK8XYZ' } });
        fireEvent.click(pay);
        await waitFor(() => expect(posts(calls)).toHaveLength(1));
        const sent = posts(calls)[0]!;
        expect(sent.url).toBe('/api/v1/admin/refunds/31/pay');
        expect(sent.body).toEqual({ method: 'bkash', reference: 'BK8XYZ' });
        expect(sent.headers['Idempotency-Key']).toMatch(/^[0-9a-f-]{36}$/);
    });

    it('flags a refund above the threshold instead of offering approval', async () => {
        returnsApi(operator, (url) =>
            /\/returns\/42$/.test(url) ? { status: 200, body: { ...inspected, refund: { ...refund, status: 'requested', amount: '2500.00', needs_manager: true, can: { approve: false, pay: false } } } } : undefined,
        );
        renderApp('/returns?tab=inspected&rma=42');

        await screen.findByText('Above ৳2,000: a manager or accountant approves this refund.');
        expect(screen.queryByRole('button', { name: 'Approve refund' })).toBeNull();
        expect(screen.queryByRole('button', { name: 'Record payout' })).toBeNull();
    });
});

describe('order refund panel', () => {
    it('opens a return from the order with only quantities, reason and the delivery choice', async () => {
        const calls = returnsApi(operator, (url, init) => {
            if (url.endsWith('/orders/120/returns') && init?.method === 'POST') return { status: 201, body: requested };
            if (url.endsWith('/orders/120/returns')) return { status: 200, body: panel };
            if (url.endsWith('/orders/120')) return { status: 404 };
            return undefined;
        });
        const { OrderRefundPanel } = await import('./index');
        const { render } = await import('@testing-library/react');
        const { QueryClientProvider } = await import('@tanstack/react-query');
        const { createQueryClient } = await import('@/lib/queryClient');
        const { I18nProvider } = await import('@/i18n/I18nProvider');
        const { ToastProvider } = await import('@/components/ui/Toast');
        const { MemoryRouter } = await import('react-router');
        render(
            <QueryClientProvider client={createQueryClient()}>
                <I18nProvider initialLocale="en">
                    <ToastProvider>
                        <MemoryRouter>
                            <OrderRefundPanel orderId={120} />
                        </MemoryRouter>
                    </ToastProvider>
                </I18nProvider>
            </QueryClientProvider>,
        );

        await screen.findByText('Captured ৳3,070 · still refundable ৳3,070');
        fireEvent.click(screen.getByRole('button', { name: 'Request a return' }));
        expect(screen.getByText('2 of 2 can come back')).toBeTruthy();
        fireEvent.change(screen.getByRole('spinbutton', { name: 'Units of Brick Box 500 to return' }), { target: { value: '1' } });
        fireEvent.click(screen.getByRole('checkbox', { name: 'Also refund the delivery charge' }));
        fireEvent.click(screen.getByRole('button', { name: 'Open return' }));

        await waitFor(() => expect(posts(calls)).toHaveLength(1));
        expect(posts(calls)[0]).toMatchObject({
            url: '/api/v1/admin/orders/120/returns',
            body: { items: [{ order_item_id: 501, quantity: 1 }], reason: 'defective', reason_note: null, refund_shipping: true },
        });
    });

    it('formats the server’s decimal without arithmetic', () => {
        expect(formatMoney('3070.00')).toBe('3,070');
        expect(formatMoney('1250.50')).toBe('1,250.5');
    });
});
