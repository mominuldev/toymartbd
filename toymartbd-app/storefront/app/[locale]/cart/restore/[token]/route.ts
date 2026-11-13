import type { NextResponse } from 'next/server';

import { redirectTo, setCartCookies, setFlash } from '@/lib/cart/route';
import { restoreCart } from '@/lib/cart/restore';
import { getMessages, isLocale, type Locale } from '@/lib/i18n';

// GET /cart/restore/{token} (and /bn/cart/restore/{token}) — the link in an abandoned-cart SMS (P7).
// Server-side only: the token goes to the API in a POST body; the cart token it returns is set in
// the httpOnly cart cookie exactly as the cart Route Handlers do, then 303 to the cart. An expired
// or used link lands on the cart with a one-shot notice. The token is never logged.

export const dynamic = 'force-dynamic';

interface Context {
    params: Promise<{ locale: string; token: string }>;
}

export async function GET(request: Request, { params }: Context): Promise<NextResponse> {
    const { locale: raw, token } = await params;
    const locale: Locale = isLocale(raw) ? raw : 'en';
    const t = getMessages(locale).flashSale;

    let cart = null;
    try {
        cart = await restoreCart(token, locale);
    } catch {
        cart = null;
    }

    const response = redirectTo(request, locale, '/cart');
    response.headers.set('Cache-Control', 'no-store');
    response.headers.set('Referrer-Policy', 'no-referrer');
    if (cart) {
        setCartCookies(response, cart);
        setFlash(response, { kind: 'success', target: 'cart', message: t.restored });
    } else {
        setFlash(response, { kind: 'error', target: 'cart', message: t.restoreInvalid });
    }
    return response;
}
