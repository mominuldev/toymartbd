import { DEST_COOKIE, CART_MAX_AGE, serializeDestination } from '@/lib/cart/server';
import { intField, readCartRequest, redirectTo } from '@/lib/cart/route';

// POST /api/cart/destination — the cart's "Delivery to" district (+ upazila) choice, so the cart's
// totals include the API's delivery charge for it (cart.md: GET /cart?district_id=&upazila_id=).
// Stored in an httpOnly cookie; a district alone is fine where the API does not need the upazila.

export const dynamic = 'force-dynamic';

export async function POST(request: Request): Promise<Response> {
    const req = await readCartRequest(request);
    const districtId = intField(req.form, 'district_id', 1, 999_999);
    const upazilaId = intField(req.form, 'upazila_id', 1, 999_999);
    const response = redirectTo(request, req.locale, String(req.form.get('next') ?? '') === 'checkout' ? '/checkout' : '/cart');
    if (districtId === null) response.cookies.delete(DEST_COOKIE);
    else response.cookies.set(DEST_COOKIE, serializeDestination({ districtId, upazilaId }), { httpOnly: true, sameSite: 'lax', path: '/', maxAge: CART_MAX_AGE });
    return response;
}
