import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import type { Schema } from '@toymart/api-types';
import type { RestockNoteRequestInput } from '@toymart/api-types/zod';
import { api } from '@/lib/api';

export type RestockForecast = Schema<'RestockForecast'>;
export type RestockRow = RestockForecast['rows'][number];
export type RestockNote = Schema<'RestockNote'>;
export type RestockConfidence = RestockRow['confidence'];

export const horizons = ['7', '14', '30'] as const;
export type Horizon = (typeof horizons)[number];

const keys = {
    forecast: (horizon: Horizon) => ['inventory', 'restock', horizon] as const,
};

/** `GET /inventory/restock?horizon=…` — every figure is the server's (rule 1). */
export function useRestockForecast(horizon: Horizon) {
    return useQuery({
        queryKey: keys.forecast(horizon),
        queryFn: () => api<RestockForecast>(`/inventory/restock?horizon=${horizon}`),
        placeholderData: keepPreviousData,
    });
}

/** `POST /inventory/restock/note` — the AI note for the forecast at this horizon (cached server-side). */
export function useRestockNote(horizon: Horizon) {
    const client = useQueryClient();
    return useMutation({
        mutationFn: (input: RestockNoteRequestInput) => api<RestockNote>('/inventory/restock/note', { method: 'POST', body: JSON.stringify(input) }),
        onSuccess: (note) => {
            if (note.model === null || note.generated_at === null) return;
            const { model, generated_at } = note;
            client.setQueryData<RestockForecast>(keys.forecast(horizon), (old) => (old ? { ...old, note: { sentences: note.sentences, model, generated_at } } : old));
        },
    });
}
