import { accountWrite } from '@/lib/account/server';
import { readChildForm } from '@/lib/account/profile-form';
import { clearAccountCookies, readAccountRequest, redirect303 } from '@/lib/account/route';
import type { AccountChild } from '@/lib/account/types';

// POST /api/account/children — "My kids" on the profile page, without JavaScript (P8 batch 3):
//   intent=save    add (no `id`) or edit (`id`) one child: first name/nickname, day + month, optional year
//   intent=delete  remove `id` — the API deletes it (not a soft delete)
// The customer's token is the only identity: the API 404s a child that is not theirs. Its field errors
// (31 February, six children already) come back next to the form, which re-opens with what was typed.

export const dynamic = 'force-dynamic';

const PAGE = '/account/profile';

export async function POST(request: Request): Promise<Response> {
    const req = await readAccountRequest(request);
    if (!req.token) return redirect303(req.locale, `/account/login?next=${PAGE}`, { message: 'sessionEnded' });

    const child = readChildForm(req.form);
    if (Object.keys(child.errors).length > 0) return redirect303(req.locale, `${PAGE}#kids`, { errors: child.errors, draft: child.draft });

    const result =
        child.intent === 'delete' && child.id !== null
            ? await accountWrite<null>('DELETE', `storefront/account/children/${child.id}`, req.token, req.locale)
            : child.id !== null
              ? await accountWrite<AccountChild>('PATCH', `storefront/account/children/${child.id}`, req.token, req.locale, child.body)
              : await accountWrite<AccountChild>('POST', 'storefront/account/children', req.token, req.locale, child.body);

    if (result.ok) return redirect303(req.locale, `${PAGE}#kids`, { kind: 'success', message: child.intent === 'delete' ? 'childRemoved' : 'childSaved' });
    if (result.status === 401) {
        const response = redirect303(req.locale, `/account/login?next=${PAGE}`, { message: 'sessionEnded' });
        clearAccountCookies(response);
        return response;
    }
    if (result.status === 422) return redirect303(req.locale, `${PAGE}#kids`, { errors: result.errors, draft: child.draft });
    return redirect303(req.locale, `${PAGE}#kids`, { message: 'failed', draft: child.draft });
}
