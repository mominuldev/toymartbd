import { addItem, recommendationSource } from '@/lib/cart/server';
import { finish, intField, readCartRequest, redirectTo } from '@/lib/cart/route';

// POST /api/cart/items — the PDP "Add to cart" / "Buy now" form (P4-STO-08). Fields: variant_id,
// quantity (1–99), intent (add | buy_now | notify), locale, back (the product path for `notify`),
// and optionally `recommendation_source` — the placement the add came from (rule 25d; an unknown
// value is dropped here rather than failing the add).
// No cart cookie yet → the API creates the cart and we store its token (httpOnly). Without JS the
// answer is a 303: Add → the cart, Buy now → checkout; a refusal (e.g. the API's "Only 3 left in
// stock.") lands on the cart page as a notice. With JS (Accept: application/json) it is JSON.

export const dynamic = 'force-dynamic';

const PATH = /^\/[a-z0-9/_-]{0,200}(?:\?variant=\d{1,9})?$/;

export async function POST(request: Request): Promise<Response> {
    const req = await readCartRequest(request);
    const intent = String(req.form.get('intent') ?? 'add');
    if (intent === 'notify') {
        // Restock alerts are not built yet: go back to the product, nothing stored, nothing faked.
        const back = String(req.form.get('back') ?? '/');
        return redirectTo(request, req.locale, PATH.test(back) ? back : '/');
    }

    const variantId = intField(req.form, 'variant_id', 1, 999_999_999);
    const quantity = intField(req.form, 'quantity', 1, 99) ?? 1;
    if (variantId === null) {
        return finish(req, request, { cart: null, notice: { kind: 'error', target: 'add', message: 'failed' } }, '/cart');
    }

    const source = recommendationSource(req.form.get('recommendation_source'));
    let result = await addItem(req.token, variantId, quantity, req.locale, source);
    // A stale cookie (expired or already ordered): start a fresh cart rather than failing the add.
    if (!result.ok && result.gone && req.token) result = await addItem(null, variantId, quantity, req.locale, source);

    if (!result.ok) return finish(req, request, { cart: null, gone: result.gone, notice: result.notice }, '/cart');
    return finish(req, request, { cart: result.cart, notice: req.wantsJson ? { kind: 'success', target: 'add', message: 'added' } : null }, intent === 'buy_now' ? '/checkout' : '/cart');
}
