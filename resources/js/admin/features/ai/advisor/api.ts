import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import type { Schema } from '@toymart/api-types';
import type { AskAdvisorRequestInput } from '@toymart/api-types/zod';
import { api } from '@/lib/api';

export type GrowthAdvisor = Schema<'GrowthAdvisor'>;
export type AdvisorBrief = NonNullable<GrowthAdvisor['brief']>;
export type AdvisorAction = Schema<'AdvisorAction'>;
export type AdvisorAnswer = Schema<'AdvisorAnswer'>;
export type AdvisorLink = NonNullable<AdvisorAction['link']>;
export type AdvisorSource = AdvisorAnswer['sources'][number];

const key = ['analytics', 'advisor'] as const;

/** `GET /analytics/advisor` — the latest brief, its open suggestions and done-this-month. */
export function useGrowthAdvisor() {
    return useQuery({ queryKey: key, queryFn: () => api<GrowthAdvisor>('/analytics/advisor') });
}

/** `POST /analytics/advisor/brief` — write (or rewrite) this week's brief; the answer is the whole screen. */
export function useRefreshBrief() {
    const client = useQueryClient();
    return useMutation({
        mutationFn: () => api<GrowthAdvisor>('/analytics/advisor/brief', { method: 'POST' }),
        onSuccess: (data) => client.setQueryData(key, data),
    });
}

/** `POST /analytics/advisor/actions/{id}/done|dismiss` — then the counts are re-read from the server. */
export function useDecideAction() {
    const client = useQueryClient();
    return useMutation({
        mutationFn: ({ id, decision }: { id: number; decision: 'done' | 'dismiss' }) => api<AdvisorAction>(`/analytics/advisor/actions/${id}/${decision}`, { method: 'POST' }),
        onSuccess: () => client.invalidateQueries({ queryKey: key }),
    });
}

/** `POST /analytics/advisor/ask` — one question, answered from the report figures. */
export function useAskAdvisor() {
    return useMutation({
        mutationFn: (input: AskAdvisorRequestInput) => api<AdvisorAnswer>('/analytics/advisor/ask', { method: 'POST', body: JSON.stringify(input) }),
    });
}
