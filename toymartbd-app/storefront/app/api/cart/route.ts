import { cookies } from 'next/headers';

import { CART_COOKIE, getCart, validToken } from '@/lib/cart/server';
import { isLocale } from '@/lib/i18n';
import { parseQuoteRequest } from '@/lib/delivery/fetch';

// GET /api/cart?district_id=&upazila_id=&locale= — the priced cart for client islands (checkout's
// running total when the address changes). The token stays server-side and is stripped from the
// body (rule 28); the figures are the API's, passed through (rule 1).

export const dynamic = 'force-dynamic';

export async function GET(request: Request): Promise<Response> {
    const params = new URL(request.url).searchParams;
    const quote = parseQuoteRequest((key) => params.get(key));
    const locale = params.get('locale') ?? '';
    const token = validToken((await cookies()).get(CART_COOKIE)?.value);
    const cart = await getCart(token, quote ? { districtId: quote.districtId, upazilaId: quote.upazilaId } : null, isLocale(locale) ? locale : 'en').catch(() => undefined);
    if (cart === undefined) return Response.json({ title: 'The cart is unavailable.', status: 502 }, { status: 502, headers: { 'Cache-Control': 'no-store' } });
    if (cart === null) return Response.json({ title: 'No cart.', status: 404 }, { status: 404, headers: { 'Cache-Control': 'no-store' } });
    const { token: _secret, ...safe } = cart;
    void _secret;
    return Response.json(safe, { headers: { 'Cache-Control': 'no-store' } });
}
