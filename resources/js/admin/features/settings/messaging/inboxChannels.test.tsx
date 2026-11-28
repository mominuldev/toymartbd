import { cleanup, fireEvent, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { updateInboxChannelSettingsRequestSchema } from '@toymart/api-types/zod';
import { mockApi, renderApp, viewer } from '@/test/renderApp';
import type { Me } from '@/features/auth/api';
import type { InboxChannelCredentials, InboxChannelSettings, MessagingSettings } from './api';

afterEach(() => {
    cleanup();
    localStorage.clear();
});

const owner: Me = { ...viewer, roles: ['super_admin'], permissions: ['dashboard.view', 'settings.view', 'settings.manage_integrations'] };
const operator: Me = { ...viewer, roles: ['order_operator'], permissions: ['dashboard.view', 'settings.view', 'settings.update', 'inbox.view'] };

const messaging: MessagingSettings = {
    sms_driver: 'log',
    reve_base_url: null,
    reve_sender_id: null,
    sms_cost_per_segment: '0.26',
    whatsapp_enabled: false,
    whatsapp_driver: 'log',
    reve_api_key_set: false,
    reve_api_key_hint: null,
    reve_api_key_source: null,
    reve_secret_key_set: false,
    reve_secret_key_hint: null,
    reve_secret_key_source: null,
    sources: { sms_driver: 'env', reve_base_url: 'env', reve_sender_id: 'env', sms_cost_per_segment: 'env', whatsapp_enabled: 'env', whatsapp_driver: 'env' },
};

const unset = { set: false, hint: null, source: null } as const;

/** Fields added for Inbox › Channels (connection state, counts, Facebook login). */
const channelExtras = {
    connection: { state: 'not_connected', reason: null, since: null },
    activity: { open: 0, waiting: 0, unread: 0, oldest_waiting_at: null, last_inbound_at: null, last_inbound_from: null, last_event_at: null },
    page_name: null,
    connected_at: null,
    connected_by: null,
    app_id: null,
    app_id_source: null,
    login_config_id: null,
    login_config_id_source: null,
    login_ready: false,
    oauth_redirect_uri: null,
} as const;

const whatsapp: InboxChannelCredentials = {
    channel: 'whatsapp',
    driver: 'cloud',
    driver_source: 'settings',
    account_id: '106540352242922',
    account_id_source: 'settings',
    business_account_id: '102290129340398',
    business_account_id_source: 'settings',
    human_agent: false,
    human_agent_source: 'env',
    send_token: { set: true, hint: '••••7781', source: 'settings' },
    app_secret: { set: true, hint: '••••4410', source: 'settings' },
    verify_token: { set: true, hint: '••••', source: 'env' },
    webhook_url: 'https://toymartbd.com/api/v1/webhooks/inbox/whatsapp',
    webhook_ready: true,
    send_ready: true,
    verified: false,
    last_check: null,
    ...channelExtras,
};

const messenger: InboxChannelCredentials = {
    channel: 'messenger',
    driver: 'log',
    driver_source: 'env',
    account_id: null,
    account_id_source: null,
    business_account_id: null,
    business_account_id_source: null,
    human_agent: false,
    human_agent_source: 'env',
    send_token: unset,
    app_secret: unset,
    verify_token: unset,
    webhook_url: 'https://toymartbd.com/api/v1/webhooks/inbox/messenger',
    webhook_ready: false,
    send_ready: false,
    verified: false,
    last_check: null,
    ...channelExtras,
};

const saved: InboxChannelSettings = { whatsapp, messenger };

function inboxApi(me: Me, put: { status: number; body?: unknown } = { status: 200, body: saved }, test?: { status: number; body?: unknown }, after?: InboxChannelSettings) {
    let tested = false;
    return mockApi((url, init) => {
        if (url.endsWith('/auth/me')) return { status: 200, body: me };
        if (url.endsWith('/settings/inbox-channels/whatsapp/test') && init?.method === 'POST') {
            tested = true;
            return test ?? { status: 404 };
        }
        if (url.endsWith('/settings/inbox-channels') && init?.method !== 'PUT' && tested && after) return { status: 200, body: after };
        if (url.endsWith('/settings/messaging')) return { status: 200, body: messaging };
        if (url.endsWith('/settings/inbox-channels') && init?.method === 'PUT') return put;
        if (url.endsWith('/settings/inbox-channels')) return { status: 200, body: saved };
        return { status: 404 };
    });
}

function putBody(calls: ReturnType<typeof mockApi>): Record<string, unknown> {
    const call = calls.mock.calls.find(([url, init]) => String(url).endsWith('/settings/inbox-channels') && (init as RequestInit | undefined)?.method === 'PUT')!;
    return JSON.parse(String((call[1] as RequestInit).body)) as Record<string, unknown>;
}

const putSent = (calls: ReturnType<typeof mockApi>) =>
    calls.mock.calls.some(([url, init]) => String(url).endsWith('/settings/inbox-channels') && (init as RequestInit | undefined)?.method === 'PUT');

async function card(name: string): Promise<HTMLElement> {
    const heading = await screen.findByRole('heading', { name });
    return heading.closest('section')!;
}

describe('integrations → messaging → inbox channels', () => {
    it('shows each channel on its own tab, with ids, secrets only as hints, the webhook URL and the readiness line', async () => {
        inboxApi(owner);
        renderApp('/integrations/messaging/whatsapp');

        const wa = within(await card('WhatsApp inbox'));
        expect((wa.getByLabelText('Phone number ID') as HTMLInputElement).value).toBe('106540352242922');
        const token = wa.getByLabelText(/^Access token/) as HTMLInputElement;
        expect(token.type).toBe('password');
        expect(token.value).toBe('');
        expect(wa.getByText('Saved ••••7781 — leave blank to keep')).toBeTruthy();
        expect(wa.getByText('Saved ••••4410 — leave blank to keep')).toBeTruthy();
        expect(wa.getByText('Set in the server environment •••• — enter a value to save it here')).toBeTruthy();
        expect(wa.getByText('https://toymartbd.com/api/v1/webhooks/inbox/whatsapp')).toBeTruthy();
        expect(wa.getByRole('button', { name: 'Copy WhatsApp inbox callback URL' })).toBeTruthy();
        expect(wa.getByText('Webhook ready')).toBeTruthy();
        expect(wa.getByText('Sending ready')).toBeTruthy();
        expect(wa.getByText('Not verified — no real account yet')).toBeTruthy();
        expect(wa.getByRole('button', { name: 'WhatsApp Cloud API' }).getAttribute('aria-pressed')).toBe('true');
        expect(screen.queryByRole('heading', { name: 'Messenger inbox' })).toBeNull();

        fireEvent.click(screen.getByRole('link', { name: 'Facebook Messenger' }));
        const fb = within(await card('Messenger inbox'));
        expect(screen.queryByRole('heading', { name: 'WhatsApp inbox' })).toBeNull();
        expect(fb.getAllByText('Not set')).toHaveLength(3);
        expect(fb.getByText('Webhook not ready')).toBeTruthy();
        expect(fb.getByText('Sending not ready')).toBeTruthy();
        expect(fb.getByText('Not verified — no real account yet')).toBeTruthy();
        expect(fb.getByRole('button', { name: 'Off — log only' }).getAttribute('aria-pressed')).toBe('true');
        // The driver and the HUMAN_AGENT switch both come from env until saved.
        expect(fb.getAllByText('Currently from the server environment')).toHaveLength(2);
    });

    it('saves a body the generated schema accepts: blank secrets kept, typed ones sent, clears explicit', async () => {
        const calls = inboxApi(owner);
        renderApp('/integrations/messaging/messenger');

        const fb = within(await card('Messenger inbox'));
        fireEvent.click(fb.getByRole('button', { name: 'Messenger' }));
        fireEvent.change(fb.getByLabelText('Page ID'), { target: { value: ' 104857300112233 ' } });
        fireEvent.change(fb.getByLabelText(/^Page access token/), { target: { value: 'EAAG-page-token' } });
        fireEvent.change(fb.getByLabelText(/^App secret/), { target: { value: 'fb-app-secret' } });
        fireEvent.click(fb.getByRole('switch', { name: 'Reply for up to 7 days (human agent)' }));
        // The WhatsApp card is on its own tab; its saved values ride along unchanged.
        fireEvent.click(screen.getByRole('button', { name: 'Save inbox channels' }));

        await waitFor(() => expect(putSent(calls)).toBe(true));
        const body = putBody(calls);
        expect(body).toEqual({
            whatsapp_driver: 'cloud',
            whatsapp_phone_number_id: '106540352242922',
            whatsapp_business_account_id: '102290129340398',
            whatsapp_access_token: null,
            whatsapp_app_secret: null,
            whatsapp_verify_token: null,
            clear_whatsapp_access_token: false,
            clear_whatsapp_app_secret: false,
            clear_whatsapp_verify_token: false,
            messenger_driver: 'messenger',
            messenger_page_id: '104857300112233',
            messenger_human_agent: true,
            messenger_page_access_token: 'EAAG-page-token',
            messenger_app_secret: 'fb-app-secret',
            messenger_verify_token: null,
            messenger_app_id: null,
            messenger_login_config_id: null,
            clear_messenger_page_access_token: false,
            clear_messenger_app_secret: false,
            clear_messenger_verify_token: false,
        });
        expect(updateInboxChannelSettingsRequestSchema.safeParse(body).success).toBe(true);
        expect(await screen.findByText('Inbox channel settings saved')).toBeTruthy();
        // The SMS form's own save was not touched.
        expect(calls.mock.calls.some(([url, init]) => String(url).endsWith('/settings/messaging') && (init as RequestInit | undefined)?.method === 'PUT')).toBe(false);
    });

    it('shows the Business Account ID and explains the HUMAN_AGENT switch may need Meta app review', async () => {
        inboxApi(owner);
        renderApp('/integrations/messaging/whatsapp');

        const wa = within(await card('WhatsApp inbox'));
        expect((wa.getByLabelText('WhatsApp Business Account ID') as HTMLInputElement).value).toBe('102290129340398');
        fireEvent.click(screen.getByRole('link', { name: 'Facebook Messenger' }));
        const fb = within(await card('Messenger inbox'));
        expect(fb.getByRole('switch', { name: 'Reply for up to 7 days (human agent)' }).getAttribute('aria-checked')).toBe('false');
        expect(fb.getByText(/Meta may require your app to pass review/)).toBeTruthy();
    });

    it('tests the WhatsApp connection with the saved credentials and shows the result — never “verified”', async () => {
        const after: InboxChannelSettings = {
            ...saved,
            whatsapp: {
                ...whatsapp,
                last_check: {
                    ok: true,
                    checked_at: new Date().toISOString(),
                    display_name: 'Smart Junior',
                    display_number: '+880 1700-000000',
                    quality_rating: 'GREEN',
                    error_code: null,
                    error_message: null,
                },
            },
        };
        const calls = inboxApi(owner, undefined, { status: 200, body: { channel: 'whatsapp', ...after.whatsapp.last_check } }, after);
        renderApp('/integrations/messaging/whatsapp');

        const wa = within(await card('WhatsApp inbox'));
        expect(wa.getByText('Not tested yet.')).toBeTruthy();
        expect(wa.getByText(/A passing test does not mark the channel verified/)).toBeTruthy();
        fireEvent.click(wa.getByRole('button', { name: 'Test connection' }));

        expect(await wa.findByText(/Connected as Smart Junior \(\+880 1700-000000\) · quality GREEN/)).toBeTruthy();
        expect(wa.getByText('Not verified — no real account yet')).toBeTruthy();
        expect(calls.mock.calls.some(([url, init]) => String(url).endsWith('/settings/inbox-channels/whatsapp/test') && (init as RequestInit | undefined)?.method === 'POST')).toBe(true);
    });

    it('disables the test while the form has unsaved changes, and shows a failed check', async () => {
        const failed: InboxChannelSettings = {
            ...saved,
            whatsapp: {
                ...whatsapp,
                last_check: {
                    ok: false,
                    checked_at: new Date().toISOString(),
                    display_name: null,
                    display_number: null,
                    quality_rating: null,
                    error_code: 'meta_190',
                    error_message: 'Error validating access token.',
                },
            },
        };
        mockApi((url) => {
            if (url.endsWith('/auth/me')) return { status: 200, body: owner };
            if (url.endsWith('/settings/messaging')) return { status: 200, body: messaging };
            if (url.endsWith('/settings/inbox-channels')) return { status: 200, body: failed };
            return { status: 404 };
        });
        renderApp('/integrations/messaging/whatsapp');

        const wa = within(await card('WhatsApp inbox'));
        expect(wa.getByText(/Failed: meta_190 — Error validating access token\./)).toBeTruthy();
        fireEvent.change(wa.getByLabelText('Phone number ID'), { target: { value: '999' } });
        await waitFor(() => expect((wa.getByRole('button', { name: 'Test connection' }) as HTMLButtonElement).disabled).toBe(true));
        expect(wa.getByText('Save your changes first: the test uses the saved credentials.')).toBeTruthy();
    });

    it('refuses a non-numeric id with the generated schema before calling the API', async () => {
        const calls = inboxApi(owner);
        renderApp('/integrations/messaging/messenger');

        const fb = within(await card('Messenger inbox'));
        fireEvent.change(fb.getByLabelText('Page ID'), { target: { value: 'toymartbd' } });
        fireEvent.click(screen.getByRole('button', { name: 'Save inbox channels' }));

        expect(await fb.findByText('Digits only — copy the ID exactly as Meta shows it.')).toBeTruthy();
        expect(putSent(calls)).toBe(false);
    });

    it('shows the server’s 422 on the field (Meta driver without a token)', async () => {
        const calls = inboxApi(owner, {
            status: 422,
            body: {
                type: 'https://toymartbd.com/problems/validation-error',
                title: 'Validation failed',
                status: 422,
                errors: [{ field: 'messenger_page_access_token', message: 'Required to send through Meta.' }],
            },
        });
        renderApp('/integrations/messaging/messenger');

        const fb = within(await card('Messenger inbox'));
        fireEvent.click(fb.getByRole('button', { name: 'Messenger' }));
        fireEvent.change(fb.getByLabelText('Page ID'), { target: { value: '1048573' } });
        fireEvent.click(screen.getByRole('button', { name: 'Save inbox channels' }));

        await waitFor(() => expect(putSent(calls)).toBe(true));
        expect(await fb.findByText('Required to send through Meta.')).toBeTruthy();
    });

    it('is read-only — closed, with no credential request — for staff without settings.manage_integrations', async () => {
        const calls = inboxApi(operator);
        renderApp('/integrations/messaging/whatsapp');

        expect(await screen.findByText('You do not have access to this page.')).toBeTruthy();
        expect(screen.queryByRole('heading', { name: 'WhatsApp inbox' })).toBeNull();
        expect(screen.queryByRole('button', { name: 'Save inbox channels' })).toBeNull();
        expect(screen.queryAllByRole('textbox')).toHaveLength(0);
        expect(calls.mock.calls.some(([url]) => String(url).includes('/settings/inbox-channels'))).toBe(false);
    });
});
