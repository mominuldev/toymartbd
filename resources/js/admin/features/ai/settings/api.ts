import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import type { Schema } from '@toymart/api-types';
import type { UpdateAiSettingsRequestInput } from '@toymart/api-types/zod';
import { api } from '@/lib/api';

/**
 * Integrations › AI assistant (Figma 536:82215). Every answer is the whole `AiSettings`
 * document: providers (never a key — only its last four), routing, cap, sharing, this month's
 * estimated spend and what the viewer may change.
 */
export type AiSettings = Schema<'AiSettings'>;
export type AiProviderInfo = AiSettings['providers'][number];
export type AiProviderId = AiProviderInfo['provider'];
export type AiFeatureId = AiSettings['features'][number]['feature'];
export type AiSettingsInput = UpdateAiSettingsRequestInput;

export const aiSettingsKey = ['ai', 'settings'] as const;
const PATH = '/settings/ai';

/** GET /settings/ai (settings.view). */
export function useAiSettings(enabled: boolean) {
    return useQuery({ queryKey: aiSettingsKey, queryFn: () => api<AiSettings>(PATH), enabled });
}

function useStore() {
    const client = useQueryClient();
    return (next: AiSettings) => {
        client.setQueryData(aiSettingsKey, next);
        // The Integrations hub shows the AI providers' status too.
        void client.invalidateQueries({ queryKey: ['integrations'] });
    };
}

/** PUT /settings/ai — routing, cap and sharing (settings.update). */
export function useSaveAiSettings() {
    const store = useStore();
    return useMutation({
        mutationFn: (input: AiSettingsInput) => api<AiSettings>(PATH, { method: 'PUT', body: JSON.stringify(input) }),
        onSuccess: store,
    });
}

/** PUT /settings/ai/providers/{provider} — the server checks the key with a real call before saving it. */
export function useConnectAiProvider() {
    const store = useStore();
    return useMutation({
        mutationFn: ({ provider, apiKey }: { provider: AiProviderId; apiKey: string }) =>
            api<AiSettings>(`${PATH}/providers/${provider}`, { method: 'PUT', body: JSON.stringify({ api_key: apiKey }) }),
        onSuccess: store,
    });
}

/** DELETE /settings/ai/providers/{provider}. */
export function useForgetAiProvider() {
    const store = useStore();
    return useMutation({
        mutationFn: (provider: AiProviderId) => api<AiSettings>(`${PATH}/providers/${provider}`, { method: 'DELETE' }),
        onSuccess: store,
    });
}

/** POST /settings/ai/providers/{provider}/check — re-tests the saved key. */
export function useCheckAiProvider() {
    const store = useStore();
    return useMutation({
        mutationFn: (provider: AiProviderId) => api<AiSettings>(`${PATH}/providers/${provider}/check`, { method: 'POST' }),
        onSuccess: store,
    });
}
