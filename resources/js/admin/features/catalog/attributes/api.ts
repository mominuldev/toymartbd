import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import type { Schema } from '@toymart/api-types';
import { api } from '@/lib/api';

export type Attribute = Schema<'Attribute'>;
export type AttributeValue = Schema<'AttributeValue'>;
export type AttributeType = Attribute['type'];
export type AttributeInput = {
    name: string;
    name_bn?: string | null;
    slug?: string | null;
    type?: AttributeType;
    is_variant_defining?: boolean;
    is_filterable?: boolean;
};
export type ValueInput = { value: string; value_bn?: string | null; slug?: string | null; color_hex?: string | null };

export const attributeTypes: AttributeType[] = ['select', 'color', 'text'];

const keys = {
    all: ['attributes'] as const,
    list: ['attributes', 'list'] as const,
    one: (id: number) => ['attributes', 'one', id] as const,
};

export function useAttributes() {
    return useQuery({
        queryKey: keys.list,
        queryFn: () => api<{ data: Attribute[] }>('/attributes').then((body) => body.data),
    });
}

export function useAttribute(id: number | null) {
    return useQuery({
        queryKey: keys.one(id ?? 0),
        queryFn: () => api<Attribute>(`/attributes/${id}`),
        enabled: id !== null,
    });
}

export function useSaveAttribute(id: number | null) {
    const client = useQueryClient();

    return useMutation({
        mutationFn: (input: AttributeInput) =>
            id === null
                ? api<Attribute>('/attributes', { method: 'POST', body: JSON.stringify(input) })
                : api<Attribute>(`/attributes/${id}`, { method: 'PATCH', body: JSON.stringify(input) }),
        onSuccess: (attribute) => {
            client.setQueryData(keys.one(attribute.id), attribute);
            void client.invalidateQueries({ queryKey: keys.list });
        },
    });
}

export function useDeleteAttribute(id: number) {
    const client = useQueryClient();

    return useMutation({
        mutationFn: () => api<void>(`/attributes/${id}`, { method: 'DELETE' }),
        onSuccess: () => void client.invalidateQueries({ queryKey: keys.all }),
    });
}

/** Value writes all refresh the parent attribute, which carries the ordered values. */
export function useValueMutations(attributeId: number) {
    const client = useQueryClient();
    const refresh = () => client.invalidateQueries({ queryKey: keys.all });
    const base = `/attributes/${attributeId}/values`;

    return {
        add: useMutation({
            mutationFn: (input: ValueInput) => api<AttributeValue>(base, { method: 'POST', body: JSON.stringify(input) }),
            onSuccess: refresh,
        }),
        update: useMutation({
            mutationFn: ({ id, ...input }: ValueInput & { id: number }) => api<AttributeValue>(`${base}/${id}`, { method: 'PATCH', body: JSON.stringify(input) }),
            onSuccess: refresh,
        }),
        remove: useMutation({
            mutationFn: (id: number) => api<void>(`${base}/${id}`, { method: 'DELETE' }),
            onSuccess: refresh,
        }),
        reorder: useMutation({
            mutationFn: (ids: number[]) => api<Attribute>(`${base}/reorder`, { method: 'POST', body: JSON.stringify({ ids }) }),
            onSuccess: (attribute) => {
                client.setQueryData(keys.one(attribute.id), attribute);
                return refresh();
            },
        }),
    };
}
