import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import type { Schema } from '@toymart/api-types';
import { ApiError, api, ensureCsrfCookie } from '@/lib/api';

/** GET /api/v1/admin/auth/me — generated from the OpenAPI contract (rule 27). */
export type Me = Schema<'AuthUserResource'>;

export type Credentials = { email: string; password: string; remember: boolean };

/** Second sign-in step: one of the authenticator code or a recovery code. */
export type SecondFactor = { code: string } | { recovery_code: string };

/** `POST /auth/login` answers 202 `{ two_factor: true }` when the account has two-factor on. */
export type LoginResult = { kind: 'signed-in'; me: Me } | { kind: 'two-factor' };

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
        mutationFn: async (credentials: Credentials): Promise<LoginResult> => {
            await ensureCsrfCookie();
            const answer = await api<Me | Schema<'TwoFactorChallengeResource'>>('/auth/login', { method: 'POST', body: JSON.stringify(credentials) });
            // The 202 body is `{ two_factor: true }`; a signed-in Me has `two_factor` too, as an object.
            return answer.two_factor === true ? { kind: 'two-factor' } : { kind: 'signed-in', me: answer as Me };
        },
        onSuccess: (result) => {
            if (result.kind === 'signed-in') client.setQueryData(meQueryKey, result.me);
        },
    });
}

/** Completes a sign-in that needed a second factor. */
export function useTwoFactorChallenge() {
    const client = useQueryClient();

    return useMutation({
        mutationFn: (factor: SecondFactor) => api<Me>('/auth/two-factor-challenge', { method: 'POST', body: JSON.stringify(factor) }),
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
