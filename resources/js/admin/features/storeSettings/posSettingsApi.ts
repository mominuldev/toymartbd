import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import type { Schema } from '@toymart/api-types';
import type { UpdatePosSettingsRequestInput } from '@toymart/api-types/zod';
import { api } from '@/lib/api';

/** `GET /settings/pos` — the cap on a cashier's discount (rule 33d). */
export type PosSettings = Schema<'PosSettings'>;
/** The `PUT /settings/pos` body — the generated request type (rule 27). */
export type PosSettingsInput = UpdatePosSettingsRequestInput;

export const posSettingsKey = ['settings', 'pos'] as const;

export function usePosSettings(enabled: boolean) {
    return useQuery({ queryKey: posSettingsKey, queryFn: () => api<PosSettings>('/settings/pos'), enabled });
}

export function useSavePosSettings() {
    const client = useQueryClient();

    return useMutation({
        mutationFn: (input: PosSettingsInput) => api<PosSettings>('/settings/pos', { method: 'PUT', body: JSON.stringify(input) }),
        onSuccess: (saved) => {
            client.setQueryData(posSettingsKey, saved);
            // The register's quotes carry the cap: the next one must use the new value.
            void client.invalidateQueries({ queryKey: ['pos', 'quote'] });
        },
    });
}
