import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { act, renderHook } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { inboxKeys } from './api';
import { INBOX_CHANNEL, INBOX_EVENT, useInboxLiveUpdates } from './live';

type Listener = (payload: unknown) => void;
const realtime = vi.hoisted(() => ({
    listeners: [] as { channel: string; event: string; listener: (payload: unknown) => void }[],
    reconnects: [] as (() => void)[],
    stopped: 0,
}));

vi.mock('@/lib/realtime', () => ({
    listenPrivate: (channel: string, event: string, listener: Listener) => {
        realtime.listeners.push({ channel, event, listener });
        return () => {
            realtime.stopped += 1;
        };
    },
    onReconnect: (listener: () => void) => {
        realtime.reconnects.push(listener);
        return () => undefined;
    },
    useLivePollInterval: (ms: number) => ms,
}));

function setup(enabled = true) {
    const client = new QueryClient();
    const invalidate = vi.spyOn(client, 'invalidateQueries').mockResolvedValue();
    const wrapper = ({ children }: { children: ReactNode }) => <QueryClientProvider client={client}>{children}</QueryClientProvider>;
    const hook = renderHook(() => useInboxLiveUpdates(enabled), { wrapper });
    const keys = () => invalidate.mock.calls.map(([filters]) => JSON.stringify(filters?.queryKey));
    return { hook, invalidate, keys };
}

describe('Inbox live updates', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        realtime.listeners.length = 0;
        realtime.reconnects.length = 0;
        realtime.stopped = 0;
    });
    afterEach(() => vi.useRealTimers());

    it('listens on the private inbox channel for the conversation event', () => {
        setup();
        expect(realtime.listeners).toHaveLength(1);
        expect(realtime.listeners[0]).toMatchObject({ channel: INBOX_CHANNEL, event: INBOX_EVENT });
        expect(INBOX_EVENT).toBe('conversation.changed');
    });

    it('does not subscribe without inbox.view', () => {
        setup(false);
        expect(realtime.listeners).toHaveLength(0);
    });

    it('coalesces a burst into one refetch of the lists (and badge), plus each named thread', () => {
        const { invalidate, keys } = setup();
        const push = realtime.listeners[0]!.listener;

        act(() => {
            push({ conversation_id: 7 });
            push({ conversation_id: 7 });
            push({ conversation_id: 9 });
        });
        expect(invalidate).not.toHaveBeenCalled();

        act(() => void vi.advanceTimersByTime(300));
        expect(keys()).toEqual([
            JSON.stringify(inboxKeys.lists),
            JSON.stringify(inboxKeys.detail(7)),
            JSON.stringify(inboxKeys.messages(7)),
            JSON.stringify(inboxKeys.detail(9)),
            JSON.stringify(inboxKeys.messages(9)),
        ]);
    });

    it('treats a push without a usable id as a list refresh only', () => {
        const { keys } = setup();
        act(() => realtime.listeners[0]!.listener({ conversation_id: 'x' }));
        act(() => void vi.advanceTimersByTime(300));
        expect(keys()).toEqual([JSON.stringify(inboxKeys.lists)]);
    });

    it('refetches everything inbox after a reconnect, and unsubscribes on unmount', () => {
        const { hook, keys } = setup();
        act(() => realtime.reconnects[0]!());
        expect(keys()).toEqual([JSON.stringify(inboxKeys.all)]);

        hook.unmount();
        expect(realtime.stopped).toBe(1);
    });
});
