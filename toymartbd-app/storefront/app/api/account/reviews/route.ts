import { accountWrite } from '@/lib/account/server';
import { clearAccountCookies, field, readAccountRequest, redirect303 } from '@/lib/account/route';
import { intField } from '@/lib/cart/route';

// POST /api/account/reviews — the PDP "Write a review" form (P4-CAT-12). A plain `<form method="post">`,
// so it works without JavaScript. Signed out (or a stale token): 303 to sign-in, which brings the
// shopper back to the product's reviews. Signed in: the review goes to Laravel with the customer's
// own token (rule 28) and the shopper lands back on the product with `?review=sent|duplicate|invalid|
// failed#reviews`. Verification is derived by the API from a delivered order line — never sent from here.

export const dynamic = 'force-dynamic';

const SLUG = /^[a-z0-9]+(?:-[a-z0-9]+)*$/;

export async function POST(request: Request): Promise<Response> {
    const req = await readAccountRequest(request);
    const slugField = field(req.form, 'slug', 92);
    const slug = SLUG.test(slugField) ? slugField : null;
    const back = (outcome: string) => redirect303(req.locale, slug ? `/products/${slug}?review=${outcome}#reviews` : '/');
    const login = () => redirect303(req.locale, `/account/login?next=${encodeURIComponent(slug ? `/products/${slug}` : '/account')}`, { message: 'sessionEnded' });

    if (!req.token) return login();

    const productId = intField(req.form, 'product_id', 1, 999_999_999);
    const rating = intField(req.form, 'rating', 1, 5);
    const body = field(req.form, 'body', 5000);
    if (productId === null || rating === null || body.length < 10) return back('invalid');

    const title = field(req.form, 'title', 160);
    const name = field(req.form, 'reviewer_name', 120);
    const result = await accountWrite('POST', 'storefront/account/reviews', req.token, req.locale, {
        product_id: productId,
        rating,
        body,
        ...(title ? { title } : {}),
        ...(name ? { reviewer_name: name } : {}),
    });

    if (result.ok) return back('sent');
    if (result.status === 401) {
        const response = login();
        clearAccountCookies(response);
        return response;
    }
    return back(result.status === 409 ? 'duplicate' : result.status === 422 ? 'invalid' : 'failed');
}
