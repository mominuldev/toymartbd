import { accountWrite } from '@/lib/account/server';
import { clearAccountCookies, field, readAccountRequest, redirect303 } from '@/lib/account/route';
import type { Account } from '@/lib/account/types';

// POST /api/account/profile — name, email, language (P4-STO-11). The phone is the identity and is
// not editable here.

export const dynamic = 'force-dynamic';

export async function POST(request: Request): Promise<Response> {
    const req = await readAccountRequest(request);
    if (!req.token) return redirect303(req.locale, '/account/login?next=/account/profile', { message: 'sessionEnded' });

    const draft = { name: field(req.form, 'name', 160), email: field(req.form, 'email', 191), preferred_language: field(req.form, 'preferred_language', 2) };
    const body = { name: draft.name, email: draft.email === '' ? null : draft.email, preferred_language: draft.preferred_language === 'en' ? 'en' : 'bn' };

    const result = await accountWrite<Account>('PATCH', 'storefront/account', req.token, req.locale, body);
    if (result.ok) return redirect303(req.locale, '/account/profile', { kind: 'success', message: 'saved' });
    if (result.status === 401) {
        const response = redirect303(req.locale, '/account/login?next=/account/profile', { message: 'sessionEnded' });
        clearAccountCookies(response);
        return response;
    }
    return redirect303(req.locale, '/account/profile', { errors: result.errors, draft, message: result.status === 422 ? null : 'failed' });
}
