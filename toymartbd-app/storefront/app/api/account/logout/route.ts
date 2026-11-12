import { accountWrite } from '@/lib/account/server';
import { clearAccountCookies, readAccountRequest, redirect303 } from '@/lib/account/route';

// POST /api/account/logout — revoke the token at the API, then forget it (and this device's cart).

export const dynamic = 'force-dynamic';

export async function POST(request: Request): Promise<Response> {
    const req = await readAccountRequest(request);
    if (req.token) {
        // Best effort: the cookie is cleared whatever the API answers.
        await accountWrite<null>('POST', 'storefront/account/logout', req.token, req.locale).catch(() => null);
    }
    const response = redirect303(req.locale, '/', { kind: 'success', message: 'signedOut' });
    clearAccountCookies(response);
    return response;
}
