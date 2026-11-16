import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import type { Schema } from '@toymart/api-types';
import type { UpdateSiteBrandingRequestInput } from '@toymart/api-types/zod';
import { api } from '@/lib/api';

export type SiteBranding = Schema<'SiteBranding'>;
export type SiteImage = NonNullable<SiteBranding['logo']>;
/** The request body is the generated type, never a hand-written copy (rule 27). */
export type SiteBrandingInput = UpdateSiteBrandingRequestInput;

const key = ['settings', 'site'] as const;

export function useSiteBranding(enabled: boolean) {
    return useQuery({ queryKey: key, queryFn: () => api<SiteBranding>('/settings/site'), enabled });
}

export function useSaveSiteBranding() {
    const client = useQueryClient();

    return useMutation({
        mutationFn: (input: SiteBrandingInput) => api<SiteBranding>('/settings/site', { method: 'PUT', body: JSON.stringify(input) }),
        onSuccess: (saved) => {
            client.setQueryData(key, saved);
            // The library's "used in" counts change when an image is set or cleared here.
            void client.invalidateQueries({ queryKey: ['media'] });
        },
    });
}
