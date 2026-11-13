import 'server-only';

import { cookies } from 'next/headers';
import { redirect } from 'next/navigation';

import { ApiError, noStore, send } from '../api';
import type { StorefrontOrder } from '../cart/types';
import { localizedPath, type Locale } from '../i18n/config';
import { ACCOUNT_COOKIE, ACCOUNT_FLASH_COOKIE } from './cookies';
import { decodeFlash } from './flash';
import type { Account, AccountConsents, AccountFlash, OrderGroup, OrderPage, SavedAddress, WishlistItem } from './types';

// The customer account, server side (P4-STO-11, docs/api/account.md). The customer's Sanctum token
// lives in an httpOnly cookie, is read only here and in the account Server Actions, and is sent to
// Laravel as the bearer token of `/storefront/account/*` — it never reaches client JavaScript or a
// URL (rule 28). Every figure on these pages is the API's (rule 1).

const TOKEN = /^\d+\|[A-Za-z0-9]{20,100}$/;

export function validAccountToken(value: string | undefined | null): string | null {
    return value && TOKEN.test(value) ? value : null;
}

export async function accountToken(): Promise<string | null> {
    return validAccountToken((await cookies()).get(ACCOUNT_COOKIE)?.value);
}

export async function readAccountFlash(): Promise<AccountFlash | null> {
    return decodeFlash((await cookies()).get(ACCOUNT_FLASH_COOKIE)?.value);
}

/** Request headers for a customer call: the customer's token replaces the server's own. */
export function customerHeaders(token: string): Record<string, string> {
    return { Authorization: `Bearer ${token}` };
}

export class SignedOut extends Error {
    constructor() {
        super('The customer token is missing, expired or revoked.');
        this.name = 'SignedOut';
    }
}

async function read<T>(path: string, token: string, locale: Locale): Promise<T> {
    try {
        return await noStore<T>(path, { locale, headers: customerHeaders(token) });
    } catch (error) {
        if (error instanceof ApiError && error.status === 401) throw new SignedOut();
        throw error;
    }
}

export interface AccountContext {
    token: string;
    account: Account;
}

/**
 * The signed-in customer for an account page, or a redirect to sign-in (`?next=` brings them back).
 * A stale cookie (expired / revoked token) is treated exactly like no cookie.
 */
export async function requireAccount(locale: Locale, path: string): Promise<AccountContext> {
    const token = await accountToken();
    const login = `${localizedPath(locale, '/account/login')}?next=${encodeURIComponent(path)}`;
    if (!token) redirect(login);
    try {
        return { token, account: await read<Account>('storefront/account', token, locale) };
    } catch (error) {
        if (error instanceof SignedOut) redirect(login);
        throw error;
    }
}

export async function getOrders(token: string, locale: Locale, group: OrderGroup | null, cursor: string | null): Promise<OrderPage> {
    const q = new URLSearchParams();
    if (group) q.set('group', group);
    if (cursor) q.set('cursor', cursor);
    const query = q.toString();
    return read<OrderPage>(`storefront/account/orders${query ? `?${query}` : ''}`, token, locale);
}

/** One of the customer's own orders, or null (another customer's number is the same 404). */
export async function getOrder(token: string, number: string, locale: Locale): Promise<StorefrontOrder | null> {
    try {
        return await read<StorefrontOrder>(`storefront/account/orders/${encodeURIComponent(number)}`, token, locale);
    } catch (error) {
        if (error instanceof ApiError && error.isNotFound) return null;
        throw error;
    }
}

export async function getAddresses(token: string, locale: Locale): Promise<SavedAddress[]> {
    return (await read<{ data: SavedAddress[] }>('storefront/account/addresses', token, locale)).data;
}

export async function getWishlist(token: string, locale: Locale): Promise<WishlistItem[]> {
    return (await read<{ data: WishlistItem[] }>('storefront/account/wishlist', token, locale)).data;
}

/** The customer's own message consents (P8: birthday). No customer id is sent — the token is the customer. */
export async function getConsents(token: string, locale: Locale): Promise<AccountConsents> {
    return read<AccountConsents>('storefront/account/consents', token, locale);
}

export type Write<T> = { ok: true; data: T } | { ok: false; status: number; message: string | null; errors: Record<string, string> };

/** A customer write; 4xx come back as a result (field errors keyed by the API's field), 5xx throw. */
export async function accountWrite<T>(method: 'POST' | 'PATCH' | 'PUT' | 'DELETE', path: string, token: string | null, locale: Locale, body?: unknown, headers: Record<string, string> = {}): Promise<Write<T>> {
    try {
        const { data } = await send<T>(method, path, { locale, body, headers: { ...(token ? customerHeaders(token) : {}), ...headers } });
        return { ok: true, data };
    } catch (error) {
        if (!(error instanceof ApiError) || error.status >= 500) throw error;
        const errors: Record<string, string> = {};
        const raw = error.problem.errors;
        if (Array.isArray(raw)) {
            for (const e of raw as unknown[]) {
                if (typeof e !== 'object' || e === null) continue;
                const { field, message } = e as { field?: unknown; message?: unknown };
                if (typeof field === 'string' && typeof message === 'string' && !(field in errors)) errors[field.split('.')[0] ?? field] = message;
            }
        }
        return { ok: false, status: error.status, message: error.problem.detail ?? null, errors };
    }
}
