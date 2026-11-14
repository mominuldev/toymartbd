import { useInfiniteQuery, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import type { Schema } from '@toymart/api-types';
import type { ResetStaffPasswordRequestInput, StoreStaffRequestInput, UpdateStaffRequestInput } from '@toymart/api-types/zod';
import { api } from '@/lib/api';

export type StaffMember = Schema<'StaffResource'>;
export type StaffRole = Schema<'StaffRoleResource'>;
/** Request bodies are the generated types, never hand-written copies (rule 27). */
export type NewStaffInput = StoreStaffRequestInput;
export type StaffChangeInput = UpdateStaffRequestInput;
export type PasswordInput = ResetStaffPasswordRequestInput;

/** The 7 roles, in the server's order (App\Models\Enums\Role). */
export const roleKeys = ['super_admin', 'manager', 'order_operator', 'inventory_staff', 'accountant', 'content_editor', 'viewer'] as const;
export type RoleKey = (typeof roleKeys)[number];

type Page<T> = { data: T[]; meta: { next_cursor?: string | null } };

const keys = {
    all: ['staff'] as const,
    people: ['staff', 'people'] as const,
    roles: ['staff', 'roles'] as const,
};

export function useStaff(enabled: boolean) {
    return useInfiniteQuery({
        queryKey: keys.people,
        queryFn: ({ pageParam }) => api<Page<StaffMember>>(`/staff${pageParam ? `?cursor=${encodeURIComponent(pageParam)}` : ''}`),
        initialPageParam: null as string | null,
        getNextPageParam: (last) => last.meta.next_cursor ?? null,
        enabled,
    });
}

export function useStaffRoles(enabled: boolean) {
    return useQuery({
        queryKey: keys.roles,
        queryFn: async () => (await api<{ data: StaffRole[] }>('/staff/roles')).data,
        enabled,
    });
}

/** Every write changes the list and the role counts, so both are refetched. */
function useStaffMutation<TVars, TResult>(fn: (vars: TVars) => Promise<TResult>) {
    const client = useQueryClient();

    return useMutation({
        mutationFn: fn,
        onSuccess: () => client.invalidateQueries({ queryKey: keys.all }),
    });
}

export function useAddStaff() {
    return useStaffMutation((input: NewStaffInput) => api<StaffMember>('/staff', { method: 'POST', body: JSON.stringify(input) }));
}

export function useUpdateStaff() {
    return useStaffMutation(({ id, input }: { id: number; input: StaffChangeInput }) =>
        api<StaffMember>(`/staff/${id}`, { method: 'PATCH', body: JSON.stringify(input) }),
    );
}

export function useSetStaffActive() {
    return useStaffMutation(({ id, active }: { id: number; active: boolean }) =>
        api<StaffMember>(`/staff/${id}/${active ? 'reactivate' : 'deactivate'}`, { method: 'POST' }),
    );
}

export function useResetStaffPassword() {
    return useStaffMutation(({ id, input }: { id: number; input: PasswordInput }) =>
        api<undefined>(`/staff/${id}/password`, { method: 'POST', body: JSON.stringify(input) }),
    );
}

/** The single role a staff account holds (the API assigns exactly one). */
export function roleOf(member: StaffMember): RoleKey | null {
    const role = member.roles[0];
    return role !== undefined && (roleKeys as readonly string[]).includes(role) ? (role as RoleKey) : null;
}
