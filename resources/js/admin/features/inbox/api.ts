import { useInfiniteQuery, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import type { Schema } from '@toymart/api-types';
import type { AssignConversationRequestInput, SendInboxReplyRequestInput, SendQuickReplyRequestInput, StoreInboxNoteRequestInput } from '@toymart/api-types/zod';
import { api } from '@/lib/api';

export type Conversation = Schema<'InboxConversation'>;
export type ConversationDetail = Schema<'InboxConversationDetail'>;
export type InboxMessage = Schema<'InboxMessage'>;
export type Channel = Conversation['channel'];
export type ConversationStatus = Conversation['status'];
export type InboxCustomer = NonNullable<ConversationDetail['customer']>;
/** The POST bodies — generated from the FormRequests (rule 27). */
export type ReplyInput = SendInboxReplyRequestInput;
export type NoteInput = StoreInboxNoteRequestInput;
export type AssignInput = AssignConversationRequestInput;
export type QuickReplyInput = SendQuickReplyRequestInput;

export const views = ['all', 'mine', 'needs_human'] as const;
export type View = (typeof views)[number];
export const channels: Channel[] = ['whatsapp', 'messenger', 'live_chat'];
export const statuses: ConversationStatus[] = ['open', 'resolved'];

/** Every filter is a server allowlist (rule 16); the browser computes nothing (rule 1). */
export type InboxFilters = { view: View; channel: Channel | ''; status: ConversationStatus | ''; search: string };

type CursorMeta = { next_cursor: string | null; prev_cursor: string | null; per_page: number };
export type ConversationPage = { data: Conversation[]; meta: CursorMeta & { counts: Record<View, number> } };
export type MessagePage = { data: InboxMessage[]; meta: CursorMeta };
export type Assignee = { id: number; name: string };

export const inboxKeys = {
    all: ['inbox'] as const,
    lists: ['inbox', 'list'] as const,
    list: (f: InboxFilters) => ['inbox', 'list', f] as const,
    detail: (id: number) => ['inbox', 'detail', id] as const,
    messages: (id: number) => ['inbox', 'messages', id] as const,
    assignees: ['inbox', 'assignees'] as const,
};

export function conversationsPath(f: InboxFilters, cursor?: string | null): string {
    const params = new URLSearchParams();
    if (f.view !== 'all') params.set('filter[view]', f.view);
    if (f.channel) params.set('filter[channel]', f.channel);
    if (f.status) params.set('filter[status]', f.status);
    if (f.search) params.set('filter[search]', f.search);
    if (cursor) params.set('cursor', cursor);
    const text = params.toString();
    return `/inbox/conversations${text ? `?${text}` : ''}`;
}

export function useConversations(filters: InboxFilters) {
    return useInfiniteQuery({
        queryKey: inboxKeys.list(filters),
        queryFn: ({ pageParam }) => api<ConversationPage>(conversationsPath(filters, pageParam)),
        initialPageParam: null as string | null,
        getNextPageParam: (last) => last.meta.next_cursor,
        refetchInterval: 30_000,
    });
}

export function useConversation(id: number) {
    return useQuery({
        queryKey: inboxKeys.detail(id),
        queryFn: () => api<ConversationDetail>(`/inbox/conversations/${id}`),
        enabled: Number.isInteger(id) && id > 0,
    });
}

/** Newest first from the API; the thread reverses each page for display. */
export function useMessages(id: number) {
    return useInfiniteQuery({
        queryKey: inboxKeys.messages(id),
        queryFn: ({ pageParam }) => api<MessagePage>(`/inbox/conversations/${id}/messages${pageParam ? `?cursor=${encodeURIComponent(pageParam)}` : ''}`),
        initialPageParam: null as string | null,
        getNextPageParam: (last) => last.meta.next_cursor,
        enabled: Number.isInteger(id) && id > 0,
        refetchInterval: 15_000,
    });
}

export function useAssignees(enabled: boolean) {
    return useQuery({
        queryKey: inboxKeys.assignees,
        queryFn: () => api<{ data: Assignee[] }>('/inbox/assignees').then((r) => r.data),
        enabled,
        staleTime: 5 * 60_000,
    });
}

function useInboxMutation<TInput, TResult>(id: number, request: (input: TInput) => Promise<TResult>) {
    const client = useQueryClient();
    return useMutation({
        mutationFn: request,
        onSuccess: (result) => {
            if (result && typeof result === 'object' && 'window' in result) client.setQueryData(inboxKeys.detail(id), result);
            void client.invalidateQueries({ queryKey: inboxKeys.messages(id) });
            void client.invalidateQueries({ queryKey: inboxKeys.detail(id) });
            void client.invalidateQueries({ queryKey: inboxKeys.lists });
        },
    });
}

const post = (body?: unknown): RequestInit => ({ method: 'POST', ...(body === undefined ? {} : { body: JSON.stringify(body) }) });

export const useSendReply = (id: number) => useInboxMutation(id, (input: ReplyInput) => api<InboxMessage>(`/inbox/conversations/${id}/messages`, post(input)));
export const useAddNote = (id: number) => useInboxMutation(id, (input: NoteInput) => api<InboxMessage>(`/inbox/conversations/${id}/notes`, post(input)));
export const useQuickReply = (id: number) => useInboxMutation(id, (input: QuickReplyInput) => api<InboxMessage>(`/inbox/conversations/${id}/quick-replies`, post(input)));
export const useAssign = (id: number) => useInboxMutation(id, (input: AssignInput) => api<ConversationDetail>(`/inbox/conversations/${id}/assign`, post(input)));
export const useResolve = (id: number) => useInboxMutation(id, (resolve: boolean) => api<ConversationDetail>(`/inbox/conversations/${id}/${resolve ? 'resolve' : 'reopen'}`, post()));

/** Opening a thread clears its unread count (inbox.view). */
export function useMarkRead(id: number) {
    const client = useQueryClient();
    return useMutation({
        mutationFn: () => api<ConversationDetail>(`/inbox/conversations/${id}/read`, post()),
        onSuccess: (detail) => {
            client.setQueryData(inboxKeys.detail(id), detail);
            void client.invalidateQueries({ queryKey: inboxKeys.lists });
        },
    });
}

/** Active products for the product card picker — the existing catalog list (catalog.view). */
export type ProductOption = Pick<Schema<'ProductListItem'>, 'id' | 'name' | 'name_bn' | 'image_url'>;
export function useProductOptions(search: string, enabled: boolean) {
    return useQuery({
        queryKey: ['inbox', 'products', search],
        queryFn: () => api<{ data: ProductOption[] }>(`/products?filter[status]=active${search ? `&filter[search]=${encodeURIComponent(search)}` : ''}`).then((r) => r.data.slice(0, 8)),
        enabled,
    });
}
