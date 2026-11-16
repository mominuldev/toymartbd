import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import type { Schema } from '@toymart/api-types';
import type { UpdateStoreBrandingRequestInput } from '@toymart/api-types/zod';
import { api } from '@/lib/api';

/** GET/PUT /settings/branding (docs/api/store-settings.md). Generated types only (rule 27). */
export type StoreBranding = Schema<'StoreBranding'>;
export type StoreBrandingInput = UpdateStoreBrandingRequestInput;
/** One saved image as the API describes it: `media.logo` etc. */
export type BrandingMedia = NonNullable<StoreBranding['media']['logo']>;

const keys = {
    branding: ['settings', 'branding'] as const,
    /** The logo is the same row Taxes & invoices prints (invoice.company.logo_media_id). */
    invoice: ['settings', 'invoice'] as const,
};

export function useStoreBranding(enabled: boolean) {
    return useQuery({ queryKey: keys.branding, queryFn: () => api<StoreBranding>('/settings/branding'), enabled });
}

export function useSaveStoreBranding() {
    const client = useQueryClient();

    return useMutation({
        mutationFn: (input: StoreBrandingInput) => api<StoreBranding>('/settings/branding', { method: 'PUT', body: JSON.stringify(input) }),
        onSuccess: (saved) => {
            client.setQueryData(keys.branding, saved);
            void client.invalidateQueries({ queryKey: keys.invoice });
        },
    });
}

export const brandingQueryKey = keys.branding;
