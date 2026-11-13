import { NextResponse } from 'next/server';

import { ApiError, problemErrors, problemType, send } from '@/lib/api';
import {
    CART_MAX_AGE,
    CHECKOUT_DRAFT_COOKIE,
    CHECKOUT_DRAFT_MAX_AGE,
    CHECKOUT_FLASH_COOKIE,
    DEST_COOKIE,
    ORDER_COOKIE,
    ORDER_MAX_AGE,
} from '@/lib/cart/cookies';
import { clearCartCookies, readCartRequest, redirectTo, setFlash, type CartRequest } from '@/lib/cart/route';
import { applyCoupon, removeCoupon, serializeDestination } from '@/lib/cart/server';
import type { StorefrontOrder } from '@/lib/cart/types';
import { checkoutBody, draftId, encodeDraft, encodeFlash, readDraft, readPaymentMethod, splitErrors, validAmount, validKey, type CheckoutDraft, type CheckoutFlash } from '@/lib/checkout/form';
import { getMessages, localizedPath } from '@/lib/i18n';
import { isOnlineMethod, ONLINE_METHODS } from '@/lib/payments/methods';
import { startPayment } from '@/lib/payments/start';

// POST /api/checkout — the checkout form (P4-STO-09, docs/api/checkout.md). One handler, four intents:
//   place          → POST /storefront/checkout with the cart token, the page's Idempotency-Key and
//                    the total the customer saw; 201 → confirmation (COD) or, for an online method,
//                    POST /orders/{n}/payment and on to the gateway's page; else back with the errors.
//   refresh        → the address changed: remember the district/upazila so the page re-renders with
//                    the API's delivery charge and total (the "Update total" button without JS).
//   coupon / coupon_remove → the cart's coupon, without losing what was typed.
// Without JS every answer is a 303 (the typed fields ride in a 30-minute httpOnly draft cookie, the
// errors in a one-shot flash cookie). With JS (Accept: JSON) it is `{redirect}` or `{refresh: true}`
// and the page re-renders in place — no PII cookie needed. The client never computes a total: the
// `expected_total` it sends is the API's own figure the page rendered.

export const dynamic = 'force-dynamic';

const secure = () => process.env.NODE_ENV === 'production' && !(process.env.SITE_URL ?? '').startsWith('http://');

interface Outcome {
    /** Locale-independent page to go to next. */
    next: string;
    flash?: CheckoutFlash;
    /** Keep the typed fields for the no-JS round trip. */
    keepDraft?: boolean;
    after?: (response: NextResponse) => void;
    /** An absolute https URL the API handed us (the gateway's hosted page) — overrides `next`. */
    external?: string;
}

function respond(req: CartRequest, request: Request, draft: CheckoutDraft, outcome: Outcome): NextResponse {
    const response = outcome.external
        ? req.wantsJson
            ? NextResponse.json({ redirect: outcome.external }, { headers: { 'Cache-Control': 'no-store' } })
            : new NextResponse(null, { status: 303, headers: { Location: outcome.external } })
        : req.wantsJson
          ? NextResponse.json(outcome.next === '/checkout' ? { refresh: true } : { redirect: localizedPath(req.locale, outcome.next) }, { headers: { 'Cache-Control': 'no-store' } })
          : redirectTo(request, req.locale, outcome.next);
    const district = draftId(draft.district_id);
    if (district !== null) {
        response.cookies.set(DEST_COOKIE, serializeDestination({ districtId: district, upazilaId: draftId(draft.upazila_id) }), {
            httpOnly: true,
            sameSite: 'lax',
            secure: secure(),
            path: '/',
            maxAge: CART_MAX_AGE,
        });
    }
    if (outcome.flash) response.cookies.set(CHECKOUT_FLASH_COOKIE, encodeFlash(outcome.flash), { httpOnly: true, sameSite: 'lax', secure: secure(), path: '/', maxAge: 60 });
    if (outcome.keepDraft && !req.wantsJson) {
        response.cookies.set(CHECKOUT_DRAFT_COOKIE, encodeDraft(draft), { httpOnly: true, sameSite: 'lax', secure: secure(), path: '/', maxAge: CHECKOUT_DRAFT_MAX_AGE });
    }
    outcome.after?.(response);
    return response;
}

const notice = (message: string): CheckoutFlash => ({ errors: {}, notice: message });

