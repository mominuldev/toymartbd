import { accountWrite } from '@/lib/account/server';
import { nationalPhone, safeNext } from '@/lib/account/flash';
import { field, readAccountRequest, redirect303, setOtpCookie } from '@/lib/account/route';
import type { OtpIssued } from '@/lib/account/types';

// POST /api/account/otp — "Send code by SMS" and "Resend code" (P4-STO-11). The phone travels in the
// POST body, never a URL; the API texts the code (SMS only, rule 32b) and the phone is kept in a
// short-lived httpOnly cookie for the "Enter the code" step. `intent=resend` re-uses that cookie.

export const dynamic = 'force-dynamic';

export async function POST(request: Request): Promise<Response> {
    const req = await readAccountRequest(request);
    const resend = req.form.get('intent') === 'resend';
    const typed = resend ? (req.otp?.phone ?? '') : field(req.form, 'phone', 32);
    const next = resend ? (req.otp?.next ?? '/account') : safeNext(field(req.form, 'next', 200));
    const phone = nationalPhone(typed);

    if (!phone) {
        return resend
            ? redirect303(req.locale, '/account/login')
            : redirect303(req.locale, '/account/login', { errors: { phone: 'phoneInvalid' }, draft: { phone: typed, next } });
    }

    const result = await accountWrite<OtpIssued>('POST', 'storefront/auth/otp', null, req.locale, { phone });
    if (result.ok) {
        const response = redirect303(req.locale, '/account/verify', resend ? { kind: 'success', message: 'sent' } : undefined);
        setOtpCookie(response, { phone, sentAt: Date.now(), resendIn: result.data.resend_in, next });
        return response;
    }
    if (result.status === 429 && req.otp?.phone === phone) {
        // A code is already on its way to this number: go (back) to the code step with the API's message.
        return redirect303(req.locale, '/account/verify', { message: result.message });
    }
    if (result.status === 422) {
        return redirect303(req.locale, '/account/login', { errors: { phone: result.errors.phone ?? 'phoneInvalid' }, draft: { phone: typed, next } });
    }
    return redirect303(req.locale, '/account/login', { message: result.status === 429 ? result.message : 'failed', draft: { phone: typed, next } });
}
