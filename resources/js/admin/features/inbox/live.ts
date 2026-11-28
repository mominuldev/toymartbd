import { useQueryClient } from '@tanstack/react-query';
import { useEffect } from 'react';
import { listenPrivate, onReconnect } from '@/lib/realtime';
import { inboxKeys } from './api';

/** The private channel and event the API broadcasts (App\Domain\Inbox\Events\InboxConversationChanged). */
export const INBOX_CHANNEL = 'inbox';
export const INBOX_EVENT = 'conversation.changed';

/** Coalesce a burst (a message and its conversation row arrive as two pushes) into one refetch. */
const BATCH_MS = 250;

function conversationIdOf(payload: unknown): number | null {
    if (typeof payload !== 'object' || payload === null || !('conversation_id' in payload)) return null;
    const id = (payload as { conversation_id: unknown }).conversation_id;
    return typeof id === 'number' && Number.isInteger(id) && id > 0 ? id : null;
}

/**
 * Mounted once in the Shell for anyone with inbox.view. A push names the conversation that changed;
 * this refetches the conversation lists (and with them the nav badge, nested under the list key),
 * plus that conversation's detail and messages if they are cached. Nothing in the push is shown.
 */
export function useInboxLiveUpdates(enabled: boolean): void {
    const client = useQueryClient();

    useEffect(() => {
        if (!enabled) return;

        const pending = new Set<number>();
        let timer: ReturnType<typeof setTimeout> | null = null;

        const flush = () => {
            timer = null;
            void client.invalidateQueries({ queryKey: inboxKeys.lists });
            pending.forEach((id) => {
                void client.invalidateQueries({ queryKey: inboxKeys.detail(id) });
                void client.invalidateQueries({ queryKey: inboxKeys.messages(id) });
            });
            pending.clear();
        };

        const stopListening = listenPrivate(INBOX_CHANNEL, INBOX_EVENT, (payload) => {
            const id = conversationIdOf(payload);
            if (id !== null) pending.add(id);
            timer ??= setTimeout(flush, BATCH_MS);
        });
        // After a dropped connection, pushes may have been missed: refetch everything inbox once.
        const stopReconnect = onReconnect(() => void client.invalidateQueries({ queryKey: inboxKeys.all }));

        return () => {
            stopListening();
            stopReconnect();
            if (timer) clearTimeout(timer);
        };
    }, [client, enabled]);
}
