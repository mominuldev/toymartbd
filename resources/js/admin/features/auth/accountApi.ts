import { useMutation, useQueryClient } from '@tanstack/react-query';
import type { UpdateOwnPasswordRequestInput, UpdateOwnProfileRequestInput } from '@toymart/api-types/zod';
import { api } from '@/lib/api';
import { meQueryKey, type Me } from './api';

/** `PUT /auth/profile` — your own name and sign-in email; answers with the new `/auth/me`. */
export function useUpdateOwnProfile() {
    const client = useQueryClient();

    return useMutation({
        mutationFn: (input: UpdateOwnProfileRequestInput) => api<Me>('/auth/profile', { method: 'PUT', body: JSON.stringify(input) }),
        onSuccess: (me) => client.setQueryData(meQueryKey, me),
    });
}

/** `PUT /auth/password` — 204; other browser sessions are signed out, this one stays. */
export function useUpdateOwnPassword() {
    return useMutation({
        mutationFn: (input: UpdateOwnPasswordRequestInput) => api<void>('/auth/password', { method: 'PUT', body: JSON.stringify(input) }),
    });
}

/** `POST /auth/avatar` with `{ media_id }` — your profile photo, picked from the media library; answers with `/auth/me`. */
export function useOwnAvatar() {
    const client = useQueryClient();

    const choose = useMutation({
        mutationFn: (mediaId: number) => api<Me>('/auth/avatar', { method: 'POST', body: JSON.stringify({ media_id: mediaId }) }),
        onSuccess: (me: Me) => client.setQueryData(meQueryKey, me),
    });

    return { choose };
}
