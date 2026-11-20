import { useMutation, useQueryClient } from '@tanstack/react-query';
import type { Schema } from '@toymart/api-types';
import { api } from '@/lib/api';
import type { InboxChannelSettings, InboxChannelSettingsInput } from '../api';

export type Channel = 'whatsapp' | 'messenger';
export type MessengerLoginStart = Schema<'MessengerLoginStart'>;
export type MessengerLoginPages = Schema<'MessengerLoginPages'>;

const settingsKey = ['settings', 'inbox-channels'] as const;

function useStoreSettings() {
    const client = useQueryClient();
    return (saved: InboxChannelSettings) => {
        client.setQueryData(settingsKey, saved);
        // The Integrations hub and the Inbox read the same readiness.
        void client.invalidateQueries({ queryKey: ['integrations'] });
        void client.invalidateQueries({ queryKey: ['inbox'] });
    };
}

/** "Continue with Facebook", step 1: the dialog URL (state bound to this staff member). */
export function useStartMessengerLogin() {
    return useMutation({
        mutationFn: () => api<MessengerLoginStart>('/settings/inbox-channels/messenger/login', { method: 'POST' }),
    });
}

/** Step 2: `code` + `state` from the redirect → the Pages shared (ids and names; tokens stay on the server). */
export function useFinishMessengerLogin() {
    return useMutation({
        mutationFn: (input: { code: string; state: string }) =>
            api<MessengerLoginPages>('/settings/inbox-channels/messenger/login/finish', { method: 'POST', body: JSON.stringify(input) }),
    });
}

/** Step 3: subscribe and save the chosen Page; returns the channel settings. */
export function useConnectMessengerPage() {
    const store = useStoreSettings();
    return useMutation({
        mutationFn: (input: { session: string; page_id: string }) =>
            api<InboxChannelSettings>('/settings/inbox-channels/messenger/connect', { method: 'POST', body: JSON.stringify(input) }),
        onSuccess: store,
    });
}

export function useDisconnectChannel() {
    const store = useStoreSettings();
    return useMutation({
        mutationFn: (channel: Channel) => api<InboxChannelSettings>(`/settings/inbox-channels/${channel}/disconnect`, { method: 'POST' }),
        onSuccess: store,
    });
}

/**
 * The PUT body that changes nothing: drivers as they are, each account id only when it was saved
 * here (a blank id removes a saved one; an env id is not ours to copy), secrets blank (= keep), and
 * the optional fields left out (= keep). Callers spread their one change over it.
 */
export function keepAll(s: InboxChannelSettings): InboxChannelSettingsInput {
    return {
        whatsapp_driver: s.whatsapp.driver as InboxChannelSettingsInput['whatsapp_driver'],
        whatsapp_phone_number_id: s.whatsapp.account_id_source === 'settings' ? s.whatsapp.account_id : null,
        messenger_driver: s.messenger.driver as InboxChannelSettingsInput['messenger_driver'],
        messenger_page_id: s.messenger.account_id_source === 'settings' ? s.messenger.account_id : null,
    };
}
