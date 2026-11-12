import { accountWrite } from '@/lib/account/server';
import { clearOtpCookie, readAccountRequest, redirect303, setAccountCookie, setMergedCart } from '@/lib/account/route';
import type { AccountSession } from '@/lib/account/types';

// POST /api/account/verify — "Verify & continue" (P4-STO-11). Sends the code with the guest cart's
// token (`X-Cart-Token`), so the API merges that cart into the customer's on sign-in; the merged
// cart's token replaces the cart cookie. The customer token is set httpOnly and never shown to JS.

export const dynamic = 'force-dynamic';

const BENGALI = '০১২৩৪৫৬৭৮৯';

/** The six boxes (d1…d6) or one `code` field (SMS autofill, no-JS paste), Bengali digits folded. */
function readCode(form: FormData): string {
    const boxes = [1, 2, 3, 4, 5, 6].map((n) => String(form.get(`d${n}`) ?? '')).join('');
    const raw = boxes.trim() !== '' ? boxes : String(form.get('code') ?? '');
    return raw.replace(/[০-৯]/g, (d) => String(BENGALI.indexOf(d))).replace(/\D/g, '').slice(0, 6);
}

export async function POST(request: Request): Promise<Response> {
    const req = await readAccountRequest(request);
    if (!req.otp) return redirect303(req.locale, '/account/login', { message: 'expired' });

    const code = readCode(req.form);
    if (!/^\d{6}$/.test(code)) return redirect303(req.locale, '/account/verify', { errors: { code: 'codeInvalid' } });

    const result = await accountWrite<AccountSession>('POST', 'storefront/auth/otp/verify', null, req.locale, { phone: req.otp.phone, code }, req.cartToken ? { 'X-Cart-Token': req.cartToken } : {});
    if (!result.ok) {
        if (result.status === 422) return redirect303(req.locale, '/account/verify', { errors: { code: result.errors.code ?? 'codeInvalid' } });
        return redirect303(req.locale, '/account/verify', { message: result.status === 429 || result.status === 403 ? result.message : 'failed' });
    }

    const session = result.data;
    const welcome = session.customer.name.trim() === '';
    const response = redirect303(req.locale, welcome ? '/account/profile?welcome=1' : req.otp.next, { kind: 'success', message: 'signedIn' });
    setAccountCookie(response, session.token, session.expires_at);
    setMergedCart(response, session.cart);
    clearOtpCookie(response);
    return response;
}
