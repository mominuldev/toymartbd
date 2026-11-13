import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import type { Schema } from '@toymart/api-types';
import type { UpdateAutomationSettingsRequestInput } from '@toymart/api-types/zod';
import { api } from '@/lib/api';

/** Scheduling for the review-request and birthday automations (P8) and what they did lately. */
export type AutomationSettings = Schema<'AutomationSettings'>;
/** The PUT body — generated, never hand-written (rule 27). */
export type AutomationSettingsInput = UpdateAutomationSettingsRequestInput;

/** Template key → automation key in `stats`. */
export const scheduledAutomations: Record<string, 'review_request' | 'birthday'> = {
    'review.request': 'review_request',
    'customer.birthday': 'birthday',
};

const key = ['notifications', 'automations'] as const;

export function useAutomationSettings(enabled = true) {
    return useQuery({ queryKey: key, queryFn: () => api<AutomationSettings>('/notifications/automations'), enabled });
}

export function useSaveAutomationSettings() {
    const client = useQueryClient();
    return useMutation({
        mutationFn: (input: AutomationSettingsInput) => api<AutomationSettings>('/notifications/automations', { method: 'PUT', body: JSON.stringify(input) }),
        onSuccess: (saved) => client.setQueryData(key, saved),
    });
}
