import { cleanup, fireEvent, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { renderApp } from '@/test/renderApp';
import type { Order } from './api';
import { detail, operator, ordersApi } from './fixtures';

// P4-PAY-03 — the order payment card for an online (SSLCommerz) payment: gateway verification
// state, the transaction ids staff reconcile with, a review flag, and "Re-check with gateway"
// (POST …/payments/{id}/verify with an Idempotency-Key).

afterEach(() => cleanup());

const online: Order['payments'][number] = {
    id: 41,
    method: 'sslcommerz',
    status: 'initiated',
    amount: '1930.00',
    fee_amount: '0.00',
    transaction_id: 'TMB-26-00126-P41',
    paid_at: null,
    gateway_reference: 'A1B2C3D4E5F6',
    val_id: null,
    failure_reason: null,
    review_reason: 'amount_mismatch',
    expires_at: '2026-11-12T10:30:00Z',
    verified_at: null,
    gateway: { enabled: true, verified: false, sandbox: true, reason: null },
};

describe('online payment card', () => {
    it('shows gateway state, ids and the review flag, and re-checks with an Idempotency-Key', async () => {
        const withOnline = { ...detail, payment_method: 'sslcommerz', payments: [online] };
        const calls = ordersApi(operator, (url, init) => {
            if (url.endsWith('/orders/126/payments/41/verify') && init?.method === 'POST') return { status: 200, body: { ...withOnline, payments: [{ ...online, status: 'paid', review_reason: null }] } };
            if (url.endsWith('/orders/126')) return { status: 200, body: withOnline };
            return undefined;
        });
        renderApp('/orders/126');

        expect(await screen.findByText('TMB-26-00126-P41')).toBeTruthy();
        expect(screen.getByText('A1B2C3D4E5F6')).toBeTruthy();
        expect(screen.getByText('Gateway unverified · Sandbox')).toBeTruthy();
        expect(screen.getByText('Needs review: amount_mismatch — not marked paid')).toBeTruthy();

        fireEvent.click(screen.getByRole('button', { name: 'Re-check with gateway' }));
        await waitFor(() => expect(screen.queryByRole('button', { name: 'Re-check with gateway' })).toBeNull());
        const call = calls.mock.calls.find(([url]) => String(url).endsWith('/payments/41/verify'));
        expect((call?.[1]?.headers as Record<string, string>)['Idempotency-Key']).toMatch(/^[0-9a-f-]{36}$/);
    });
});
