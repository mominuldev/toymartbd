import { useEffect, useState } from 'react';
import { useMutation } from '@tanstack/react-query';
import type { Schema } from '@toymart/api-types';
import type { DraftInboxReplyRequestInput } from '@toymart/api-types/zod';
import { api } from '@/lib/api';

/** Inbox › Suggested reply (Figma 539:83211) — a draft only: the server sends and stores nothing. */
export type AiInboxDraft = Schema<'AiInboxDraft'>;
/** The POST body — generated from the FormRequest (rule 27). */
export type AiDraftInput = DraftInboxReplyRequestInput;
export type AiDraftStyle = NonNullable<AiDraftInput['style']>;

/**
 * One conversation's draft: request, re-request with a style, discard. The draft itself is the
 * mutation's result (TanStack Query, rule 25a); `open` only says whether the card is showing.
 */
export function useAiDraft(conversationId: number) {
    const [open, setOpen] = useState(false);
    const mutation = useMutation({
        mutationFn: (input: AiDraftInput) =>
            api<AiInboxDraft>(`/inbox/conversations/${conversationId}/ai-draft`, { method: 'POST', body: JSON.stringify(input) }),
    });
    const { reset } = mutation;

    useEffect(() => {
        setOpen(false);
        reset();
    }, [conversationId, reset]);

    return {
        open,
        draft: mutation.data,
        pending: mutation.isPending,
        error: mutation.error,
        lastStyle: mutation.variables?.style ?? 'default',
        request: (style: AiDraftStyle = 'default') => {
            setOpen(true);
            mutation.mutate(style === 'default' ? {} : { style });
        },
        discard: () => {
            setOpen(false);
            mutation.reset();
        },
    };
}

export type AiDraftState = ReturnType<typeof useAiDraft>;
