import 'server-only';

import { NextResponse } from 'next/server';

import { isLocale, localizedPath, type Locale } from '../i18n/config';
import { CART_COOKIE, CART_COUNT_COOKIE, CART_MAX_AGE, FLASH_COOKIE, encodeNotice, validToken } from './server';
import type { Cart, CartActionResponse, CartNotice } from './types';

// Shared plumbing for the cart Route Handlers (app/api/cart/*). Every cart form is a plain
// `<form method="post">`, so it works without JavaScript: the handler answers 303 to the page to
// show next, with a one-shot notice in the flash cookie. A JS-enhanced form sends
// `Accept: application/json` and gets a CartActionResponse instead (no redirect).

export interface CartRequest {
    form: FormData;
    locale: Locale;
    token: string | null;
    wantsJson: boolean;
}

export async function readCartRequest(request: Request): Promise<CartRequest> {
    const form = await request.formData().catch(() => new FormData());
    const locale = String(form.get('locale') ?? '');
    const cookie = request.headers.get('cookie') ?? '';
    const token = validToken(readCookie(cookie, CART_COOKIE));
    return {
        form,
        locale: isLocale(locale) ? locale : 'en',
        token,
        wantsJson: (request.headers.get('accept') ?? '').includes('application/json'),
    };
}

export function readCookie(header: string, name: string): string | null {
    for (const part of header.split(';')) {
        const [key, ...rest] = part.trim().split('=');
        if (key === name) return decodeURIComponent(rest.join('='));
    }
    return null;
}

/** A whole number in [min, max] from a form field, or null. */
export function intField(form: FormData, key: string, min: number, max: number): number | null {
    const raw = String(form.get(key) ?? '').trim();
    if (!/^\d{1,9}$/.test(raw)) return null;
    const n = Number(raw);
    return n >= min && n <= max ? n : null;
}

const secure = () => process.env.NODE_ENV === 'production' && !(process.env.SITE_URL ?? '').startsWith('http://');

/** Remember the cart: httpOnly token + the readable item count for cached pages' header badge. */
export function setCartCookies(response: NextResponse, cart: Cart): void {
    response.cookies.set(CART_COOKIE, cart.token, { httpOnly: true, sameSite: 'lax', secure: secure(), path: '/', maxAge: CART_MAX_AGE });
    response.cookies.set(CART_COUNT_COOKIE, String(cart.item_count), { httpOnly: false, sameSite: 'lax', secure: secure(), path: '/', maxAge: CART_MAX_AGE });
}

export function clearCartCookies(response: NextResponse): void {
    response.cookies.delete(CART_COOKIE);
    response.cookies.delete(CART_COUNT_COOKIE);
}

export function setFlash(response: NextResponse, notice: CartNotice): void {
    response.cookies.set(FLASH_COOKIE, encodeNotice(notice), { httpOnly: true, sameSite: 'lax', secure: secure(), path: '/', maxAge: 60 });
}

/**
 * 303 to a locale page (`/cart` → `/bn/cart`). The Location is relative on purpose: behind a proxy
 * (or `next start --hostname`) `request.url` can name another host than the browser used, and the
 * cart cookie just set would not travel to it.
 */
export function redirectTo(_request: Request, locale: Locale, path: string): NextResponse {
    return new NextResponse(null, { status: 303, headers: { Location: localizedPath(locale, path) } });
}

export function jsonResponse(body: CartActionResponse, status = 200): NextResponse {
    return NextResponse.json(body, { status, headers: { 'Cache-Control': 'no-store' } });
}

/** Finish a cart write: cookies, then JSON or a 303 with the notice. */
export function finish(
    req: CartRequest,
    request: Request,
    result: { cart: Cart | null; gone?: boolean; notice: CartNotice | null },
    next: string,
): NextResponse {
    const ok = result.notice?.kind !== 'error';
    const response = req.wantsJson
        ? jsonResponse({ ok, itemCount: result.cart?.item_count ?? (result.gone ? 0 : null), notice: result.notice }, ok ? 200 : result.gone ? 404 : 422)
        : redirectTo(request, req.locale, next);
    if (result.cart) setCartCookies(response, result.cart);
    else if (result.gone) clearCartCookies(response);
    if (!req.wantsJson && result.notice) setFlash(response, result.notice);
    return response;
}
