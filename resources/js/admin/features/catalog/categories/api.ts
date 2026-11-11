import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import type { Schema } from '@toymart/api-types';
import { api } from '@/lib/api';

export type Category = Schema<'Category'>;
export type CategoryInput = {
    name: string;
    name_bn?: string | null;
    slug?: string | null;
    parent_id?: number | null;
    description?: string | null;
    description_bn?: string | null;
    icon?: string | null;
    is_featured?: boolean;
    show_in_menu?: boolean;
    is_active?: boolean;
};
export type MoveInput = { id: number; parent_id: number | null; position: number };

const keys = {
    all: ['categories'] as const,
    tree: ['categories', 'tree'] as const,
    one: (id: number) => ['categories', 'one', id] as const,
};

/** The whole tree, archived included (`?tree=1`). Categories are few; one request. */
export function useCategoryTree() {
    return useQuery({
        queryKey: keys.tree,
        queryFn: () => api<{ data: Category[] }>('/categories?tree=1').then((body) => body.data),
    });
}

export function useCategory(id: number | null) {
    return useQuery({
        queryKey: keys.one(id ?? 0),
        queryFn: () => api<Category>(`/categories/${id}`),
        enabled: id !== null,
    });
}

/** Create or edit. The parent of an existing category changes only through useMoveCategory. */
export function useSaveCategory(id: number | null) {
    const client = useQueryClient();

    return useMutation({
        mutationFn: (input: CategoryInput) => {
            if (id === null) return api<Category>('/categories', { method: 'POST', body: JSON.stringify(input) });
            const { parent_id: _parent, ...changes } = input;
            return api<Category>(`/categories/${id}`, { method: 'PATCH', body: JSON.stringify(changes) });
        },
        onSuccess: (category) => {
            client.setQueryData(keys.one(category.id), category);
            void client.invalidateQueries({ queryKey: keys.tree });
        },
    });
}

export function useMoveCategory() {
    const client = useQueryClient();

    return useMutation({
        mutationFn: ({ id, parent_id, position }: MoveInput) =>
            api<Category>(`/categories/${id}/move`, { method: 'POST', body: JSON.stringify({ parent_id, position }) }),
        onSuccess: (category) => {
            client.setQueryData(keys.one(category.id), category);
            return client.invalidateQueries({ queryKey: keys.tree });
        },
    });
}

export function useArchiveCategory(id: number) {
    const client = useQueryClient();

    return useMutation({
        mutationFn: () => api<void>(`/categories/${id}`, { method: 'DELETE' }),
        onSuccess: () => void client.invalidateQueries({ queryKey: keys.all }),
    });
}

/** Tree nodes in display order with their depth, for selects and lookups. */
export function flatten(nodes: Category[], depth = 0): { node: Category; depth: number }[] {
    return nodes.flatMap((node) => [{ node, depth }, ...flatten(node.children ?? [], depth + 1)]);
}

/** Levels below a node (0 for a leaf). */
export function heightOf(node: Category): number {
    const children = node.children ?? [];
    return children.length === 0 ? 0 : 1 + Math.max(...children.map(heightOf));
}

/** The tree is at most three levels: depths 0, 1 and 2 (App\Domain\Catalog\Models\Category::MAX_DEPTH). */
export const MAX_DEPTH = 2;
