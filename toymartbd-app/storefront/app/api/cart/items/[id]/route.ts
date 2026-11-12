import { removeItem, updateItem } from '@/lib/cart/server';
import { finish, intField, readCartRequest } from '@/lib/cart/route';

// POST /api/cart/items/{id} — a cart line's quantity stepper or remove button (P4-STO-08).
// intent = update (quantity 1–99) | inc | dec | remove. Raising past stock is the API's 422 with its
// own message ("Only 2 left in stock."), shown on that line. Lowering is always allowed.

export const dynamic = 'force-dynamic';

export async function POST(request: Request, { params }: { params: Promise<{ id: string }> }): Promise<Response> {
    const req = await readCartRequest(request);
    const { id } = await params;
    const itemId = /^[1-9]\d{0,9}$/.test(id) ? Number(id) : null;
    if (itemId === null || !req.token) return finish(req, request, { cart: null, gone: !req.token, notice: { kind: 'error', target: 'cart', message: 'gone' } }, '/cart');

    const intent = String(req.form.get('intent') ?? 'update');
    const current = intField(req.form, 'quantity', 0, 99);
    let result;
    if (intent === 'remove' || (intent === 'dec' && current !== null && current <= 1)) {
        result = await removeItem(req.token, itemId, req.locale);
    } else {
        const quantity = intent === 'inc' && current !== null ? current + 1 : intent === 'dec' && current !== null ? current - 1 : current;
        if (quantity === null || quantity < 1 || quantity > 99) {
            return finish(req, request, { cart: null, notice: { kind: 'error', target: String(itemId), message: 'quantity' } }, '/cart');
        }
        result = await updateItem(req.token, itemId, quantity, req.locale);
    }
    if (!result.ok) return finish(req, request, { cart: null, gone: result.gone, notice: result.notice }, '/cart');
    return finish(req, request, { cart: result.cart, notice: null }, '/cart');
}
