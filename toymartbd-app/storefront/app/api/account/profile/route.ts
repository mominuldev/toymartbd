import { accountWrite } from '@/lib/account/server';
import { readProfileForm } from '@/lib/account/profile-form';
import { clearAccountCookies, readAccountRequest, redirect303 } from '@/lib/account/route';
import type { Account, AccountConsents } from '@/lib/account/types';

// POST /api/account/profile — name, email, language, date of birth (P4-STO-11) and the birthday-
// message consent (P8). The phone is the identity and is not editable here. Two API calls: the
// profile first (so a bad date stops before the consent changes), then the consent — recorded by
// the API with source `storefront`; an unticked box withdraws a consent, it never deletes one.

export const dynamic = 'force-dynamic';

export async function POST(request: Request): Promise<Response> {
    const req = await readAccountRequest(request);
    if (!req.token) return redirect303(req.locale, '/account/login?next=/account/profile', { message: 'sessionEnded' });

    const form = readProfileForm(req.form);
    if (Object.keys(form.errors).length > 0) return redirect303(req.locale, '/account/profile', { errors: form.errors, draft: form.draft });

    const signedOut = () => {
        const response = redirect303(req.locale, '/account/login?next=/account/profile', { message: 'sessionEnded' });
        clearAccountCookies(response);
        return response;
    };

    const profile = await accountWrite<Account>('PATCH', 'storefront/account', req.token, req.locale, form.body);
    if (!profile.ok) {
        if (profile.status === 401) return signedOut();
        return redirect303(req.locale, '/account/profile', { errors: profile.errors, draft: form.draft, message: profile.status === 422 ? null : 'failed' });
    }

    const consent = await accountWrite<AccountConsents>('PUT', 'storefront/account/consents', req.token, req.locale, { birthday: form.birthday });
    if (!consent.ok) {
        if (consent.status === 401) return signedOut();
        return redirect303(req.locale, '/account/profile', { errors: consent.errors, draft: form.draft, message: 'failed' });
    }

    return redirect303(req.locale, '/account/profile', { kind: 'success', message: 'saved' });
}
