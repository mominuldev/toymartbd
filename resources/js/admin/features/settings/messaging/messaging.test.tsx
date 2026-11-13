import { cleanup, fireEvent, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { mockApi, renderApp, viewer } from '@/test/renderApp';
import type { Me } from '@/features/auth/api';
import type { MessagingSettings, SmsTestResult } from './api';

afterEach(() => {
    cleanup();
    localStorage.clear();
});

const owner: Me = { ...viewer, roles: ['super_admin'], permissions: ['dashboard.view', 'settings.view', 'settings.manage_integrations'] };
const operator: Me = { ...viewer, roles: ['order_operator'], permissions: ['dashboard.view', 'settings.view', 'settings.update'] };

const saved: MessagingSettings = {
    sms_driver: 'reve',
    reve_base_url: 'https://smpp.ajuratech.com:7790',
    reve_sender_id: '8809612345678',
    sms_cost_per_segment: '0.26',
    whatsapp_enabled: false,
    reve_api_key_set: true,
    reve_api_key_hint: '••••1234',
    reve_api_key_source: 'settings',
    reve_secret_key_set: true,
    reve_secret_key_hint: '••••',
    reve_secret_key_source: 'env',
    sources: { sms_driver: 'settings', reve_base_url: 'settings', reve_sender_id: 'settings', sms_cost_per_segment: 'env', whatsapp_enabled: 'env' },
};

const accepted: SmsTestResult = { driver: 'reve', accepted: true, message_id: '4242', segments: 2, cost: '0.52', error: null };

function messagingApi(me: Me, test: { status: number; body?: unknown } = { status: 200, body: accepted }) {
    return mockApi((url, init) => {
        if (url.endsWith('/auth/me')) return { status: 200, body: me };
        if (url.endsWith('/settings/messaging/test')) return test;
        if (url.endsWith('/settings/messaging') && init?.method === 'PUT') {
            const sent = JSON.parse(String(init.body)) as Partial<MessagingSettings>;
            return { status: 200, body: { ...saved, ...sent } };
        }
        if (url.endsWith('/settings/messaging')) return { status: 200, body: saved };
        return { status: 404 };
    });
}

function sentBody(calls: ReturnType<typeof mockApi>, method: string, path: string): Record<string, unknown> {
    const call = calls.mock.calls.find(([url, init]) => String(url).endsWith(path) && (init as RequestInit | undefined)?.method === method)!;
    return JSON.parse(String((call[1] as RequestInit).body)) as Record<string, unknown>;
}

describe('integrations → messaging', () => {
    it('shows saved secrets only as a hint, with the source, and blank password fields', async () => {
        messagingApi(owner);
        renderApp('/integrations/messaging');

        const apiKey = (await screen.findByLabelText(/API key/)) as HTMLInputElement;
        expect(apiKey.type).toBe('password');
        expect(apiKey.value).toBe('');
        expect(screen.getByText('Saved ••••1234 — leave blank to keep')).toBeTruthy();
        expect(screen.getByText('Set in the server environment •••• — enter a value to save it here')).toBeTruthy();
        // The env-sourced cost says where it comes from.
        expect(screen.getAllByText('Currently from the server environment').length).toBeGreaterThan(0);
        expect((screen.getByRole('switch', { name: 'Send order updates on WhatsApp first' }) as HTMLButtonElement).getAttribute('aria-checked')).toBe('false');
    });

    it('saves with blank secrets kept (null) and a typed one replaced', async () => {
        const calls = messagingApi(owner);
        renderApp('/integrations/messaging');

        const secret = await screen.findByLabelText(/Secret key/);
        fireEvent.change(secret, { target: { value: 'new-secret-value' } });
        fireEvent.click(screen.getByRole('switch', { name: 'Send order updates on WhatsApp first' }));
        fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));

        await waitFor(() => expect(calls.mock.calls.some(([, init]) => (init as RequestInit | undefined)?.method === 'PUT')).toBe(true));
        expect(sentBody(calls, 'PUT', '/settings/messaging')).toEqual({
            sms_driver: 'reve',
            reve_base_url: 'https://smpp.ajuratech.com:7790',
            reve_sender_id: '8809612345678',
            sms_cost_per_segment: '0.26',
            whatsapp_enabled: true,
            reve_api_key: null,
            reve_secret_key: 'new-secret-value',
            clear_reve_api_key: false,
            clear_reve_secret_key: false,
        });
        expect(await screen.findByText('Messaging settings saved')).toBeTruthy();
    });

    it('refuses a masked (lettered) sender ID before sending, with a clear message', async () => {
        const calls = messagingApi(owner);
        renderApp('/integrations/messaging');

        const sender = await screen.findByLabelText(/Sender ID/);
        fireEvent.change(sender, { target: { value: 'ToyMartBD' } });
        fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));

        expect(await screen.findByText('Enter the non-masking number, digits only (5–15). Letters and brand names are not allowed.')).toBeTruthy();
        expect(calls.mock.calls.some(([, init]) => (init as RequestInit | undefined)?.method === 'PUT')).toBe(false);
    });

    it('can remove a saved key explicitly', async () => {
        const calls = messagingApi(owner);
        renderApp('/integrations/messaging');

        fireEvent.click(await screen.findByRole('button', { name: 'Remove saved key' }));
        expect(screen.getByText('Will be removed when you save.')).toBeTruthy();
        fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));

        await waitFor(() => expect(calls.mock.calls.some(([, init]) => (init as RequestInit | undefined)?.method === 'PUT')).toBe(true));
        expect(sentBody(calls, 'PUT', '/settings/messaging')).toMatchObject({ reve_api_key: null, clear_reve_api_key: true });
    });

    it('validates with the generated schema before calling the API', async () => {
        const calls = messagingApi(owner);
        renderApp('/integrations/messaging');

        fireEvent.change(await screen.findByLabelText(/API base URL/), { target: { value: 'not a url' } });
        fireEvent.change(screen.getByLabelText(/Cost per SMS segment/), { target: { value: '-1' } });
        fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));

        await waitFor(() => expect(screen.getByLabelText(/API base URL/).getAttribute('aria-invalid')).toBe('true'));
        expect(screen.getByLabelText(/Cost per SMS segment/).getAttribute('aria-invalid')).toBe('true');
        expect(calls.mock.calls.some(([, init]) => (init as RequestInit | undefined)?.method === 'PUT')).toBe(false);
    });

    it('sends a Bangla test SMS and shows what the provider said', async () => {
        const calls = messagingApi(owner);
        renderApp('/integrations/messaging');

        fireEvent.change(await screen.findByLabelText(/Mobile number/), { target: { value: '01712345678' } });
        fireEvent.click(screen.getByRole('button', { name: 'বাংলা' }));
        fireEvent.click(screen.getByRole('button', { name: 'Send test SMS' }));

        expect(await screen.findByText('Accepted by reve')).toBeTruthy();
        expect(screen.getByText('Message ID 4242 · 2 segment(s) · ৳0.52')).toBeTruthy();
        expect(sentBody(calls, 'POST', '/settings/messaging/test')).toEqual({ phone: '01712345678', locale: 'bn' });
    });

    it('refuses a non-BD number (rule 30) without calling the API, and shows a refusal', async () => {
        const calls = messagingApi(owner, { status: 200, body: { ...accepted, accepted: false, message_id: null, error: 'reve 109: Invalid api key/secret key' } });
        renderApp('/integrations/messaging');

        fireEvent.change(await screen.findByLabelText(/Mobile number/), { target: { value: '01112345678' } });
        fireEvent.click(screen.getByRole('button', { name: 'Send test SMS' }));
        expect(await screen.findByText('Enter a Bangladeshi mobile number (013–019).')).toBeTruthy();
        expect(calls.mock.calls.some(([url]) => String(url).endsWith('/test'))).toBe(false);

        fireEvent.change(screen.getByLabelText(/Mobile number/), { target: { value: '01712345678' } });
        fireEvent.click(screen.getByRole('button', { name: 'Send test SMS' }));
        expect(await screen.findByText('Refused by reve')).toBeTruthy();
        expect(screen.getByText('reve 109: Invalid api key/secret key')).toBeTruthy();
    });

    it('is closed to staff without settings.manage_integrations', async () => {
        const calls = messagingApi(operator);
        renderApp('/integrations/messaging');

        expect(await screen.findByText('You do not have access to this page.')).toBeTruthy();
        expect(calls.mock.calls.some(([url]) => String(url).endsWith('/settings/messaging'))).toBe(false);
    });

    it('opens the Integrations section on Messaging, in Bangla too', async () => {
        messagingApi(owner);
        renderApp('/integrations', { locale: 'bn' });

        expect(await screen.findByRole('heading', { name: 'মেসেজিং' })).toBeTruthy();
    });
});
