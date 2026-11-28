import { cleanup, fireEvent, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { updateInboxChannelSettingsRequestSchema } from '@toymart/api-types/zod';
import { mockApi, renderApp, viewer } from '@/test/renderApp';
import type { Me } from '@/features/auth/api';
import type { InboxChannelCredentials, InboxChannelSettings } from '../api';

afterEach(() => {
    cleanup();
    localStorage.clear();
    vi.restoreAllMocks();
});

const owner: Me = { ...viewer, roles: ['super_admin'], permissions: ['dashboard.view', 'inbox.view', 'settings.view', 'settings.manage_integrations'] };
const agent: Me = { ...viewer, roles: ['support'], permissions: ['dashboard.view', 'inbox.view'] };

const unset = { set: false, hint: null, source: null } as const;
const noActivity = { open: 0, waiting: 0, unread: 0, oldest_waiting_at: null, last_inbound_at: null, last_inbound_from: null, last_event_at: null };

function channel(overrides: Partial<InboxChannelCredentials> & Pick<InboxChannelCredentials, 'channel'>): InboxChannelCredentials {
    return {
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
        webhook_url: `https://admin.toymartbd.com/api/v1/webhooks/inbox/${overrides.channel}`,
        webhook_ready: false,
        send_ready: false,
        verified: false,
        last_check: null,
        connection: { state: 'not_connected', reason: null, since: null },
        activity: noActivity,
        page_name: null,
        connected_at: null,
        connected_by: null,
        app_id: null,
        app_id_source: null,
        login_config_id: null,
        login_config_id_source: null,
        login_ready: false,
        oauth_redirect_uri: overrides.channel === 'messenger' ? 'https://admin.toymartbd.com/admin/integrations/messenger' : null,
        ...overrides,
    };
}

const secret = (hint: string) => ({ set: true, hint, source: 'settings' }) as const;

const connectedPage = channel({
    channel: 'messenger',
    driver: 'messenger',
    driver_source: 'settings',
    account_id: '151784545352643',
    account_id_source: 'settings',
    send_token: secret('••••a1b2'),
    app_secret: secret('••••c3d4'),
    verify_token: secret('••••e5f6'),
    webhook_ready: true,
    send_ready: true,
    connection: { state: 'connected', reason: null, since: null },
    activity: { open: 12, waiting: 3, unread: 5, oldest_waiting_at: new Date(Date.now() - 18 * 60_000).toISOString(), last_inbound_at: new Date(Date.now() - 4 * 60_000).toISOString(), last_inbound_from: 'Rakibul Hasan', last_event_at: new Date().toISOString() },
    page_name: 'Smart Junior',
    connected_at: '2026-11-20T08:00:00+06:00',
    connected_by: 'Mominul Islam',
    app_id: '987654321012345',
    app_id_source: 'settings',
    login_ready: true,
});

type Handler = (url: string, init?: RequestInit) => { status: number; body?: unknown } | undefined;

function api(me: Me, settings: InboxChannelSettings, extra?: Handler) {
    return mockApi((url, init) => {
        if (url.endsWith('/auth/me')) return { status: 200, body: me };
        const handled = extra?.(url, init);
        if (handled) return handled;
        if (url.endsWith('/settings/inbox-channels') && init?.method !== 'PUT') return { status: 200, body: settings };
        if (url.endsWith('/settings/inbox-channels') && init?.method === 'PUT') return { status: 200, body: settings };
        if (url.includes('/inbox/unread-count')) return { status: 200, body: { count: 0 } };
        return { status: 404 };
    });
}

function bodies(calls: ReturnType<typeof mockApi>, suffix: string, method = 'PUT'): Record<string, unknown>[] {
    return calls.mock.calls
        .filter(([url, init]) => String(url).endsWith(suffix) && (init as RequestInit | undefined)?.method === method)
        .map(([, init]) => JSON.parse(String((init as RequestInit).body ?? '{}')) as Record<string, unknown>);
}

describe('inbox › channels › facebook page', () => {
    it('offers Continue with Facebook only once the Meta app is added, and links to the keys form', async () => {
        api(owner, { whatsapp: channel({ channel: 'whatsapp' }), messenger: channel({ channel: 'messenger' }) });
        renderApp('/integrations/messenger');

        expect(await screen.findByRole('heading', { name: 'Connect your Facebook Page' })).toBeTruthy();
        expect((screen.getByRole('button', { name: 'Continue with Facebook' }) as HTMLButtonElement).disabled).toBe(true);
        expect(screen.getByRole('link', { name: 'Add the Meta app' }).getAttribute('href')).toBe('/integrations/messaging/messenger#inbox-channel-settings');
        expect(screen.getByText(/Valid OAuth Redirect URIs: https:\/\/admin.toymartbd.com\/admin\/integrations\/messenger/)).toBeTruthy();
        expect(screen.getByText('Good to know before you start')).toBeTruthy();
        expect(screen.getByRole('link', { name: 'Enter keys manually' })).toBeTruthy();
    });

    it('sends the owner to the Facebook dialog the server built', async () => {
        const assign = vi.fn();
        vi.spyOn(window, 'location', 'get').mockReturnValue({ ...window.location, assign } as Location);
        api(owner, { whatsapp: channel({ channel: 'whatsapp' }), messenger: channel({ channel: 'messenger', login_ready: true, app_id: '1', app_id_source: 'settings' }) }, (url, init) =>
            url.endsWith('/messenger/login') && init?.method === 'POST' ? { status: 200, body: { url: 'https://www.facebook.com/v27.0/dialog/oauth?client_id=1', redirect_uri: 'x' } } : undefined,
        );
        renderApp('/integrations/messenger');

        fireEvent.click(await screen.findByRole('button', { name: 'Continue with Facebook' }));
        await waitFor(() => expect(assign).toHaveBeenCalledWith('https://www.facebook.com/v27.0/dialog/oauth?client_id=1'));
    });

    it('finishes the login from the redirect and connects the only Page shared, never seeing its token', async () => {
        let connected = false;
        const calls = api(owner, { whatsapp: channel({ channel: 'whatsapp' }), messenger: channel({ channel: 'messenger', login_ready: true }) }, (url, init) => {
            if (url.endsWith('/messenger/login/finish')) return { status: 200, body: { session: 'S'.repeat(48), pages: [{ id: '151784545352643', name: 'Smart Junior', can_message: true }] } };
            if (url.endsWith('/messenger/connect')) {
                connected = true;
                return { status: 200, body: { whatsapp: channel({ channel: 'whatsapp' }), messenger: connectedPage } };
            }
            if (url.endsWith('/settings/inbox-channels') && init?.method !== 'PUT' && connected) return { status: 200, body: { whatsapp: channel({ channel: 'whatsapp' }), messenger: connectedPage } };
            return undefined;
        });
        renderApp('/integrations/messenger?code=AQD-code&state=' + 'a'.repeat(48));

        await waitFor(() => expect(bodies(calls, '/messenger/connect', 'POST')).toEqual([{ session: 'S'.repeat(48), page_id: '151784545352643' }]));
        expect(bodies(calls, '/messenger/login/finish', 'POST')).toEqual([{ code: 'AQD-code', state: 'a'.repeat(48) }]);
        expect(await screen.findByRole('heading', { name: 'Smart Junior' })).toBeTruthy();
    });

    it('asks which Page when several were shared, and says when a Page cannot be messaged', async () => {
        api(owner, { whatsapp: channel({ channel: 'whatsapp' }), messenger: channel({ channel: 'messenger', login_ready: true }) }, (url) =>
            url.endsWith('/messenger/login/finish')
                ? {
                      status: 200,
                      body: {
                          session: 'S'.repeat(48),
                          pages: [
                              { id: '1', name: 'Smart Junior', can_message: true },
                              { id: '2', name: 'Babur Gallery', can_message: false },
                          ],
                      },
                  }
                : undefined,
        );
        renderApp('/integrations/messenger?code=c&state=' + 'a'.repeat(48));

        expect(await screen.findByRole('heading', { name: 'Choose the Page to connect' })).toBeTruthy();
        const buttons = screen.getAllByRole('button', { name: 'Connect this Page' }) as HTMLButtonElement[];
        expect(buttons.map((b) => b.disabled)).toEqual([false, true]);
        expect(screen.getByText('This account cannot manage the Page’s messages')).toBeTruthy();
    });

    it('explains a cancelled Facebook window', async () => {
        api(owner, { whatsapp: channel({ channel: 'whatsapp' }), messenger: channel({ channel: 'messenger', login_ready: true }) });
        renderApp('/integrations/messenger?error=access_denied&error_reason=user_denied');
        expect(await screen.findByText('The Facebook window was closed before the Page was shared. Nothing changed.')).toBeTruthy();
    });

    it('shows the connected Page: status, counts, actions, late replies and the webhook', async () => {
        api(owner, { whatsapp: channel({ channel: 'whatsapp' }), messenger: connectedPage });
        renderApp('/integrations/messenger');

        const card = within((await screen.findByRole('heading', { name: 'Smart Junior' })).closest('section')!);
        expect(card.getByText('Connected')).toBeTruthy();
        expect(card.getByText(/Page ID 151784545352643/)).toBeTruthy();
        expect(card.getByText(/connected by Mominul Islam/)).toBeTruthy();
        expect(card.getByText('12')).toBeTruthy();
        expect(card.getByText('from Rakibul Hasan')).toBeTruthy();
        expect(card.getByRole('switch', { name: 'Send replies through Messenger' }).getAttribute('aria-checked')).toBe('true');
        expect(card.getByRole('link', { name: 'Open chats' }).getAttribute('href')).toBe('/inbox?channel=messenger');
        expect(card.getByRole('button', { name: 'Reconnect Page' })).toBeTruthy();
        expect(screen.getByRole('switch', { name: 'Reply up to 7 days after the customer’s last message' })).toBeTruthy();
        expect(screen.getByText('https://admin.toymartbd.com/api/v1/webhooks/inbox/messenger')).toBeTruthy();
        expect(screen.getByText('••••e5f6 · saved')).toBeTruthy();
    });

    it('switching replies off keeps every saved id and secret (a body the schema accepts)', async () => {
        const calls = api(owner, { whatsapp: channel({ channel: 'whatsapp' }), messenger: connectedPage });
        renderApp('/integrations/messenger');

        fireEvent.click(await screen.findByRole('switch', { name: 'Send replies through Messenger' }));
        await waitFor(() => expect(bodies(calls, '/settings/inbox-channels')).toHaveLength(1));
        const body = bodies(calls, '/settings/inbox-channels')[0]!;
        expect(body).toEqual({ whatsapp_driver: 'log', whatsapp_phone_number_id: null, messenger_driver: 'log', messenger_page_id: '151784545352643' });
        expect(updateInboxChannelSettingsRequestSchema.safeParse(body).success).toBe(true);
    });

    it('shows Reconnect needed with the fix inside the warning', async () => {
        api(owner, { whatsapp: channel({ channel: 'whatsapp' }), messenger: { ...connectedPage, connection: { state: 'reconnect', reason: 'meta_190', since: '2026-11-19T22:42:00+06:00' } } });
        renderApp('/integrations/messenger');

        const alert = await screen.findByRole('alert');
        expect(within(alert).getByText('Messenger chats are not arriving')).toBeTruthy();
        expect(within(alert).getByRole('button', { name: 'Reconnect Page' })).toBeTruthy();
        expect(screen.getByText('Reconnect needed')).toBeTruthy();
    });

    it('disconnects only after confirming', async () => {
        const calls = api(owner, { whatsapp: channel({ channel: 'whatsapp' }), messenger: connectedPage }, (url) =>
            url.endsWith('/messenger/disconnect') ? { status: 200, body: { whatsapp: channel({ channel: 'whatsapp' }), messenger: channel({ channel: 'messenger', login_ready: true }) } } : undefined,
        );
        renderApp('/integrations/messenger');

        fireEvent.click(await screen.findByRole('button', { name: 'Disconnect' }));
        expect(calls.mock.calls.some(([url]) => String(url).endsWith('/messenger/disconnect'))).toBe(false);
        const dialog = await screen.findByRole('dialog', { name: 'Disconnect the Facebook Page?' });
        fireEvent.click(within(dialog).getByRole('button', { name: 'Disconnect' }));
        await waitFor(() => expect(calls.mock.calls.some(([url]) => String(url).endsWith('/messenger/disconnect'))).toBe(true));
        expect(await screen.findByRole('heading', { name: 'Connect your Facebook Page' })).toBeTruthy();
    });

    it('is closed to staff without settings.manage_integrations, and the sidebar shows them no channel links', async () => {
        api(agent, { whatsapp: channel({ channel: 'whatsapp' }), messenger: channel({ channel: 'messenger' }) });
        renderApp('/integrations/messenger');
        expect(await screen.findByText('You do not have access to this page.')).toBeTruthy();
        expect(screen.queryByRole('link', { name: 'Facebook Page' })).toBeNull();
        expect(screen.queryByRole('list', { name: 'Integrations' })).toBeNull();
    });

    it('sits in the Integrations submenu, not under Inbox', async () => {
        api(owner, { whatsapp: channel({ channel: 'whatsapp' }), messenger: channel({ channel: 'messenger' }) });
        renderApp('/integrations/messenger');
        const submenu = (await screen.findAllByRole('list', { name: 'Integrations' }))[0]!;
        expect(within(submenu).getByRole('link', { name: 'Facebook Page' }).getAttribute('aria-current')).toBe('page');
        expect(within(submenu).getByRole('link', { name: 'All integrations' }).getAttribute('aria-current')).toBeNull();
        expect(within(submenu).getByRole('link', { name: 'WhatsApp' }).getAttribute('href')).toBe('/integrations/whatsapp');
        expect(screen.queryByRole('list', { name: 'Inbox' })).toBeNull();
    });

    it('redirects the old Inbox › Channels URL, keeping a Facebook login return', async () => {
        api(owner, { whatsapp: channel({ channel: 'whatsapp' }), messenger: channel({ channel: 'messenger', login_ready: true }) });
        renderApp('/inbox/channels/messenger?error=access_denied&error_reason=user_denied');
        expect(await screen.findByText('The Facebook window was closed before the Page was shared. Nothing changed.')).toBeTruthy();
        expect(screen.getByRole('heading', { name: 'Facebook Page', level: 1 })).toBeTruthy();
    });
});

describe('inbox › channels › whatsapp', () => {
    it('walks the guided setup: each step saves, the last one checks with Meta before replies switch on', async () => {
        let tested = false;
        const calls = api(owner, { whatsapp: channel({ channel: 'whatsapp' }), messenger: channel({ channel: 'messenger' }) }, (url) => {
            if (url.endsWith('/whatsapp/test')) {
                tested = true;
                return { status: 200, body: { channel: 'whatsapp', ok: true, checked_at: new Date().toISOString(), display_name: 'Smart Junior', display_number: '+880 1711-000222', quality_rating: 'GREEN', error_code: null, error_message: null } };
            }
            return undefined;
        });
        renderApp('/integrations/whatsapp');

        expect(await screen.findByRole('heading', { name: 'Connect your WhatsApp Business number' })).toBeTruthy();
        fireEvent.click(screen.getByRole('button', { name: 'Start setup' }));
        const dialog = within(await screen.findByRole('dialog', { name: 'Set up WhatsApp' }));

        // Step 1 needs the app secret.
        fireEvent.click(dialog.getByRole('button', { name: 'Next: number IDs' }));
        expect(await dialog.findByText('Needed to continue.')).toBeTruthy();
        fireEvent.change(dialog.getByLabelText(/^App secret/), { target: { value: 'wa-app-secret' } });
        fireEvent.click(dialog.getByRole('button', { name: 'Next: number IDs' }));

        // Step 2: digits only.
        fireEvent.change(await dialog.findByLabelText(/^Phone number ID/), { target: { value: '10654abc' } });
        fireEvent.click(dialog.getByRole('button', { name: 'Next: permanent token' }));
        expect(await dialog.findByText('Digits only.')).toBeTruthy();
        fireEvent.change(dialog.getByLabelText(/^Phone number ID/), { target: { value: '106540352242922' } });
        fireEvent.change(dialog.getByLabelText(/^WhatsApp Business Account ID/), { target: { value: '102938475610293' } });
        fireEvent.click(dialog.getByRole('button', { name: 'Next: permanent token' }));

        // Step 3: the token.
        fireEvent.change(await dialog.findByLabelText(/^Access token/), { target: { value: 'EAAG-system-user' } });
        fireEvent.click(dialog.getByRole('button', { name: 'Next: webhook' }));

        // Step 4: make a verify token, then check.
        fireEvent.click(await dialog.findByRole('button', { name: 'Make a verify token' }));
        expect(dialog.getByText('Copy it now — after saving only its last 4 characters are shown.')).toBeTruthy();
        fireEvent.click(dialog.getByRole('button', { name: 'Check and connect' }));

        await waitFor(() => expect(bodies(calls, '/settings/inbox-channels')).toHaveLength(5));
        const puts = bodies(calls, '/settings/inbox-channels');
        expect(puts[0]).toMatchObject({ whatsapp_app_secret: 'wa-app-secret', whatsapp_driver: 'log' });
        expect(puts[1]).toMatchObject({ whatsapp_phone_number_id: '106540352242922', whatsapp_business_account_id: '102938475610293' });
        expect(puts[2]).toMatchObject({ whatsapp_access_token: 'EAAG-system-user' });
        expect(puts[3]!.whatsapp_verify_token).toMatch(/^[0-9a-f]{48}$/);
        expect(tested).toBe(true);
        expect(puts[4]).toMatchObject({ whatsapp_driver: 'cloud' });
        for (const body of puts) expect(updateInboxChannelSettingsRequestSchema.safeParse(body).success).toBe(true);
    });

    it('keeps replies off and says why when Meta refuses the details', async () => {
        const calls = api(
            owner,
            {
                whatsapp: channel({ channel: 'whatsapp', app_secret: secret('••••1111'), account_id: '106540352242922', account_id_source: 'settings', send_token: secret('••••2222'), verify_token: secret('••••3333'), send_ready: true }),
                messenger: channel({ channel: 'messenger' }),
            },
            (url) =>
                url.endsWith('/whatsapp/test')
                    ? { status: 200, body: { channel: 'whatsapp', ok: false, checked_at: new Date().toISOString(), display_name: null, display_number: null, quality_rating: null, error_code: 'meta_190', error_message: 'Session has expired.' } }
                    : undefined,
        );
        renderApp('/integrations/whatsapp');

        fireEvent.click(await screen.findByRole('button', { name: 'Continue setup' }));
        const dialog = within(await screen.findByRole('dialog', { name: 'Set up WhatsApp' }));
        fireEvent.click(await dialog.findByRole('button', { name: 'Check and connect' }));
        expect(await dialog.findByText(/Meta did not accept these details: Meta no longer accepts the access token. Session has expired./)).toBeTruthy();
        expect(bodies(calls, '/settings/inbox-channels').some((b) => b.whatsapp_driver === 'cloud')).toBe(false);
    });

    it('shows a connected number with its quality and templates link', async () => {
        api(owner, {
            whatsapp: channel({
                channel: 'whatsapp',
                driver: 'cloud',
                account_id: '106540352242922',
                account_id_source: 'settings',
                business_account_id: '102938475610293',
                send_token: secret('••••2222'),
                send_ready: true,
                connection: { state: 'connected', reason: null, since: null },
                last_check: { ok: true, checked_at: new Date().toISOString(), display_name: 'Smart Junior', display_number: '+880 1711-000222', quality_rating: 'GREEN', error_code: null, error_message: null },
            }),
            messenger: channel({ channel: 'messenger' }),
        });
        renderApp('/integrations/whatsapp');

        const card = within((await screen.findByRole('heading', { name: '+880 1711-000222' })).closest('section')!);
        expect(card.getByText('Quality: High')).toBeTruthy();
        expect(card.getByText(/Business Account 102938475610293/)).toBeTruthy();
        expect(card.getByRole('link', { name: 'Message templates' }).getAttribute('href')).toBe('/automations/templates');
        expect(screen.getByText(/Meta answered with the number \+880 1711-000222/)).toBeTruthy();
    });
});
