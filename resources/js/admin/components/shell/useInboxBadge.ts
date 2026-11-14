import { useQuery } from '@tanstack/react-query';
import type { Schema } from '@toymart/api-types';
import { api } from '@/lib/api';

export type InboxUnreadCount = Schema<'InboxUnreadCount'>;

/**
 * Nested under the inbox list key (`['inbox', 'list']`), so every inbox action that refreshes the
 * conversation list — open (mark read), reply, note, assign, resolve, reopen — refreshes the badge too.
 */
export const inboxBadgeKey = ['inbox', 'list', 'unread-count'] as const;

/**
 * The Inbox nav badge (owner decision 2026-11-15): open conversations with unread messages or
 * waiting on a staff reply, counted by the server (rule 1). New messages arrive by webhook, so it
 * polls every minute and refetches when the window regains focus.
 */
export function useInboxBadge(enabled: boolean) {
    return useQuery({
        queryKey: inboxBadgeKey,
        queryFn: () => api<InboxUnreadCount>('/inbox/unread-count'),
        enabled,
        staleTime: 30_000,
        refetchInterval: 60_000,
        refetchOnWindowFocus: true,
    });
}
