/**
 * Admin API client.
 *
 * Auth is a Sanctum COOKIE session, not a bearer token: no long-lived credential
 * sits in JavaScript where an XSS could exfiltrate it. The cost is that every
 * write needs the CSRF token, which Laravel sets as the XSRF-TOKEN cookie.
 *
 * Errors follow RFC 9457 Problem Details. Callers branch on `type`, which is a
 * stable machine-readable string, never on `detail`, which is human prose and
 * will be translated.
 */

const BASE = '/api/v1/admin';

export type ProblemDetails = {
    type: string;
    title: string;
    status: number;
    detail?: string;
    errors?: Record<string, string[]>;
};

export class ApiError extends Error {
    constructor(
        public readonly status: number,
        public readonly problem: ProblemDetails,
    ) {
        super(problem.title);
        this.name = 'ApiError';
    }
}

function csrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    return match?.[1] ? decodeURIComponent(match[1]) : '';
}

export async function api<T>(path: string, init: RequestInit = {}): Promise<T> {
    const method = (init.method ?? 'GET').toUpperCase();
    const isWrite = method !== 'GET' && method !== 'HEAD';

    const response = await fetch(`${BASE}${path}`, {
        ...init,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            ...(init.body ? { 'Content-Type': 'application/json' } : {}),
            ...(isWrite ? { 'X-XSRF-TOKEN': csrfToken() } : {}),
            ...init.headers,
        },
    });

    if (response.status === 204) return undefined as T;

    const payload = await response.json().catch(() => null);

    if (!response.ok) {
        throw new ApiError(response.status, payload ?? {
            type: 'about:blank',
            title: response.statusText || 'Request failed',
            status: response.status,
        });
    }

    return payload as T;
}

/**
 * Writes that create an order, book a shipment or capture a payment require an
 * Idempotency-Key. On a flaky 4G connection a double-tap must not create two
 * orders — the server replays the original response instead.
 */
export function withIdempotency(init: RequestInit = {}): RequestInit {
    return {
        ...init,
        headers: { ...init.headers, 'Idempotency-Key': crypto.randomUUID() },
    };
}
