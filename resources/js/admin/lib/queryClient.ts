import { MutationCache, QueryCache, QueryClient } from '@tanstack/react-query';
import { ApiError } from './api';
import { meQueryKey } from '@/features/auth/api';

/**
 * Server data lives in TanStack Query and nowhere else (CLAUDE.md rule 25a).
 *
 * Any 401, from any query or mutation, means the session is gone (expired, signed out
 * elsewhere, or the account was deactivated). Clearing `me` makes RequireAuth send the
 * user to /login from wherever they are.
 */
export function createQueryClient(): QueryClient {
    const onError = (error: unknown) => {
        if (error instanceof ApiError && error.status === 401) {
            client.setQueryData(meQueryKey, null);
        }
    };

    const client: QueryClient = new QueryClient({
        queryCache: new QueryCache({ onError }),
        mutationCache: new MutationCache({ onError }),
        defaultOptions: {
            queries: {
                staleTime: 30_000,
                retry: (failureCount, error) => {
                    // Never retry auth, permission or validation failures: they will not succeed,
                    // and retrying hides the real problem from the operator.
                    if (error instanceof ApiError && [401, 403, 404, 422].includes(error.status)) return false;
                    return failureCount < 2;
                },
                refetchOnWindowFocus: false,
            },
        },
    });

    return client;
}
