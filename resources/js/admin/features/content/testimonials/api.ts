import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import type { Schema } from '@toymart/api-types';
import type { TestimonialRequestInput } from '@toymart/api-types/zod';
import { api } from '@/lib/api';

export type Testimonial = Schema<'Testimonial'>;
export type TestimonialInput = TestimonialRequestInput;

const keys = {
    all: ['content-testimonials'] as const,
    list: ['content-testimonials', 'list'] as const,
    one: (id: number) => ['content-testimonials', 'one', id] as const,
};

export function useTestimonials() {
    return useQuery({ queryKey: keys.list, queryFn: () => api<{ data: Testimonial[] }>('/content/testimonials').then((b) => b.data) });
}

export function useTestimonial(id: number | null) {
    return useQuery({ queryKey: keys.one(id ?? 0), queryFn: () => api<Testimonial>(`/content/testimonials/${id}`), enabled: id !== null });
}

export function useSaveTestimonial(id: number | null) {
    const client = useQueryClient();
    return useMutation({
        mutationFn: (input: TestimonialInput) =>
            id === null
                ? api<Testimonial>('/content/testimonials', { method: 'POST', body: JSON.stringify(input) })
                : api<Testimonial>(`/content/testimonials/${id}`, { method: 'PUT', body: JSON.stringify(input) }),
        onSuccess: (saved) => {
            client.setQueryData(keys.one(saved.id), saved);
            void client.invalidateQueries({ queryKey: keys.list });
        },
    });
}

export function useDeleteTestimonial(id: number) {
    const client = useQueryClient();
    return useMutation({
        mutationFn: () => api<void>(`/content/testimonials/${id}`, { method: 'DELETE' }),
        onSuccess: () => void client.invalidateQueries({ queryKey: keys.all }),
    });
}

/** `POST /content/testimonials/reorder`: every id once, in the new order. Optimistic. */
export function useReorderTestimonials() {
    const client = useQueryClient();
    return useMutation({
        mutationFn: (ids: number[]) => api<{ data: Testimonial[] }>('/content/testimonials/reorder', { method: 'POST', body: JSON.stringify({ ids }) }).then((b) => b.data),
        onMutate: (ids) => {
            const previous = client.getQueryData<Testimonial[]>(keys.list);
            if (previous) client.setQueryData(keys.list, ids.map((id) => previous.find((t) => t.id === id)).filter((t): t is Testimonial => t !== undefined));
            return { previous };
        },
        onError: (_error, _ids, context) => {
            if (context?.previous) client.setQueryData(keys.list, context.previous);
        },
        onSuccess: (list) => client.setQueryData(keys.list, list),
    });
}
