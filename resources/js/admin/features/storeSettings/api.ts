import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import type { Schema } from '@toymart/api-types';
import type { StoreStockLocationRequestInput, UpdateBusinessDetailsRequestInput, UpdateStockLocationRequestInput } from '@toymart/api-types/zod';
import { api } from '@/lib/api';

export type BusinessDetails = Schema<'BusinessDetails'>;
export type StoreLocation = Schema<'StoreLocation'>;
export type LocationType = NonNullable<StoreLocation['type']>;
export type LocationDistrict = Schema<'StoreLocationDistrict'>;
/** Request bodies are the generated types, never hand-written copies (rule 27). */
export type BusinessDetailsInput = UpdateBusinessDetailsRequestInput;
export type NewLocationInput = StoreStockLocationRequestInput;
export type LocationChangeInput = UpdateStockLocationRequestInput;

/** The server's order (App\Domain\Inventory\Enums\StockLocationType). */
export const locationTypes: LocationType[] = ['warehouse', 'retail', 'pickup'];

const keys = {
    business: ['settings', 'business'] as const,
    /** Invoice details reads the same rows (CompanyDetails): refresh it after a Business save. */
    invoice: ['settings', 'invoice'] as const,
    locations: ['settings', 'locations'] as const,
    districts: ['settings', 'locations', 'districts'] as const,
};

export function useBusinessDetails(enabled: boolean) {
    return useQuery({ queryKey: keys.business, queryFn: () => api<BusinessDetails>('/settings/business'), enabled });
}

export function useSaveBusinessDetails() {
    const client = useQueryClient();

    return useMutation({
        mutationFn: (input: BusinessDetailsInput) => api<BusinessDetails>('/settings/business', { method: 'PUT', body: JSON.stringify(input) }),
        onSuccess: (saved) => {
            client.setQueryData(keys.business, saved);
            void client.invalidateQueries({ queryKey: keys.invoice });
        },
    });
}

export function useStoreLocations(enabled: boolean) {
    return useQuery({ queryKey: keys.locations, queryFn: () => api<{ data: StoreLocation[] }>('/settings/locations').then((r) => r.data), enabled });
}

/** Districts with their division and upazilas — static reference data, fetched once a session. */
export function useLocationDistricts(enabled: boolean) {
    return useQuery({
        queryKey: keys.districts,
        queryFn: () => api<{ data: LocationDistrict[] }>('/settings/locations/districts').then((r) => r.data),
        enabled,
        staleTime: Infinity,
    });
}

export function useSaveLocation() {
    const client = useQueryClient();

    return useMutation({
        mutationFn: ({ id, input }: { id: number | null; input: NewLocationInput | LocationChangeInput }) =>
            id === null
                ? api<StoreLocation>('/settings/locations', { method: 'POST', body: JSON.stringify(input) })
                : api<StoreLocation>(`/settings/locations/${id}`, { method: 'PUT', body: JSON.stringify(input) }),
        onSuccess: () => {
            void client.invalidateQueries({ queryKey: keys.locations, exact: true });
            // Inventory screens list the active locations too.
            void client.invalidateQueries({ queryKey: ['inventory'] });
        },
    });
}

/** Business → Invoice details shares these keys; the invoice page invalidates Business after its own save. */
export const businessQueryKey = keys.business;