export async function POST(request: Request): Promise<Response> {
    const req = await readCartRequest(request);
    const t = getMessages(req.locale);
    const draft = readDraft(req.form);
    const intent = String(req.form.get('intent') ?? 'place');

    if (!req.token) {
        const response = req.wantsJson ? NextResponse.json({ redirect: localizedPath(req.locale, '/cart') }) : redirectTo(request, req.locale, '/cart');
        setFlash(response, { kind: 'error', target: 'cart', message: 'gone' });
        return response;
    }

    if (intent === 'refresh') return respond(req, request, draft, { next: '/checkout', keepDraft: true });

    if (intent === 'coupon' || intent === 'coupon_remove') {
        const code = String(req.form.get('coupon_code') ?? '').trim().toUpperCase().slice(0, 64);
        if (intent === 'coupon' && code === '') return respond(req, request, draft, { next: '/checkout', keepDraft: true, flash: notice(t.cart.notices.couponEmpty) });
        const result = intent === 'coupon' ? await applyCoupon(req.token, code, req.locale) : await removeCoupon(req.token, req.locale);
        if (!result.ok && result.gone) return respond(req, request, draft, { next: '/cart', after: clearCartCookies });
        const message = result.ok ? null : (t.cart.notices[result.notice.message as keyof typeof t.cart.notices] ?? result.notice.message);
        return respond(req, request, draft, { next: '/checkout', keepDraft: true, flash: message ? notice(message) : undefined });
    }

    const method = readPaymentMethod(req.form, ONLINE_METHODS);
    const key = validKey(req.form.get('idempotency_key'));
    const expected = validAmount(String(req.form.get('expected_total') ?? ''));
    if (!key || !expected) return respond(req, request, draft, { next: '/checkout', keepDraft: true, flash: notice(t.checkout.priceChanged) });

    try {
        const { data: order } = await send<StorefrontOrder>('POST', 'storefront/checkout', {
            locale: req.locale,
            headers: { 'X-Cart-Token': req.token, 'Idempotency-Key': key },
            body: checkoutBody(draft, expected, method),
        });
        const token = req.token;
        // Online (P4-PAY-02): the order is placed and its stock held; now open the gateway session
        // (same key — the API scopes it by path) and send the browser there. If no session opens,
        // the payment page explains and offers a retry. COD goes straight to the confirmation.
        const started = isOnlineMethod(method) ? await startPayment(order.number, token, key, req.locale) : null;
        return respond(req, request, draft, {
            next: started ? `/orders/${order.number}/payment` : `/orders/${order.number}`,
            external: started?.ok ? started.url : undefined,
            after: (response) => {
                // The cart is closed; its token now only proves ownership of this order (checkout.md).
                clearCartCookies(response);
                response.cookies.delete(CHECKOUT_DRAFT_COOKIE);
                response.cookies.set(ORDER_COOKIE, token, { httpOnly: true, sameSite: 'lax', secure: secure(), path: '/', maxAge: ORDER_MAX_AGE });
            },
        });
    } catch (error) {
        // Unreachable API: nothing was placed that we know of; the same key makes a retry safe.
        if (!(error instanceof ApiError)) return respond(req, request, draft, { next: '/checkout', keepDraft: true, flash: notice('failed') });
        const type = problemType(error);
        const errors = problemErrors(error);
        switch (true) {
            case error.status === 404:
                return respond(req, request, draft, {
                    next: '/cart',
                    after: (response) => {
                        clearCartCookies(response);
                        setFlash(response, { kind: 'error', target: 'cart', message: 'gone' });
                    },
                });
            case type === 'price-changed':
                return respond(req, request, draft, { next: '/checkout', keepDraft: true, flash: notice(errors[0]?.message ?? t.checkout.priceChanged) });
            case type === 'insufficient-stock':
                return respond(req, request, draft, {
                    next: '/cart',
                    keepDraft: true,
                    after: (response) => setFlash(response, { kind: 'error', target: 'cart', message: t.checkout.stockChanged }),
                });
            case type === 'request-in-progress':
                return respond(req, request, draft, { next: '/checkout', keepDraft: true, flash: notice('busy') });
            case type === 'validation-error': {
                const { fields, general } = splitErrors(errors);
                return respond(req, request, draft, { next: '/checkout', keepDraft: true, flash: { errors: fields, notice: general ?? (Object.keys(fields).length ? 'summary' : 'failed') } });
            }
            case type === 'idempotency-key-reused':
                // The page renders a fresh key on the way back; pressing again is a new attempt.
                return respond(req, request, draft, { next: '/checkout', keepDraft: true, flash: notice('failed') });
            case error.status === 429:
                return respond(req, request, draft, { next: '/checkout', keepDraft: true, flash: notice('tooMany') });
            case error.status === 503:
                return respond(req, request, draft, { next: '/checkout', keepDraft: true, flash: notice('unavailable') });
            default:
                return respond(req, request, draft, { next: '/checkout', keepDraft: true, flash: notice('failed') });
        }
    }
}
