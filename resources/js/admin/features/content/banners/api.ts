import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import type { Schema } from '@toymart/api-types';
import type { BannerRequestInput } from '@toymart/api-types/zod';
import { api } from '@/lib/api';

export type Banner = Schema<'Banner'>;
export type BannerPlacement = Banner['placement'];
export type BannerInput = BannerRequestInput;
export const placements: readonly BannerPlacement[] = ['announcement_bar', 'category_page', 'info_strip', 'popup'];

const keys = {
    all: ['content-banners'] as const,
    list: (placement: BannerPlacement | 'all') => ['content-banners', 'list', placement] as const,
    one: (id: number) => ['content-banners', 'one', id] as const,
};

export function useBanners(placement: BannerPlacement | 'all') {
    return useQuery({
        queryKey: keys.list(placement),
        queryFn: () => api<{ data: Banner[] }>(`/content/banners${placement === 'all' ? '' : `?filter%5Bplacement%5D=${placement}`}`),
    });
}

export function useBanner(id: number | null) {
    return useQuery({ queryKey: keys.one(id ?? 0), queryFn: () => api<Banner>(`/content/banners/${id}`), enabled: id !== null });
}

export function useSaveBanner(id: number | null) {
    const client = useQueryClient();
    return useMutation({
        mutationFn: (input: BannerInput) =>
            id === null
                ? api<Banner>('/content/banners', { method: 'POST', body: JSON.stringify(input) })
                : api<Banner>(`/content/banners/${id}`, { method: 'PUT', body: JSON.stringify(input) }),
        onSuccess: (banner) => {
            client.setQueryData(keys.one(banner.id), banner);
            void client.invalidateQueries({ queryKey: [...keys.all, 'list'] });
        },
    });
}

export function useDeleteBanner(id: number) {
    const client = useQueryClient();
    return useMutation({
        mutationFn: () => api<void>(`/content/banners/${id}`, { method: 'DELETE' }),
        onSuccess: () => void client.invalidateQueries({ queryKey: keys.all }),
    });
}
