import { cleanup, fireEvent, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { mockApi, renderApp, viewer } from '@/test/renderApp';
import type { Me } from '@/features/auth/api';
import type { GatewayCheck, PaymentGateway } from './api';

afterEach(() => {
    cleanup();
    localStorage.clear();
    vi.unstubAllGlobals();
});

const owner: Me = { ...viewer, roles: ['super_admin'], permissions: ['dashboard.view', 'settings.view', 'settings.manage_integrations'] };
const operator: Me = { ...viewer, roles: ['order_operator'], permissions: ['dashboard.view', 'settings.view', 'settings.update'] };

const SSL_IPN = 'https://api.toymartbd.test/api/v1/webhooks/payments/sslcommerz/ipn';

const ssl: PaymentGateway = {
    code: 'sslcommerz',
    name: 'SSLCommerz',
    is_active: true,
    mode: 'sandbox',
    verified: false,
    verified_at: null,
    configured: true,
    status: { enabled: true, reason: null },
    credentials: {
        sandbox: {
            complete: true,
            fields: [
                { key: 'store_id', secret: false, set: true, value: 'testbox' },
                { key: 'store_password', secret: true, set: true, value: null },
            ],
        },
        live: {
            complete: false,
            fields: [
                { key: 'store_id', secret: false, set: false, value: null },
                { key: 'store_password', secret: true, set: false, value: null },
            ],
        },
    },
    ipn_url: SSL_IPN,
    hosts: { sandbox: 'sandbox.sslcommerz.com', live: 'securepay.sslcommerz.com' },
    updated_at: '2026-11-20T10:50:00+00:00',
    updated_by: { name: 'Mominul' },
};

const paystation: PaymentGateway = {
    code: 'paystation',
    name: 'PayStation',
    is_active: true,
    mode: 'live',
    verified: false,
    verified_at: null,
    configured: true,
    status: { enabled: false, reason: 'unverified' },
    credentials: {
        sandbox: { complete: false, fields: [{ key: 'merchant_id', secret: false, set: false, value: null }, { key: 'password', secret: true, set: false, value: null }] },
        live: { complete: true, fields: [{ key: 'merchant_id', secret: false, set: true, value: '104-1690000000' }, { key: 'password', secret: true, set: true, value: null }] },
    },
    ipn_url: 'https://api.toymartbd.test/api/v1/webhooks/payments/paystation/ipn',
    hosts: { sandbox: 'sandbox.paystation.com.bd', live: 'api.paystation.com.bd' },
    updated_at: null,
    updated_by: null,
};

type Reply = { status: number; body?: unknown };

const okCheck: Reply = { status: 200, body: { ok: true, code: 'ok', mode: 'sandbox', checked_at: '2026-11-20T10:52:00+00:00' } satisfies GatewayCheck };

function gatewaysApi(me: Me, put?: (code: string, body: Record<string, unknown>) => Reply, check: Reply = okCheck) {
    return mockApi((url, init) => {
        if (url.endsWith('/auth/me')) return { status: 200, body: me };
        const m = /\/settings\/payment-gateways\/(\w+)(\/check)?$/.exec(url);
        if (m && m[2]) return check;
        if (m && init?.method === 'PUT') {
            const body = JSON.parse(String(init.body)) as Record<string, unknown>;
            const row = m[1] === 'sslcommerz' ? ssl : paystation;
            return put ? put(m[1]!, body) : { status: 200, body: { ...row, ...(body.mode ? { mode: body.mode } : {}) } };
        }
        if (url.endsWith('/settings/payment-gateways')) return { status: 200, body: { data: [ssl, paystation] } };
        return { status: 404 };
    });
}

function puts(calls: ReturnType<typeof mockApi>) {
    return calls.mock.calls.filter(([, init]) => (init as RequestInit | undefined)?.method === 'PUT');
}

function putBody(calls: ReturnType<typeof mockApi>, code: string): Record<string, unknown> {
    const call = puts(calls).find(([url]) => String(url).endsWith(`/settings/payment-gateways/${code}`))!;
    return JSON.parse(String((call[1] as RequestInit).body)) as Record<string, unknown>;
}

/** The settings panel (a form named by the gateway's heading). */
async function panel(name: string): Promise<HTMLElement> {
    return screen.findByRole('form', { name });
}

function section(scope: HTMLElement, title: string): HTMLElement {
    return within(scope).getByRole('heading', { name: title }).closest('section')!;
}

function saveButton(scope: HTMLElement): HTMLButtonElement {
    return within(scope).getByRole('button', { name: 'Save changes' }) as HTMLButtonElement;
}

/** Save stays a solid Primary at rest; "nothing changed" shows on Discard. */
function discardButton(scope: HTMLElement): HTMLButtonElement {
    return within(scope).getByRole('button', { name: 'Discard' }) as HTMLButtonElement;
}

describe('integrations → payments', () => {
    it('shows a tile per gateway plus cash on delivery, and the selected gateway in the panel and the URL', async () => {
        gatewaysApi(owner);
        const { router } = renderApp('/integrations/payments');

        const tiles = await screen.findByRole('list', { name: 'Payment methods' });
        expect(within(tiles).getAllByRole('listitem')).toHaveLength(3);
        expect(within(tiles).getByRole('button', { name: 'SSLCommerz settings', pressed: true })).toBeTruthy();
        expect(within(tiles).getAllByText('Offered · test payments only').length).toBeGreaterThan(0);
        expect(within(tiles).getAllByText('Hidden · waiting for verification').length).toBeGreaterThan(0);
        expect(within(tiles).getAllByText('Always on').length).toBeGreaterThan(0);

        const sslPanel = await panel('SSLCommerz');
        expect(within(sslPanel).getByText('sandbox.sslcommerz.com')).toBeTruthy();
        expect(within(sslPanel).getByText(/Last saved .* by Mominul\. Changes apply to SSLCommerz only\./)).toBeTruthy();

        fireEvent.click(within(tiles).getByRole('button', { name: 'PayStation settings' }));
        const psPanel = await panel('PayStation');
        expect(router.state.location.search).toBe('?gateway=paystation');
        expect(within(psPanel).getByText('Waiting for verification')).toBeTruthy();
        expect(within(psPanel).getByText(/until the owner verifies it on the server/)).toBeTruthy();
        // No save recorded for PayStation: the footer never invents one.
        expect(within(psPanel).queryByText(/Last saved/)).toBeNull();
        expect(within(psPanel).getByText('Changes apply to PayStation only.')).toBeTruthy();
    });

    it('opens the gateway named in the URL', async () => {
        gatewaysApi(owner);
        renderApp('/integrations/payments?gateway=paystation');

        const psPanel = await panel('PayStation');
        expect(within(psPanel).getByRole('radio', { name: /Live/ })).toHaveProperty('checked', true);
        expect(within(psPanel).getByText('Live keys in use')).toBeTruthy();
        expect((within(psPanel).getByLabelText('Merchant ID') as HTMLInputElement).value).toBe('104-1690000000');
    });

    it('sends only what changed: the new live keys and the switch to live', async () => {
        const calls = gatewaysApi(owner);
        renderApp('/integrations/payments');

        const sslPanel = await panel('SSLCommerz');
        expect(saveButton(sslPanel).disabled).toBe(false);
        expect(discardButton(sslPanel).disabled).toBe(true);
        // At rest Save is still the solid Primary: it says there is nothing to save and sends nothing.
        fireEvent.click(saveButton(sslPanel));
        expect(await screen.findByText('Nothing to save — no changes yet')).toBeTruthy();
        expect(calls.mock.calls.some(([, init]) => (init as RequestInit | undefined)?.method === 'PUT')).toBe(false);
        fireEvent.click(within(sslPanel).getByRole('button', { name: 'Live keys' }));
        expect(within(sslPanel).getByText('Not set')).toBeTruthy();
        fireEvent.change(within(sslPanel).getByLabelText('Store ID'), { target: { value: ' toymartlive ' } });
        fireEvent.change(within(sslPanel).getByLabelText('Store Password'), { target: { value: 'live-pass-1' } });
        fireEvent.click(within(sslPanel).getByRole('radio', { name: /Live/ }));

        expect(within(sslPanel).getByText('3 unsaved changes')).toBeTruthy();
        expect(within(sslPanel).getByText('Environment, Store ID, Store Password')).toBeTruthy();
        fireEvent.click(saveButton(sslPanel));

        await waitFor(() => expect(puts(calls)).toHaveLength(1));
        expect(putBody(calls, 'sslcommerz')).toEqual({ mode: 'live', credentials: { live: { store_id: 'toymartlive', store_password: 'live-pass-1' } } });
        expect(await screen.findByText('SSLCommerz settings saved')).toBeTruthy();
    });

    it('removes a saved secret only through clear, and Undo takes the removal back', async () => {
        const calls = gatewaysApi(owner);
        renderApp('/integrations/payments');

        const sslPanel = await panel('SSLCommerz');
        expect(within(sslPanel).getByText('Saved')).toBeTruthy();
        fireEvent.click(within(sslPanel).getByRole('button', { name: 'Remove the saved Store Password' }));
        expect(within(sslPanel).getByText('Will be removed')).toBeTruthy();
        fireEvent.click(within(sslPanel).getByRole('button', { name: 'Keep the saved Store Password' }));
        expect(discardButton(sslPanel).disabled).toBe(true);

        fireEvent.click(within(sslPanel).getByRole('button', { name: 'Remove the saved Store Password' }));
        fireEvent.click(saveButton(sslPanel));

        await waitFor(() => expect(puts(calls)).toHaveLength(1));
        expect(putBody(calls, 'sslcommerz')).toEqual({ clear: { sandbox: ['store_password'] } });
    });

    it('replaces a saved secret with a typed one; Cancel keeps the saved one and sends nothing', async () => {
        const calls = gatewaysApi(owner);
        renderApp('/integrations/payments');

        const sslPanel = await panel('SSLCommerz');
        fireEvent.click(within(sslPanel).getByRole('button', { name: 'Replace the saved Store Password' }));
        const input = within(sslPanel).getByLabelText('Store Password') as HTMLInputElement;
        expect(input.type).toBe('password');
        expect(input.value).toBe('');
        fireEvent.change(input, { target: { value: 'typed-then-cancelled' } });
        fireEvent.click(within(sslPanel).getByRole('button', { name: 'Cancel replacing the Store Password' }));
        expect(discardButton(sslPanel).disabled).toBe(true);

        fireEvent.click(within(sslPanel).getByRole('button', { name: 'Replace the saved Store Password' }));
        fireEvent.change(within(sslPanel).getByLabelText('Store Password'), { target: { value: 'new-sandbox-pass' } });
        fireEvent.click(saveButton(sslPanel));

        await waitFor(() => expect(puts(calls)).toHaveLength(1));
        expect(putBody(calls, 'sslcommerz')).toEqual({ credentials: { sandbox: { store_password: 'new-sandbox-pass' } } });
    });

    it("shows the server's refusal to switch to live without live keys on the Environment section", async () => {
        gatewaysApi(owner, () => ({
            status: 422,
            body: { type: 'https://toymartbd.com/problems/validation-error', title: 'Validation failed', status: 422, errors: [{ field: 'mode', message: 'Enter every live key before switching to live mode.' }] },
        }));
        renderApp('/integrations/payments');

        const sslPanel = await panel('SSLCommerz');
        fireEvent.click(within(sslPanel).getByRole('radio', { name: /Live/ }));
        fireEvent.click(saveButton(sslPanel));

        const environment = section(sslPanel, 'Environment');
        expect(await within(environment).findByRole('alert')).toHaveProperty('textContent', 'Enter every live key before switching to live mode.');
        expect(within(environment).getByRole('radio', { name: /Live/ }).getAttribute('aria-invalid')).toBe('true');
    });

    it('tests the saved keys only when nothing is unsaved, and never PayStation in live mode', async () => {
        gatewaysApi(owner);
        renderApp('/integrations/payments');

        const sslPanel = await panel('SSLCommerz');
        const connection = section(sslPanel, 'Connection');
        fireEvent.click(within(connection).getByRole('button', { name: 'Test connection' }));
        expect(await within(connection).findByText(/SSLCommerz accepted the sandbox keys · /)).toBeTruthy();

        fireEvent.change(within(sslPanel).getByLabelText('Store ID'), { target: { value: 'other' } });
        expect((within(connection).getByRole('button', { name: 'Test connection' }) as HTMLButtonElement).disabled).toBe(true);
        expect(within(connection).getByText('Save your changes, then test the connection.')).toBeTruthy();

        fireEvent.click(screen.getByRole('button', { name: 'PayStation settings' }));
        const psConnection = section(await panel('PayStation'), 'Connection');
        expect((within(psConnection).getByRole('button', { name: 'Test connection' }) as HTMLButtonElement).disabled).toBe(true);
        expect(within(psConnection).getByText(/Available in sandbox only/)).toBeTruthy();
    });

    it('keeps an unsaved draft when switching tiles, and a tile switch edits the same value as the panel', async () => {
        const calls = gatewaysApi(owner);
        renderApp('/integrations/payments');

        await panel('SSLCommerz');
        fireEvent.click(screen.getByRole('switch', { name: 'Show SSLCommerz at checkout' }));
        const sslPanel = await panel('SSLCommerz');
        expect(within(sslPanel).getByRole('switch', { name: 'Show at checkout' }).getAttribute('aria-checked')).toBe('false');

        fireEvent.click(screen.getByRole('button', { name: 'PayStation settings' }));
        await panel('PayStation');
        fireEvent.click(screen.getByRole('button', { name: 'SSLCommerz settings' }));
        const again = await panel('SSLCommerz');
        expect(within(again).getByText('1 unsaved change')).toBeTruthy();
        fireEvent.click(saveButton(again));

        await waitFor(() => expect(puts(calls)).toHaveLength(1));
        expect(putBody(calls, 'sslcommerz')).toEqual({ is_active: false });
    });

    it('copies the IPN URL and says so', async () => {
        const writeText = vi.fn().mockResolvedValue(undefined);
        Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true });
        gatewaysApi(owner);
        renderApp('/integrations/payments');

        const notifications = section(await panel('SSLCommerz'), 'Payment notifications');
        expect(within(notifications).getByText(SSL_IPN)).toBeTruthy();
        fireEvent.click(within(notifications).getByRole('button', { name: 'Copy the IPN listener URL' }));

        await waitFor(() => expect(writeText).toHaveBeenCalledWith(SSL_IPN));
        expect(await screen.findByText('IPN URL copied')).toBeTruthy();
    });

    it('is forbidden without settings.manage_integrations', async () => {
        gatewaysApi(operator);
        renderApp('/integrations/payments');

        expect(await screen.findByText('You do not have access to this page.')).toBeTruthy();
    });
});
