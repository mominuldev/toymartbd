import { NextResponse } from 'next/server';

import { ORDER_COOKIE } from '@/lib/cart/cookies';
import { readCookie } from '@/lib/cart/route';
import { validToken } from '@/lib/cart/server';
import { ORDER_NUMBER, validKey } from '@/lib/checkout/form';
import { isLocale, localizedPath, type Locale } from '@/lib/i18n';
import { PAYMENT_FLASH_COOKIE } from '@/lib/payments/cookies';
import { startPayment } from '@/lib/payments/start';

// POST /api/payments/retry — the payment page's "Try payment again" (P4-PAY-04). Works without JS.
// The API opens a new session for the SAME order (never a second stock reservation) or hands back
// the live one; on success the browser goes to the gateway, otherwise back to the payment page with
// the reason in a one-shot flash cookie. The Idempotency-Key is minted by the page render.

export const dynamic = 'force-dynamic';

export async function POST(request: Request): Promise<Response> {
    const form = await request.formData().catch(() => new FormData());
    const rawLocale = String(form.get('locale') ?? '');
    const locale: Locale = isLocale(rawLocale) ? rawLocale : 'en';
    const number = String(form.get('number') ?? '').toUpperCase();
    const key = validKey(form.get('idempotency_key'));
    const token = validToken(readCookie(request.headers.get('cookie') ?? '', ORDER_COOKIE));

    if (!ORDER_NUMBER.test(number)) {
        return new NextResponse(null, { status: 303, headers: { Location: localizedPath(locale, '/track') } });
    }
    const back = localizedPath(locale, `/orders/${number}/payment`);
    const result = token && key ? await startPayment(number, token, key, locale) : { ok: false as const, reason: token ? 'failed' : 'not_found' };

    if (result.ok) {
        return new NextResponse(null, { status: 303, headers: { Location: result.url, 'Cache-Control': 'no-store' } });
    }
    const response = new NextResponse(null, { status: 303, headers: { Location: back, 'Cache-Control': 'no-store' } });
    const secure = process.env.NODE_ENV === 'production' && !(process.env.SITE_URL ?? '').startsWith('http://');
    response.cookies.set(PAYMENT_FLASH_COOKIE, result.reason.slice(0, 40), { httpOnly: true, sameSite: 'lax', secure, path: '/', maxAge: 60 });
    return response;
}
