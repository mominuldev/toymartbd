// Planned downtime (Gate 5, docs/ops/DEPLOYMENT.md §5): the shop shows its maintenance page — a
// 503 + Retry-After, in the request's locale — never the 500 page or a broken one. Two triggers:
//
//  1. STOREFRONT_MAINTENANCE=1 on the storefront host: every page, for as long as it is set.
//  2. The API itself is down (`php artisan down`): it answers every call with a 503 problem of
//     type `maintenance` (app/Http/Problems/ProblemType.php). No extra request probes for that —
//     the storefront reacts to the 503s its real fetches get: the proxy's redirect-map refresh
//     (once a minute, every 10 s while failing) and every page or route-handler call through
//     lib/api.ts. Each records the signal below; proxy.ts reads it and answers the maintenance
//     page until the signal expires or a successful API answer clears it.
//
// Plain module, no `server-only`: proxy.ts imports it too.

/** Retry-After when the API gave none (and for the env switch). */
export const MAINTENANCE_RETRY_AFTER_SECONDS = 600;

/**
 * How long one maintenance answer keeps the proxy serving the maintenance page without asking
 * again. Short on purpose: Laravel's Retry-After is an estimate, and once the window lapses the
 * next real request re-checks (and re-arms the signal if the API is still down).
 */
export const SIGNAL_MIN_SECONDS = 5;
export const SIGNAL_MAX_SECONDS = 60;

/** Error digest an error boundary recognises (`TOYMART_MAINTENANCE;<retry-after>`). */
export const MAINTENANCE_DIGEST = 'TOYMART_MAINTENANCE';

export function maintenanceOn(env: Record<string, string | undefined>): boolean {
    return env.STOREFRONT_MAINTENANCE?.trim() === '1';
}

/**
 * Is this API answer Laravel's maintenance mode? Only status 503 with problem type `maintenance`:
 * a feature-level 503 (`service-unavailable`, e.g. no courier rate card) is not downtime.
 */
export function isMaintenanceProblem(status: number, type: unknown): boolean {
    return status === 503 && typeof type === 'string' && type.split('/').pop() === 'maintenance';
}

/** `Retry-After` as seconds (delta-seconds or an HTTP-date), clamped to 1 s – 1 day; else the fallback. */
export function retryAfterSeconds(header: string | null | undefined, now = Date.now(), fallback = MAINTENANCE_RETRY_AFTER_SECONDS): number {
    const value = header?.trim() ?? '';
    if (value === '') return fallback;
    let seconds: number;
    if (/^\d+$/.test(value)) {
        seconds = Number(value);
    } else {
        // An HTTP-date names its weekday/month ("Sat, 14 Nov 2026 10:05:00 GMT"); Date.parse would
        // also accept "-5" or "2026" as a date.
        const at = /[a-z]/i.test(value) ? Date.parse(value) : Number.NaN;
        if (Number.isNaN(at)) return fallback;
        seconds = Math.ceil((at - now) / 1000);
    }
    return Math.min(Math.max(seconds, 1), 86_400);
}

/** The error-boundary digest for a maintenance answer. */
export function maintenanceDigest(retryAfter: number): string {
    return `${MAINTENANCE_DIGEST};${retryAfter}`;
}

export function isMaintenanceDigest(digest: unknown): boolean {
    return typeof digest === 'string' && digest.split(';', 1)[0] === MAINTENANCE_DIGEST;
}

// ---- The API-maintenance signal --------------------------------------------------------------
// Held on globalThis, not in module scope: proxy.ts and the page/route bundles are separate module
// graphs, and `next start` runs them in one Node process, so a 503 a page render gets switches the
// proxy over from the very next request. Where the proxy runs in its own isolate (a serverless
// host), it still learns from its own redirect-map refresh within TTL (lib/seo/redirect-map.ts).

interface Signal {
    /** Epoch ms after which the proxy lets requests through again. */
    until: number;
    retryAfter: number;
}

const KEY = Symbol.for('toymart.apiMaintenance');
type Holder = { [KEY]?: Signal | null };

function holder(): Holder {
    return globalThis as unknown as Holder;
}

/** Record a maintenance answer from the API. */
export function noteApiMaintenance(retryAfter: number, now = Date.now()): void {
    const window = Math.min(Math.max(retryAfter, SIGNAL_MIN_SECONDS), SIGNAL_MAX_SECONDS);
    holder()[KEY] = { until: now + window * 1000, retryAfter };
}

/** A successful API answer: the downtime is over. */
export function clearApiMaintenance(): void {
    holder()[KEY] = null;
}

/** The live signal (its Retry-After), or null when the API is not known to be down. */
export function apiMaintenance(now = Date.now()): { retryAfter: number } | null {
    const signal = holder()[KEY];
    if (!signal) return null;
    if (now >= signal.until) {
        holder()[KEY] = null;
        return null;
    }
    return { retryAfter: signal.retryAfter };
}

/**
 * Watch one API answer: a maintenance 503 arms the signal, a 2xx clears it, anything else leaves it.
 * Reads a clone, so the caller's body stays unread. `fresh: false` for an ISR-cached fetch: its 2xx
 * may come from Next's data cache, not from the API, so it must not clear the signal (a 503 is
 * always fresh — Next caches only 200s).
 */
export async function observeApiResponse(response: Response, { fresh = true, now = Date.now() }: { fresh?: boolean; now?: number } = {}): Promise<void> {
    if (response.ok) {
        if (fresh) clearApiMaintenance();
        return;
    }
    if (response.status !== 503) return;
    let type: unknown = null;
    try {
        const body = (await response.clone().json()) as { type?: unknown } | null;
        type = body && typeof body === 'object' ? body.type : null;
    } catch {
        return;
    }
    if (isMaintenanceProblem(503, type)) noteApiMaintenance(retryAfterSeconds(response.headers.get('retry-after'), now), now);
}

/** `fetch` that observes every answer — handed to the proxy's redirect-map refresh. */
export function observingFetch(fetchImpl: typeof fetch = fetch): typeof fetch {
    return async (input, init) => {
        const response = await fetchImpl(input, init);
        await observeApiResponse(response);
        return response;
    };
}
