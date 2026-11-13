import { indexRedirects, type RedirectIndex } from './redirects';
import type { RedirectMap } from './types';

// The redirect map for proxy.ts: GET /storefront/redirects, held in module memory for TTL_MS.
// Not lib/api.ts (`server-only` + next/headers do not belong in the proxy bundle); the same
// server-side env vars, never exposed to a browser (rule 28).
//
// Failure is always "no redirects", never a broken page: a slow API is cut off at TIMEOUT_MS,
// and a failed refresh keeps serving the last good map (stale-while-error). A refresh in flight is
// shared, so a burst of requests makes one API call.

export const TTL_MS = 60_000;
export const TIMEOUT_MS = 1_500;
const EMPTY: RedirectIndex = new Map();

interface CacheState {
    index: RedirectIndex;
    expires: number;
    inflight: Promise<RedirectIndex> | null;
    /** A map (or an answer) has arrived once: later refreshes run in the background. */
    loaded: boolean;
}

const state: CacheState = { index: EMPTY, expires: 0, inflight: null, loaded: false };

function config(): { base: string; token: string } | null {
    const base = process.env.API_URL;
    const token = process.env.STOREFRONT_API_TOKEN;
    return base && token ? { base: base.replace(/\/+$/, ''), token } : null;
}

async function load(fetchImpl: typeof fetch): Promise<RedirectIndex> {
    const cfg = config();
    if (!cfg) return EMPTY;
    const response = await fetchImpl(`${cfg.base}/api/v1/storefront/redirects`, {
        headers: { Accept: 'application/json', Authorization: `Bearer ${cfg.token}` },
        cache: 'no-store',
        signal: AbortSignal.timeout(TIMEOUT_MS),
    });
    if (!response.ok) throw new Error(`redirects ${response.status}`);
    const body = (await response.json()) as RedirectMap | { data: RedirectMap };
    const map = 'data' in body && !('redirects' in body) ? body.data : (body as RedirectMap);
    return indexRedirects(Array.isArray(map?.redirects) ? map.redirects : []);
}

export async function redirectIndex(now = Date.now(), fetchImpl: typeof fetch = fetch): Promise<RedirectIndex> {
    if (now < state.expires) return state.index;
    if (!state.inflight) {
        state.inflight = load(fetchImpl)
            .then((index) => {
                state.index = index;
                state.expires = Date.now() + TTL_MS;
                state.loaded = true;
                return index;
            })
            .catch(() => {
                // Keep the last good map; retry after a short back-off rather than on every request.
                state.expires = Date.now() + 10_000;
                state.loaded = true;
                return state.index;
            })
            .finally(() => {
                state.inflight = null;
            });
    }
    // Only the very first request waits (≤ TIMEOUT_MS); afterwards the stale map answers at once.
    return state.loaded ? state.index : state.inflight;
}

/** Fire-and-forget hit count for a manual redirect (POST /storefront/redirects/{id}/hit). */
export async function recordHit(id: number, fetchImpl: typeof fetch = fetch): Promise<void> {
    const cfg = config();
    if (!cfg || !Number.isInteger(id) || id <= 0) return;
    try {
        await fetchImpl(`${cfg.base}/api/v1/storefront/redirects/${id}/hit`, {
            method: 'POST',
            headers: { Accept: 'application/json', Authorization: `Bearer ${cfg.token}` },
            cache: 'no-store',
            signal: AbortSignal.timeout(TIMEOUT_MS),
        });
    } catch {
        // A lost hit is acceptable; a failed redirect is not.
    }
}

/** Tests only. */
export function resetRedirectCache(): void {
    state.index = EMPTY;
    state.expires = 0;
    state.inflight = null;
    state.loaded = false;
}
