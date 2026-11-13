import { cleanup, fireEvent, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import type { Me } from '@/features/auth/api';
import { renderApp } from '@/test/renderApp';
import { detail, operator, ordersApi, postedTo } from './fixtures';

/*
 * Manual fulfilment (P5, until Phase 6 courier booking): the buttons follow the server's
 * `available_actions`; every call carries an Idempotency-Key; courier booking stays disabled.
 */

afterEach(() => {
    cleanup();
    localStorage.clear();
});

const fulfiller: Me = { ...operator, permissions: [...operator.permissions, 'shipments.book'] };

const headerOf = (calls: ReturnType<typeof ordersApi>, suffix: string): string | null => {
    const call = [...calls.mock.calls].reverse().find(([url, init]) => String(url).endsWith(suffix) && init?.method === 'POST');
    const headers = (call?.[1]?.headers ?? {}) as Record<string, string>;
    return headers['Idempotency-Key'] ?? null;
};

describe('manual fulfilment on the order detail', () => {
    it('marks a packed order shipped with an optional courier and tracking reference', async () => {
        const calls = ordersApi(fulfiller, (url, init) => {
            if (url.endsWith('/orders/126') && init?.method !== 'POST') return { status: 200, body: { ...detail, status: 'ready_to_ship', available_actions: ['hold', 'ship', 'cancel', 'add_note'] } };
            if (url.endsWith('/orders/126/ship') && init?.method === 'POST') return { status: 200, body: { ...detail, status: 'shipped', available_actions: ['deliver', 'add_note'] } };
            return undefined;
        });
        renderApp('/orders/126');
        await screen.findByRole('heading', { level: 1 });

        fireEvent.click(screen.getByRole('button', { name: 'Mark shipped' }));
        const dialog = await screen.findByRole('dialog', { name: 'Mark TMB-26-00126 shipped' });
        fireEvent.change(within(dialog).getByLabelText(/Courier/), { target: { value: '  Pathao ' } });
        fireEvent.change(within(dialog).getByLabelText(/Tracking reference/), { target: { value: 'PTH-77881' } });
        fireEvent.click(within(dialog).getByRole('button', { name: 'Mark shipped' }));

        await waitFor(() => expect(postedTo(calls, '/orders/126/ship')).toEqual({ courier_name: 'Pathao', tracking_reference: 'PTH-77881', note: null }));
        expect(headerOf(calls, '/orders/126/ship')).toMatch(/^[0-9a-f-]{36}$/);
        expect(await screen.findByText('TMB-26-00126 marked shipped.')).toBeTruthy();
    });

    it('marks a shipped order delivered after one confirmation', async () => {
        const calls = ordersApi(fulfiller, (url, init) => {
            if (url.endsWith('/orders/126') && init?.method !== 'POST') return { status: 200, body: { ...detail, status: 'shipped', available_actions: ['deliver', 'add_note'] } };
            if (url.endsWith('/orders/126/deliver') && init?.method === 'POST') return { status: 200, body: { ...detail, status: 'delivered', available_actions: ['add_note'] } };
            return undefined;
        });
        renderApp('/orders/126');
        await screen.findByRole('heading', { level: 1 });
        expect(screen.queryByRole('button', { name: 'Mark shipped' })).toBeNull();

        fireEvent.click(screen.getByRole('button', { name: 'Mark delivered' }));
        const dialog = await screen.findByRole('dialog', { name: 'Mark TMB-26-00126 delivered' });
        fireEvent.click(within(dialog).getByRole('button', { name: 'Mark delivered' }));

        await waitFor(() => expect(postedTo(calls, '/orders/126/deliver')).toEqual({}));
        expect(headerOf(calls, '/orders/126/deliver')).not.toBeNull();
    });

    it('offers neither button when the server does not', async () => {
        ordersApi(operator);
        renderApp('/orders/126');
        await screen.findByRole('heading', { level: 1 });
        expect(screen.queryByRole('button', { name: 'Mark shipped' })).toBeNull();
        expect(screen.queryByRole('button', { name: 'Mark delivered' })).toBeNull();
    });

    it('renders the buttons in Bangla', async () => {
        ordersApi(fulfiller, (url, init) => (url.endsWith('/orders/126') && init?.method !== 'POST' ? { status: 200, body: { ...detail, status: 'shipped', available_actions: ['deliver', 'add_note'] } } : undefined));
        renderApp('/orders/126', { locale: 'bn' });
        await screen.findByRole('heading', { level: 1 });
        expect(screen.getByRole('button', { name: 'ডেলিভারড চিহ্নিত করুন' })).toBeTruthy();
    });
});

describe('bulk manual fulfilment on the board', () => {
    it('bulk ships per order with an Idempotency-Key, keeps failures selected, and keeps courier booking disabled', async () => {
        const calls = ordersApi(fulfiller, (url) =>
            url.endsWith('/orders/bulk/ship')
                ? {
                      status: 200,
                      body: {
                          data: [
                              { order_id: 126, number: 'TMB-26-00126', outcome: 'conflict', status: 'pending', detail: 'Order status cannot move from pending to shipped.' },
                              { order_id: 127, number: 'TMB-26-00127', outcome: 'done', status: 'shipped', detail: null },
                          ],
                          meta: { done: 1, failed: 1 },
                      },
                  }
                : undefined,
        );
        renderApp('/orders');
        const table = await screen.findByRole('table');
        fireEvent.click(within(table).getByRole('checkbox', { name: 'Select TMB-26-00126' }));
        fireEvent.click(within(table).getByRole('checkbox', { name: 'Select TMB-26-00127' }));
        const bar = screen.getByRole('region', { name: 'Bulk actions' });
        expect(within(bar).getByRole('button', { name: 'Book courier' }).getAttribute('aria-disabled')).toBe('true');

        fireEvent.click(within(bar).getByRole('button', { name: 'Mark shipped' }));
        await waitFor(() => expect(postedTo(calls, '/orders/bulk/ship')).toEqual({ order_ids: [126, 127] }));
        expect(headerOf(calls, '/orders/bulk/ship')).not.toBeNull();
        expect(await within(bar).findByText(/cannot move from pending to shipped/)).toBeTruthy();
        expect((within(table).getByRole('checkbox', { name: 'Select TMB-26-00126' }) as HTMLInputElement).checked).toBe(true);
        expect(within(bar).getByRole('button', { name: 'Mark delivered' })).toBeTruthy();
    });

    it('hides bulk ship and deliver without shipments.book', async () => {
        ordersApi(operator);
        renderApp('/orders');
        const table = await screen.findByRole('table');
        fireEvent.click(within(table).getByRole('checkbox', { name: 'Select TMB-26-00126' }));
        const bar = screen.getByRole('region', { name: 'Bulk actions' });
        expect(within(bar).queryByRole('button', { name: 'Mark shipped' })).toBeNull();
        expect(within(bar).queryByRole('button', { name: 'Mark delivered' })).toBeNull();
    });
});
