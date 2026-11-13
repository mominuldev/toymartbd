import { accountWrite } from '@/lib/account/server';
import { readDetailsForm, readPreferencesForm } from '@/lib/account/profile-form';
import { clearAccountCookies, readAccountRequest, redirect303 } from '@/lib/account/route';
import type { Account, AccountConsents } from '@/lib/account/types';

// POST /api/account/profile — the profile page's two no-JS forms (P4-STO-11, P8 batch 3):
//   intent=details      name + email (PATCH /storefront/account). The phone is the identity, not editable.
//   intent=preferences  language (PATCH) and the "Birthday reminders and offers" switch — PUT
//                       /storefront/account/consents, recorded by the API with source `storefront`; an
//                       unticked switch withdraws a consent, it never deletes one.

export const dynamic = 'force-dynamic';

const PAGE = '/account/profile';

export async function POST(request: Request): Promise<Response> {
    const req = await readAccountRequest(request);
    if (!req.token) return redirect303(req.locale, `/account/login?next=${PAGE}`, { message: 'sessionEnded' });

    const signedOut = () => {
        const response = redirect303(req.locale, `/account/login?next=${PAGE}`, { message: 'sessionEnded' });
        clearAccountCookies(response);
        return response;
    };

    if (req.form.get('intent') === 'preferences') {
        const prefs = readPreferencesForm(req.form);
        const language = await accountWrite<Account>('PATCH', 'storefront/account', req.token, req.locale, prefs.body);
        if (!language.ok) {
            if (language.status === 401) return signedOut();
            return redirect303(req.locale, `${PAGE}#notifications`, { errors: language.errors, draft: prefs.draft, message: language.status === 422 ? null : 'failed' });
        }
        const consent = await accountWrite<AccountConsents>('PUT', 'storefront/account/consents', req.token, req.locale, { birthday: prefs.birthday });
        if (!consent.ok) {
            if (consent.status === 401) return signedOut();
            return redirect303(req.locale, `${PAGE}#notifications`, { errors: consent.errors, draft: prefs.draft, message: 'failed' });
        }
        return redirect303(req.locale, `${PAGE}#notifications`, { kind: 'success', message: 'saved' });
    }

    const details = readDetailsForm(req.form);
    const profile = await accountWrite<Account>('PATCH', 'storefront/account', req.token, req.locale, details.body);
    if (!profile.ok) {
        if (profile.status === 401) return signedOut();
        return redirect303(req.locale, PAGE, { errors: profile.errors, draft: details.draft, message: profile.status === 422 ? null : 'failed' });
    }

    return redirect303(req.locale, PAGE, { kind: 'success', message: 'saved' });
}
