import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import type { Schema } from '@toymart/api-types';
import type { UpdatePaymentGatewayRequestInput } from '@toymart/api-types/zod';
import { api } from '@/lib/api';

/** One online gateway (SSLCommerz, PayStation): mode, on/off, and per-mode keys — secrets only as `set`. */
export type PaymentGateway = Schema<'PaymentGatewaySettings'>;
export type PaymentGatewayCode = PaymentGateway['code'];
export type GatewayMode = PaymentGateway['mode'];
export type GatewayKeySet = PaymentGateway['credentials']['sandbox'];
export type GatewayCheck = Schema<'PaymentGatewayConnectionCheck'>;
/** The PUT body — the generated request type, never a hand-written copy (rule 27). */
export type PaymentGatewayInput = UpdatePaymentGatewayRequestInput;

export const gatewayModes: readonly GatewayMode[] = ['sandbox', 'live'];

const key = ['settings', 'payment-gateways'] as const;

export function usePaymentGateways(enabled: boolean) {
    return useQuery({ queryKey: key, queryFn: () => api<{ data: PaymentGateway[] }>('/settings/payment-gateways').then((r) => r.data), enabled });
}

export function useSavePaymentGateway() {
    const client = useQueryClient();

    return useMutation({
        mutationFn: ({ code, input }: { code: PaymentGatewayCode; input: PaymentGatewayInput }) =>
            api<PaymentGateway>(`/settings/payment-gateways/${code}`, { method: 'PUT', body: JSON.stringify(input) }),
        onSuccess: (saved) => {
            client.setQueryData<PaymentGateway[]>(key, (rows) => rows?.map((row) => (row.code === saved.code ? saved : row)));
            // The Integrations hub and checkout settings read the same switchboard.
            void client.invalidateQueries({ queryKey: ['integrations'] });
        },
    });
}

/** "Check connection": one read-only call with the keys SAVED for the gateway's current mode. Takes no money. */
export function useCheckPaymentGateway() {
    return useMutation({
        mutationFn: (code: PaymentGatewayCode) => api<GatewayCheck>(`/settings/payment-gateways/${code}/check`, { method: 'POST' }),
    });
}
