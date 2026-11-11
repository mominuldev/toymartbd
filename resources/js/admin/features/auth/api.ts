import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import type { Schema } from '@toymart/api-types';
import { ApiError, api, ensureCsrfCookie } from '@/lib/api';

/** GET /api/v1/admin/auth/me — generated from the OpenAPI contract (rule 27). */
export type Me = Schema<'AuthUserResource'>;

export type Credentials = { email: string; password: string; remember: boolean };

export const meQueryKey = ['auth', 'me'] as const;

/**
 * The signed-in staff member, or null when the session is missing or expired.
 * A 401 is an answer here, not an error, so the guard can redirect to login.
 */
export function useMe() {
    return useQuery({
        queryKey: meQueryKey,
        queryFn: async (): Promise<Me | null> => {
            try {
                return await api<Me>('/auth/me');
            } catch (error) {
                if (error instanceof ApiError && error.status === 401) return null;
                throw error;
            }
        },
        staleTime: 5 * 60_000,
    });
}

export function useLogin() {
    const client = useQueryClient();

    return useMutation({
        mutationFn: async (credentials: Credentials): Promise<Me> => {
            await ensureCsrfCookie();
            return api<Me>('/auth/login', { method: 'POST', body: JSON.stringify(credentials) });
        },
        onSuccess: (me) => client.setQueryData(meQueryKey, me),
    });
}

export function useLogout() {
    const client = useQueryClient();

    return useMutation({
        mutationFn: () => api<void>('/auth/logout', { method: 'POST' }),
        onSettled: () => {
            // Drop every cached server response: the next user must not see this one's data.
            client.clear();
            client.setQueryData(meQueryKey, null);
        },
    });
}

export function can(me: Me | null | undefined, permission: string): boolean {
    return me?.permissions.includes(permission) ?? false;
}
