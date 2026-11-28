/**
 * Live updates over Laravel Reverb (the Pusher protocol), for the admin only.
 *
 * Off unless the bundle was built with VITE_REVERB_APP_KEY: the admin then simply polls, as it
 * always has. When on, it joins private channels through `POST /api/v1/admin/broadcasting/auth`
 * using the same cookie session and CSRF header as every other call (`api()`), so no credential
 * sits in JavaScript.
 *
 * Pushes are SIGNALS (ids), never data: a listener invalidates TanStack Query keys and the data is
 * refetched through the API with the viewer's own permissions (rule 25a).
 *
 * laravel-echo and pusher-js load lazily, only when live updates are configured.
 */
import type { ChannelAuthorizationCallback, ChannelAuthorizationHandler } from 'pusher-js';
import { useSyncExternalStore } from 'react';
import { api } from '@/lib/api';

export type LiveState = 'off' | 'connecting' | 'live';

type Config = { key: string; host: string; port: number; scheme: 'http' | 'https' };
type Listener = (payload: unknown) => void;
type EchoLike = {
    private: (channel: string) => { listen: (event: string, cb: Listener) => unknown; stopListening: (event: string, cb?: Listener) => unknown };
    leave: (channel: string) => void;
    connector: { pusher: { connection: { state: string; bind: (event: string, cb: (s: { current: string }) => void) => void } } };
};

function readConfig(): Config | null {
    const env = import.meta.env;
    const key = env.VITE_REVERB_APP_KEY;
    if (typeof key !== 'string' || key === '') return null;
    const scheme = env.VITE_REVERB_SCHEME === 'http' ? 'http' : 'https';
    const host = typeof env.VITE_REVERB_HOST === 'string' && env.VITE_REVERB_HOST !== '' ? env.VITE_REVERB_HOST : window.location.hostname;
    const port = Number(env.VITE_REVERB_PORT) || (scheme === 'https' ? 443 : 80);
    return { key, host, port, scheme };
}

/* ---------- connection state (UI state only, never server data) ---------- */

let state: LiveState = 'off';
let hadBeenLive = false;
const stateListeners = new Set<() => void>();
const reconnectListeners = new Set<() => void>();

function setState(next: LiveState): void {
    if (next === state) return;
    const wasDown = state !== 'live';
    state = next;
    stateListeners.forEach((l) => l());
    // Back after a drop: pushes may have been missed — let subscribers resync once.
    if (next === 'live' && wasDown && hadBeenLive) reconnectListeners.forEach((l) => l());
    if (next === 'live') hadBeenLive = true;
}

export function getLiveState(): LiveState {
    return state;
}

export function subscribeLiveState(listener: () => void): () => void {
    stateListeners.add(listener);
    return () => stateListeners.delete(listener);
}

export function useLiveState(): LiveState {
    return useSyncExternalStore(subscribeLiveState, getLiveState, getLiveState);
}

/** While the socket is live, polling drops to a slow safety net: a missed push heals within this. */
export const LIVE_SAFETY_POLL_MS = 5 * 60_000;

/** A query's polling interval: its normal pace while live updates are off, the safety net while live. */
export function useLivePollInterval(normalMs: number): number {
    return useLiveState() === 'live' ? LIVE_SAFETY_POLL_MS : normalMs;
}

/** Called each time the socket comes back after a drop (not on the first connect). */
export function onReconnect(listener: () => void): () => void {
    reconnectListeners.add(listener);
    return () => reconnectListeners.delete(listener);
}

/* ---------- the Echo client ---------- */

let echo: Promise<EchoLike | null> | null = null;

const authorize: ChannelAuthorizationHandler = (params, callback: ChannelAuthorizationCallback): void => {
    api<{ auth: string }>('/broadcasting/auth', { method: 'POST', body: JSON.stringify({ socket_id: params.socketId, channel_name: params.channelName }) })
        .then((data) => callback(null, data))
        .catch((error: unknown) => callback(error instanceof Error ? error : new Error(String(error)), null));
};

function client(): Promise<EchoLike | null> {
    if (echo) return echo;
    const config = readConfig();
    if (!config) return (echo = Promise.resolve(null));

    setState('connecting');
    echo = Promise.all([import('laravel-echo'), import('pusher-js')])
        .then(([{ default: Echo }, { default: Pusher }]) => {
            const instance = new Echo({
                broadcaster: 'reverb',
                key: config.key,
                wsHost: config.host,
                wsPort: config.port,
                wssPort: config.port,
                forceTLS: config.scheme === 'https',
                enabledTransports: ['ws', 'wss'],
                Pusher,
                // Leave window.fetch / axios alone: the admin's own client sends its headers.
                withoutInterceptors: true,
                channelAuthorization: { customHandler: authorize },
            }) as unknown as EchoLike;
            const connection = instance.connector.pusher.connection;
            connection.bind('state_change', ({ current }) => setState(current === 'connected' ? 'live' : 'connecting'));
            if (connection.state === 'connected') setState('live');
            return instance;
        })
        .catch(() => {
            setState('off');
            return null;
        });
    return echo;
}

/**
 * Listen to one event on one private channel. Returns an unsubscribe function; safe to call
 * before the client has loaded and when live updates are off (then it does nothing).
 */
export function listenPrivate(channel: string, event: string, listener: Listener): () => void {
    let cancelled = false;
    let stop: (() => void) | null = null;
    void client().then((instance) => {
        if (!instance || cancelled) return;
        const ch = instance.private(channel);
        ch.listen(`.${event}`, listener);
        stop = () => {
            ch.stopListening(`.${event}`, listener);
        };
    });
    return () => {
        cancelled = true;
        stop?.();
    };
}

/** Test seam: forget the client and state between tests. */
export function resetRealtimeForTests(): void {
    echo = null;
    state = 'off';
    hadBeenLive = false;
    stateListeners.clear();
    reconnectListeners.clear();
}
