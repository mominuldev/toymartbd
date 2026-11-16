import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import type { Schema } from '@toymart/api-types';
import type { UpdateBranchModeRequestInput } from '@toymart/api-types/zod';
import { ApiError, api } from '@/lib/api';
import { meQueryKey } from '@/features/auth/api';
import { useSettingsAccess } from './parts';

export type BranchMode = Schema<'BranchMode'>;
/** The request body is the generated type, never a hand-written copy (rule 27). */
export type BranchModeInput = UpdateBranchModeRequestInput;
export type BranchModeLocation = BranchMode['blockers'][number];

export const branchModeQueryKey = ['settings', 'branch-mode'] as const;

export function useBranchMode(enabled: boolean) {
    return useQuery({ queryKey: branchModeQueryKey, queryFn: () => api<BranchMode>('/settings/branch-mode'), enabled });
}

/** True while the store runs as a single shop; null until known (or without settings.view). */
export function useSingleShop(): boolean | null {
    const { canView } = useSettingsAccess();
    const mode = useBranchMode(canView);
    return mode.data ? !mode.data.multi_branch : null;
}

export function useSaveBranchMode() {
    const client = useQueryClient();

    return useMutation({
        mutationFn: (input: BranchModeInput) => api<BranchMode>('/settings/branch-mode', { method: 'PUT', body: JSON.stringify(input) }),
        onSuccess: (saved) => {
            client.setQueryData(branchModeQueryKey, saved);
            // The sidebar switcher (single shop or multiple branches) reads the mode from /auth/me.
            void client.invalidateQueries({ queryKey: meQueryKey });
        },
    });
}

/** The `blockers` a 409 `branch-mode-blocked` carries (extension member), or null for any other failure. */
export function blockersOf(error: unknown): BranchModeLocation[] | null {
    if (!(error instanceof ApiError) || !error.is('branch-mode-blocked')) return null;
    const list: unknown = (error.problem as Record<string, unknown>).blockers;
    if (!Array.isArray(list)) return [];
    return list.filter((b: unknown): b is BranchModeLocation => {
        if (typeof b !== 'object' || b === null) return false;
        const row = b as Record<string, unknown>;
        return typeof row.id === 'number' && typeof row.name === 'string';
    });
}
