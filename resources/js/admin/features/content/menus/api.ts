import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import type { ApiMenuItem, LinkType, MenuItemPayload, MenuTarget } from './tree';

export type MenuLocation = 'header' | 'footer' | 'mobile';
export const menuLocations: readonly MenuLocation[] = ['header', 'footer', 'mobile'];

/** `GET /content/menus/{location}` — typed here: the generated schema mistypes `exists`. */
export type Menu = {
    location: MenuLocation;
    exists: boolean;
    is_published: boolean;
    published_at: string | null;
    updated_at: string | null;
    max_depth: number;
    max_items: number;
    items: ApiMenuItem[];
};

export type LinkTarget = MenuTarget & { id: number };

const keys = {
    one: (location: MenuLocation) => ['content-menus', location] as const,
    targets: (type: LinkType, search: string) => ['content-menu-targets', type, search] as const,
};

export function isMenuLocation(value: string | undefined): value is MenuLocation {
    return value !== undefined && (menuLocations as readonly string[]).includes(value);
}

export function useMenu(location: MenuLocation) {
    return useQuery({ queryKey: keys.one(location), queryFn: () => api<Menu>(`/content/menus/${location}`) });
}

export function useSaveMenu(location: MenuLocation) {
    const client = useQueryClient();
    return useMutation({
        mutationFn: (body: { is_published: boolean; items: MenuItemPayload[] }) =>
            api<Menu>(`/content/menus/${location}`, { method: 'PUT', body: JSON.stringify(body) }),
        onSuccess: (menu) => client.setQueryData(keys.one(location), menu),
    });
}

export function useLinkTargets(type: LinkType, search: string, enabled: boolean) {
    return useQuery({
        queryKey: keys.targets(type, search),
        queryFn: () => {
            const params = new URLSearchParams({ type });
            if (search) params.set('search', search);
            return api<{ data: LinkTarget[] }>(`/content/menu-targets?${params.toString()}`);
        },
        enabled,
    });
}
