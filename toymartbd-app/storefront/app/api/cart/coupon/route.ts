import { applyCoupon, removeCoupon } from '@/lib/cart/server';
import { finish, readCartRequest } from '@/lib/cart/route';

// POST /api/cart/coupon — intent = apply (code) | remove (P4-STO-08, CouponInput Figma 120:2862).
// A rejected code is the API's 422 with its localised reason ("Spend at least ৳2500.00 to use this
// coupon."); the cart keeps a non-applying coupon so the customer sees why (cart.md).

export const dynamic = 'force-dynamic';

export async function POST(request: Request): Promise<Response> {
    const req = await readCartRequest(request);
    const next = String(req.form.get('next') ?? '') === 'checkout' ? '/checkout' : '/cart';
    if (!req.token) return finish(req, request, { cart: null, gone: true, notice: { kind: 'error', target: 'cart', message: 'gone' } }, '/cart');

    const intent = String(req.form.get('intent') ?? 'apply');
    const code = String(req.form.get('code') ?? '').trim().toUpperCase().slice(0, 64);
    if (intent !== 'remove' && code === '') {
        return finish(req, request, { cart: null, notice: { kind: 'error', target: 'coupon', message: 'couponEmpty' } }, next);
    }
    const result = intent === 'remove' ? await removeCoupon(req.token, req.locale) : await applyCoupon(req.token, code, req.locale);
    if (!result.ok) return finish(req, request, { cart: null, gone: result.gone, notice: result.notice }, result.gone ? '/cart' : next);
    return finish(req, request, { cart: result.cart, notice: null }, next);
}
