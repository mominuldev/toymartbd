import { NextResponse } from 'next/server';

import { accountWrite, getWishlist, SignedOut, validAccountToken } from '@/lib/account/server';
import { clearAccountCookies, readAccountRequest, redirect303 } from '@/lib/account/route';
import { ACCOUNT_COOKIE } from '@/lib/account/cookies';
import type { WishlistItem } from '@/lib/account/types';
import { intField, readCookie } from '@/lib/cart/route';
import { isLocale } from '@/lib/i18n/config';

// /api/account/wishlist (P4-STO-11, storefront spec §A22 WishlistButton).
//
// POST — `intent=add|remove` with `product_id`, optional `back` (the page the heart was on). A plain
// form post answers 303 to `/account/wishlist`; a JS caller sending `Accept: application/json` gets
// `{ ok, saved }` instead (the hearts on cards and the product page). A guest gets 401
// `{ signIn: true }` (JSON) or a 303 to sign-in with `?next=` = the page they were on (no JS).
//
// GET — `{ productIds }` of the signed-in customer's wishlist, so the hearts can show their filled
// state client-side. Product pages and listings stay ISR-cached and identical for everyone; only this
// small private, uncached answer is per customer. A guest gets `[]` without any API call.

export const dynamic = 'force-dynamic';

const PRIVATE = { 'Cache-Control': 'private, no-store' };
/** A local page path (`/products/x`, `/bn/category/y?page=2`) — never a URL to elsewhere. */
const BACK = /^\/(?!\/)[A-Za-z0-9/_\-.?=&%]{0,300}$/;

function backPath(value: FormDataEntryValue | null): string | null {
    const back = typeof value === 'string' ? value : '';
    if (!BACK.test(back)) return null;
    // `next` is locale-less (the login page re-prefixes it), so drop a `/bn` prefix.
    const stripped = back.replace(/^\/bn(?=\/|$)/, '');
    return stripped === '' ? '/' : stripped;
}

function loginPath(next: string): string {
    return `/account/login?next=${encodeURIComponent(next)}`;
}

export async function GET(request: Request): Promise<Response> {
    const cookie = request.headers.get('cookie') ?? '';
    const token = validAccountToken(readCookie(cookie, ACCOUNT_COOKIE));
    if (!token) return NextResponse.json({ productIds: [] }, { headers: PRIVATE });
    const raw = new URL(request.url).searchParams.get('locale') ?? '';
    try {
        const items = await getWishlist(token, isLocale(raw) ? raw : 'en');
        return NextResponse.json({ productIds: items.map((item) => item.product_id) }, { headers: PRIVATE });
    } catch (error) {
        if (error instanceof SignedOut) return NextResponse.json({ productIds: [] }, { headers: PRIVATE });
        return NextResponse.json({ productIds: [] }, { status: 502, headers: PRIVATE });
    }
}

export async function POST(request: Request): Promise<Response> {
    const req = await readAccountRequest(request);
    const wantsJson = (request.headers.get('accept') ?? '').includes('application/json');
    const productId = intField(req.form, 'product_id', 1, 999_999_999);
    const add = req.form.get('intent') === 'add';
    const signIn = loginPath(backPath(req.form.get('back')) ?? '/account/wishlist');

    if (!req.token) {
        return wantsJson ? NextResponse.json({ ok: false, signIn: true }, { status: 401 }) : redirect303(req.locale, signIn, { message: 'sessionEnded' });
    }
    if (productId === null) return wantsJson ? NextResponse.json({ ok: false }, { status: 422 }) : redirect303(req.locale, '/account/wishlist');

    const result = add
        ? await accountWrite<WishlistItem>('POST', 'storefront/account/wishlist', req.token, req.locale, { product_id: productId })
        : await accountWrite<null>('DELETE', `storefront/account/wishlist/${productId}`, req.token, req.locale);

    if (!result.ok && result.status === 401) {
        const response = wantsJson ? NextResponse.json({ ok: false, signIn: true }, { status: 401 }) : redirect303(req.locale, signIn, { message: 'sessionEnded' });
        clearAccountCookies(response);
        return response;
    }
    if (wantsJson) {
        const headers = { 'Cache-Control': 'no-store' };
        return result.ok ? NextResponse.json({ ok: true, saved: add }, { headers }) : NextResponse.json({ ok: false, message: result.message }, { status: result.status, headers });
    }
    return redirect303(req.locale, '/account/wishlist', result.ok ? (add ? { kind: 'success', message: 'saved' } : { kind: 'success', message: 'removedFromWishlist' }) : { message: result.message ?? 'failed' });
}
