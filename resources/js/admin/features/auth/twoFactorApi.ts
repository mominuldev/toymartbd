import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import type { Schema } from '@toymart/api-types';
import type { TwoFactorCodeRequestInput } from '@toymart/api-types/zod';
import { api } from '@/lib/api';
import { meQueryKey } from './api';

export type TwoFactorStatus = Schema<'TwoFactorStatusResource'>;
export type TwoFactorSetup = Schema<'TwoFactorSetupResource'>;
export type RecoveryCodes = Schema<'RecoveryCodesResource'>;
export type CodeInput = TwoFactorCodeRequestInput;

const statusKey = ['auth', 'two-factor'] as const;

export function useTwoFactorStatus() {
    return useQuery({ queryKey: statusKey, queryFn: () => api<TwoFactorStatus>('/auth/two-factor') });
}

/** Every change also changes `/auth/me` (two_factor.enabled), which the route guard reads. */
function useRefresh() {
    const client = useQueryClient();
    return () => Promise.all([client.invalidateQueries({ queryKey: statusKey }), client.invalidateQueries({ queryKey: meQueryKey })]);
}

export function useStartTwoFactor() {
    return useMutation({ mutationFn: () => api<TwoFactorSetup>('/auth/two-factor', { method: 'POST' }) });
}

export function useConfirmTwoFactor() {
    const refresh = useRefresh();
    return useMutation({
        mutationFn: (input: CodeInput) => api<RecoveryCodes>('/auth/two-factor/confirm', { method: 'POST', body: JSON.stringify(input) }),
        onSuccess: () => void refresh(),
    });
}

export function useNewRecoveryCodes() {
    const refresh = useRefresh();
    return useMutation({
        mutationFn: (input: CodeInput) => api<RecoveryCodes>('/auth/two-factor/recovery-codes', { method: 'POST', body: JSON.stringify(input) }),
        onSuccess: () => void refresh(),
    });
}

export function useDisableTwoFactor() {
    const refresh = useRefresh();
    return useMutation({
        mutationFn: (input: CodeInput) => api<undefined>('/auth/two-factor', { method: 'DELETE', body: JSON.stringify(input) }),
        onSuccess: () => void refresh(),
    });
}
