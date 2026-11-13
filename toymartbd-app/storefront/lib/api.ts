import 'server-only';

import { headers as incomingHeaders } from 'next/headers';

import { clientIpFrom } from './client-ip';
import type { Locale } from './i18n/config';
import type { ProblemDetails } from './api-types';

// Server-only client for the Laravel API (CLAUDE.md rule 28). `server-only` makes the build fail if
// a Client Component ever imports this module, so the bearer token cannot be bundled for the browser.

/** One hour: the ISR ceiling. Writes in Laravel shorten it through POST /api/revalidate. */
export const DEFAULT_REVALIDATE_SECONDS = 3600;

export class ApiError extends Error {
    readonly status: number;
    readonly problem: ProblemDetails;

    constructor(problem: ProblemDetails) {
        super(`${problem.status} ${problem.title}${problem.detail ? `: ${problem.detail}` : ''}`);
        this.name = 'ApiError';
        this.status = problem.status;
        this.problem = problem;
    }

    get isNotFound(): boolean {
        return this.status === 404;
    }
}

export class ApiConfigError extends Error {
    constructor(variable: string) {
        super(`${variable} is not set. The storefront reads it from the server environment (see .env.example).`);
        this.name = 'ApiConfigError';
    }
}

interface RequestOptions {
    locale?: Locale;
    /** Seconds; `cached` only. Defaults to the ISR ceiling. */
    revalidate?: number;
    /** Extra request headers (`X-Cart-Token`, `Idempotency-Key`). Never cached: use with noStore/send. */
    headers?: Record<string, string>;
}

function serverConfig(): { baseUrl: string; token: string } {
    const baseUrl = process.env.API_URL;
    const token = process.env.STOREFRONT_API_TOKEN;
    if (!baseUrl) throw new ApiConfigError('API_URL');
    if (!token) throw new ApiConfigError('STOREFRONT_API_TOKEN');
    return { baseUrl: baseUrl.replace(/\/+$/, ''), token };
}

export function apiUrl(path: string): string {
    const { baseUrl } = serverConfig();
    return `${baseUrl}/api/v1/${path.replace(/^\/+/, '')}`;
}

function headers(locale: Locale | undefined): Record<string, string> {
    const { token } = serverConfig();
    return {
        Accept: 'application/json, application/problem+json',
        Authorization: `Bearer ${token}`,
        ...(locale ? { 'Accept-Language': locale } : {}),
    };
}

/** Turn a non-2xx response into a typed ApiError, accepting problem+json or anything else. */
export async function toApiError(response: Response): Promise<ApiError> {
    const fallback: ProblemDetails = {
        type: 'about:blank',
        title: response.statusText || 'Request failed',
        status: response.status,
    };
    const contentType = response.headers.get('content-type') ?? '';
    if (!contentType.includes('json')) return new ApiError(fallback);
    try {
        const body = (await response.json()) as Partial<ProblemDetails> | null;
        if (!body || typeof body !== 'object') return new ApiError(fallback);
        return new ApiError({
            ...body,
            type: typeof body.type === 'string' ? body.type : fallback.type,
            title: typeof body.title === 'string' ? body.title : fallback.title,
            status: typeof body.status === 'number' ? body.status : response.status,
            ...(typeof body.detail === 'string' ? { detail: body.detail } : {}),
        });
    } catch {
        return new ApiError(fallback);
    }
}

/**
 * `X-Forwarded-For: <shopper IP>` for a per-request (uncached) call, so Laravel's per-IP rate limits
 * see the shopper, not this server (lib/client-ip.ts; Laravel trusts it only from TRUSTED_PROXIES).
 * Never on `cached()`: the header would split the ISR cache per shopper, and a cached answer is not
 * the shopper's request anyway. Outside a request scope (build, revalidation) there is no shopper.
 */
async function forwardedFor(): Promise<Record<string, string>> {
    try {
        const ip = clientIpFrom(await incomingHeaders());
        return ip ? { 'X-Forwarded-For': ip } : {};
    } catch {
        return {};
    }
}

async function request<T>(path: string, init: RequestInit, options: RequestOptions): Promise<T> {
    const forwarded = init.cache === 'no-store' ? await forwardedFor() : {};
    const response = await fetch(apiUrl(path), { ...init, method: 'GET', headers: { ...headers(options.locale), ...forwarded, ...options.headers } });
    if (!response.ok) throw await toApiError(response);
    return (await response.json()) as T;
}

/**
 * Cached GET for catalog data: ISR with cache tags (docs/api/revalidation.md). Tags must come from
 * the tag vocabulary there, e.g. `['brands']` or `['brands', 'brand:lego']`.
 */
export function cached<T>(path: string, tags: string[], options: RequestOptions = {}): Promise<T> {
    return request<T>(path, { next: { tags, revalidate: options.revalidate ?? DEFAULT_REVALIDATE_SECONDS } }, options);
}

/** Uncached GET for per-request data (cart, stock at checkout, order tracking). */
export function noStore<T>(path: string, options: RequestOptions = {}): Promise<T> {
    return request<T>(path, { cache: 'no-store' }, options);
}

export interface SendResult<T> {
    status: number;
    data: T;
    headers: Headers;
}

/**
 * Uncached write (POST / PATCH / DELETE) with an optional JSON body. A non-2xx response throws an
 * ApiError carrying the problem document (and its `errors[]`); a 204 resolves with `data: null`.
 */
export async function send<T>(method: 'POST' | 'PATCH' | 'PUT' | 'DELETE', path: string, options: Omit<RequestOptions, 'revalidate'> & { body?: unknown } = {}): Promise<SendResult<T>> {
    const init: RequestInit = {
        method,
        cache: 'no-store',
        headers: {
            ...headers(options.locale),
            ...(await forwardedFor()),
            ...(options.body !== undefined ? { 'Content-Type': 'application/json' } : {}),
            ...options.headers,
        },
        ...(options.body !== undefined ? { body: JSON.stringify(options.body) } : {}),
    };
    const response = await fetch(apiUrl(path), init);
    if (!response.ok) throw await toApiError(response);
    const text = response.status === 204 ? '' : await response.text();
    return { status: response.status, data: (text ? JSON.parse(text) : null) as T, headers: response.headers };
}

/** `errors[]` of a 422 / 409 problem (`[{field, message}]`), tolerating a missing or malformed list. */
export function problemErrors(error: ApiError): Array<{ field: string; message: string }> {
    const raw = error.problem.errors;
    if (!Array.isArray(raw)) return [];
    return raw.flatMap((e: unknown) => {
        if (typeof e !== 'object' || e === null) return [];
        const { field, message } = e as { field?: unknown; message?: unknown };
        return typeof message === 'string' ? [{ field: typeof field === 'string' ? field : '', message }] : [];
    });
}

/** The stable problem code: `https://toymartbd.com/problems/price-changed` → `price-changed`. */
export function problemType(error: ApiError): string {
    return error.problem.type.split('/').pop() ?? '';
}
