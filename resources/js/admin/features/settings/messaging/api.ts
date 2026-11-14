import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import type { Schema } from '@toymart/api-types';
import type { SendTestSmsRequestInput, UpdateInboxChannelSettingsRequestInput, UpdateMessagingSettingsRequestInput } from '@toymart/api-types/zod';
import { api } from '@/lib/api';

/** What GET returns: effective values; secrets only as set / source / last-4 hint, never the value. */
export type MessagingSettings = Schema<'MessagingSettings'>;
export type SmsTestResult = Schema<'SmsTestResult'>;
/** The PUT body — the generated request type, never a hand-written copy (rule 27). */
export type MessagingSettingsInput = UpdateMessagingSettingsRequestInput;
export type SmsTestInput = SendTestSmsRequestInput;

/** Drivers the owner can pick here. `sms_net_bd` is an unverified env-only skeleton. */
export const smsDrivers = ['log', 'null', 'reve'] as const;
export type SmsDriver = (typeof smsDrivers)[number];

const key = ['settings', 'messaging'] as const;

export function useMessagingSettings(enabled: boolean) {
    return useQuery({ queryKey: key, queryFn: () => api<MessagingSettings>('/settings/messaging'), enabled });
}

export function useSaveMessagingSettings() {
    const client = useQueryClient();

    return useMutation({
        mutationFn: (input: MessagingSettingsInput) => api<MessagingSettings>('/settings/messaging', { method: 'PUT', body: JSON.stringify(input) }),
        onSuccess: (saved) => client.setQueryData(key, saved),
    });
}

/** One real SMS through the configured driver; the server decides acceptance, segments and cost. */
export function useSendTestSms() {
    return useMutation({
        mutationFn: (input: SmsTestInput) => api<SmsTestResult>('/settings/messaging/test', { method: 'POST', body: JSON.stringify(input) }),
    });
}

/* Inbox channels (WhatsApp Cloud API, Messenger) — `/settings/inbox-channels`, same permission. */

export type InboxChannelSettings = Schema<'InboxChannelSettings'>;
export type InboxChannelCredentials = Schema<'InboxChannelCredentials'>;
/** The PUT body — generated from the FormRequest (rule 27). */
export type InboxChannelSettingsInput = UpdateInboxChannelSettingsRequestInput;

const inboxKey = ['settings', 'inbox-channels'] as const;

export function useInboxChannelSettings(enabled: boolean) {
    return useQuery({ queryKey: inboxKey, queryFn: () => api<InboxChannelSettings>('/settings/inbox-channels'), enabled });
}

export function useSaveInboxChannelSettings() {
    const client = useQueryClient();

    return useMutation({
        mutationFn: (input: InboxChannelSettingsInput) => api<InboxChannelSettings>('/settings/inbox-channels', { method: 'PUT', body: JSON.stringify(input) }),
        onSuccess: (saved) => {
            client.setQueryData(inboxKey, saved);
            // The Integrations hub reads the same readiness.
            void client.invalidateQueries({ queryKey: ['integrations'] });
        },
    });
}
