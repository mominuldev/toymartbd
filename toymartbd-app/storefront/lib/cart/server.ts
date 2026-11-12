import 'server-only';

import { ApiError, noStore, problemErrors, send } from '../api';
import type { Locale } from '../i18n/config';
import type { Cart, CartNotice } from './types';

// The cart, server side (P4-STO-08, docs/api/cart.md). The cart token is the customer's handle on
// their cart: it lives in an httpOnly cookie, is read only here and in Route Handlers, and is
// forwarded to Laravel as `X-Cart-Token` — never in a URL, never to client JavaScript (rule 28).
// Every figure is the API's PricingPipeline output; nothing here adds or rounds (rule 1).

export { CART_COOKIE, CART_COUNT_COOKIE, ORDER_COOKIE, DEST_COOKIE, FLASH_COOKIE, CART_MAX_AGE, ORDER_MAX_AGE } from './cookies';

const TOKEN = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

/** A cookie value that could be a cart token; anything else is ignored rather than forwarded. */
export function validToken(value: string | undefined | null): string | null {
    return value && TOKEN.test(value) ? value : null;
}

export interface Destination {
    districtId: number;
    upazilaId: number | null;
}

const ID = /^[1-9]\d{0,8}$/;

export function parseDestination(value: string | undefined | null): Destination | null {
    const [d, u] = (value ?? '').split(':');
    if (!d || !ID.test(d)) return null;
    return { districtId: Number(d), upazilaId: u && ID.test(u) ? Number(u) : null };
}

export function serializeDestination(dest: Destination): string {
    return `${dest.districtId}:${dest.upazilaId ?? ''}`;
}

export function cartPath(dest: Destination | null): string {
    if (!dest) return 'storefront/cart';
    const q = new URLSearchParams({ district_id: String(dest.districtId) });
    if (dest.upazilaId !== null) q.set('upazila_id', String(dest.upazilaId));
    return `storefront/cart?${q.toString()}`;
}

function cartHeaders(token: string): Record<string, string> {
    return { 'X-Cart-Token': token };
}

/** The priced cart, or null when there is no cart (no cookie, or the API no longer knows the token). */
export async function getCart(token: string | null, dest: Destination | null, locale: Locale): Promise<Cart | null> {
    if (!token) return null;
    try {
        return await noStore<Cart>(cartPath(dest), { locale, headers: cartHeaders(token) });
    } catch (error) {
        if (error instanceof ApiError && error.isNotFound) return null;
        throw error;
    }
}

export type CartWrite =
    | { ok: true; cart: Cart }
    /** `gone`: the token is unknown/expired/converted — drop the cookie and start over. */
    | { ok: false; gone: boolean; notice: CartNotice };

async function write(target: string, token: string | null, call: (headers: Record<string, string>) => Promise<{ data: Cart }>): Promise<CartWrite> {
    try {
        const { data } = await call(token ? cartHeaders(token) : {});
        return { ok: true, cart: data };
    } catch (error) {
        if (!(error instanceof ApiError)) throw error;
        if (error.isNotFound) return { ok: false, gone: true, notice: { kind: 'error', target, message: 'gone' } };
        if (error.status === 422) {
            // The API's own localised message ("Only 3 left in stock.") — shown verbatim.
            const first = problemErrors(error)[0];
            return { ok: false, gone: false, notice: { kind: 'error', target, message: first?.message ?? error.problem.detail ?? 'failed' } };
        }
        if (error.status >= 500) throw error;
        return { ok: false, gone: false, notice: { kind: 'error', target, message: 'failed' } };
    }
}

export function addItem(token: string | null, variantId: number, quantity: number, locale: Locale): Promise<CartWrite> {
    return write('add', token, (headers) => send<Cart>('POST', 'storefront/cart/items', { locale, headers, body: { variant_id: variantId, quantity } }));
}

export function updateItem(token: string, itemId: number, quantity: number, locale: Locale): Promise<CartWrite> {
    return write(String(itemId), token, (headers) => send<Cart>('PATCH', `storefront/cart/items/${itemId}`, { locale, headers, body: { quantity } }));
}

export function removeItem(token: string, itemId: number, locale: Locale): Promise<CartWrite> {
    return write(String(itemId), token, (headers) => send<Cart>('DELETE', `storefront/cart/items/${itemId}`, { locale, headers }));
}

export function applyCoupon(token: string, code: string, locale: Locale): Promise<CartWrite> {
    return write('coupon', token, (headers) => send<Cart>('POST', 'storefront/cart/coupon', { locale, headers, body: { code } }));
}

export function removeCoupon(token: string, locale: Locale): Promise<CartWrite> {
    return write('coupon', token, (headers) => send<Cart>('DELETE', 'storefront/cart/coupon', { locale, headers }));
}

/** Flash cookie value: base64url JSON, so Bangla survives the cookie header. */
export function encodeNotice(notice: CartNotice): string {
    return Buffer.from(JSON.stringify(notice), 'utf8').toString('base64url');
}

export function decodeNotice(value: string | undefined | null): CartNotice | null {
    if (!value || value.length > 2048) return null;
    try {
        const raw = JSON.parse(Buffer.from(value, 'base64url').toString('utf8')) as Partial<CartNotice>;
        if ((raw.kind !== 'error' && raw.kind !== 'success') || typeof raw.target !== 'string' || typeof raw.message !== 'string') return null;
        return { kind: raw.kind, target: raw.target.slice(0, 32), message: raw.message.slice(0, 300) };
    } catch {
        return null;
    }
}
