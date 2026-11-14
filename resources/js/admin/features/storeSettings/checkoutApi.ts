import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import type { Schema } from '@toymart/api-types';
import type { UpdateDeliverySettingsRequestInput } from '@toymart/api-types/zod';
import { api } from '@/lib/api';

/** `GET /settings/checkout` — what checkout really does (fixed facts + the delivery values it quotes). */
export type CheckoutSettings = Schema<'CheckoutSettings'>;
export type DeliverySettings = Schema<'DeliverySettings'>;
/** The `PUT /settings/delivery` body — the generated request type, never a hand-written copy (rule 27). */
export type FreeDeliveryInput = UpdateDeliverySettingsRequestInput;

export const checkoutSettingsKey = ['settings', 'checkout'] as const;
/** The courier settings screen caches the same endpoint under this key; refresh it after a save. */
const deliveryKey = ['couriers', 'delivery'] as const;

export function useCheckoutSettings(enabled: boolean) {
    return useQuery({ queryKey: checkoutSettingsKey, queryFn: () => api<CheckoutSettings>('/settings/checkout'), enabled });
}

/**
 * The free-delivery threshold has ONE home: `delivery.free_shipping_threshold`, written by
 * `PUT /settings/delivery` (shipments.manage_rates) — the same endpoint the courier settings
 * screen uses. No second copy here.
 */
export function useSaveFreeDelivery() {
    const client = useQueryClient();

    return useMutation({
        mutationFn: (input: FreeDeliveryInput) => api<DeliverySettings>('/settings/delivery', { method: 'PUT', body: JSON.stringify(input) }),
        onSuccess: (saved) => {
            client.setQueryData<CheckoutSettings>(checkoutSettingsKey, (current) =>
                current ? { ...current, free_shipping_threshold: saved.free_shipping_threshold } : current,
            );
            client.setQueryData(deliveryKey, saved);
        },
    });
}
