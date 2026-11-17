import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import type { Schema } from '@toymart/api-types';
import { api } from '@/lib/api';

/** One outside service with a server-derived status (Figma B · 37). No secret is ever part of it. */
export type Integration = Schema<'Integration'>;
export type IntegrationCategory = Integration['category'];
export type IntegrationStatus = Integration['status'];

export const integrationCategories: IntegrationCategory[] = ['couriers', 'payments', 'messaging', 'marketing', 'ai'];

const key = ['integrations'] as const;

export function useIntegrations(enabled: boolean) {
    return useQuery({ queryKey: key, queryFn: () => api<{ data: Integration[] }>('/integrations').then((r) => r.data), enabled });
}

/**
 * The one on/off this screen offers: a courier, through the existing
 * `PATCH /settings/couriers/{id}` (shipments.manage_rates). The server refuses switching off
 * the default or the last active courier; the caller shows that message.
 */
export function useToggleCourier() {
    const client = useQueryClient();

    return useMutation({
        mutationFn: ({ id, active }: { id: number; active: boolean }) =>
            api<unknown>(`/settings/couriers/${id}`, { method: 'PATCH', body: JSON.stringify({ is_active: active }) }),
        onSettled: () => {
            void client.invalidateQueries({ queryKey: key });
            void client.invalidateQueries({ queryKey: ['couriers', 'settings'] });
        },
    });
}
