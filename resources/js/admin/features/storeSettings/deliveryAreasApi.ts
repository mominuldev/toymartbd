import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import type { Schema } from '@toymart/api-types';
import type { UpdateDeliveryAreasRequestInput } from '@toymart/api-types/zod';
import { api } from '@/lib/api';
import { checkoutSettingsKey } from './checkoutApi';

/** `GET /settings/delivery-areas` — the `delivery.*` values every quote reads. */
export type DeliveryAreas = Schema<'DeliveryAreas'>;
/** The `PUT /settings/delivery-areas` body — the generated request type, never a hand-written copy (rule 27). */
export type DeliveryAreasInput = UpdateDeliveryAreasRequestInput;
export type DeliveryZone = keyof DeliveryAreas['eta_days'];

/** The server's order (App\Domain\Fulfilment\Enums\DeliveryZone). */
export const deliveryZones: DeliveryZone[] = ['inside_dhaka_metro', 'dhaka_suburb', 'outside_dhaka'];

export const deliveryAreasKey = ['settings', 'delivery-areas'] as const;

export function useDeliveryAreas(enabled: boolean) {
    return useQuery({ queryKey: deliveryAreasKey, queryFn: () => api<DeliveryAreas>('/settings/delivery-areas'), enabled });
}

/**
 * Saves the whole document. Checkout & delivery shows the COD advance from the same setting,
 * so its cached copy is refreshed too.
 */
export function useSaveDeliveryAreas() {
    const client = useQueryClient();

    return useMutation({
        mutationFn: (input: DeliveryAreasInput) => api<DeliveryAreas>('/settings/delivery-areas', { method: 'PUT', body: JSON.stringify(input) }),
        onSuccess: (saved) => {
            client.setQueryData(deliveryAreasKey, saved);
            void client.invalidateQueries({ queryKey: checkoutSettingsKey });
        },
    });
}
