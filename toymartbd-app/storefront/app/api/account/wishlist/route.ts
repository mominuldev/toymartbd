import { NextResponse } from 'next/server';

import { accountWrite } from '@/lib/account/server';
import { clearAccountCookies, readAccountRequest, redirect303 } from '@/lib/account/route';
import type { WishlistItem } from '@/lib/account/types';
import { intField } from '@/lib/cart/route';

// POST /api/account/wishlist — `intent=add|remove` with `product_id` (P4-STO-11). A plain form post
// answers 303 to `/account/wishlist`; a JS caller sending `Accept: application/json` gets
// `{ ok, saved }` instead (for heart buttons on cards — guests get 401 and are sent to sign-in).

export const dynamic = 'force-dynamic';

export async function POST(request: Request): Promise<Response> {
    const req = await readAccountRequest(request);
    const wantsJson = (request.headers.get('accept') ?? '').includes('application/json');
    const productId = intField(req.form, 'product_id', 1, 999_999_999);
    const add = req.form.get('intent') === 'add';

    if (!req.token) {
        return wantsJson ? NextResponse.json({ ok: false, signIn: true }, { status: 401 }) : redirect303(req.locale, '/account/login?next=/account/wishlist', { message: 'sessionEnded' });
    }
    if (productId === null) return wantsJson ? NextResponse.json({ ok: false }, { status: 422 }) : redirect303(req.locale, '/account/wishlist');

    const result = add
        ? await accountWrite<WishlistItem>('POST', 'storefront/account/wishlist', req.token, req.locale, { product_id: productId })
        : await accountWrite<null>('DELETE', `storefront/account/wishlist/${productId}`, req.token, req.locale);

    if (!result.ok && result.status === 401) {
        const response = wantsJson ? NextResponse.json({ ok: false, signIn: true }, { status: 401 }) : redirect303(req.locale, '/account/login?next=/account/wishlist', { message: 'sessionEnded' });
        clearAccountCookies(response);
        return response;
    }
    if (wantsJson) {
        const headers = { 'Cache-Control': 'no-store' };
        return result.ok ? NextResponse.json({ ok: true, saved: add }, { headers }) : NextResponse.json({ ok: false, message: result.message }, { status: result.status, headers });
    }
    return redirect303(req.locale, '/account/wishlist', result.ok ? (add ? { kind: 'success', message: 'saved' } : { kind: 'success', message: 'removedFromWishlist' }) : { message: result.message ?? 'failed' });
}
