import { NextResponse } from 'next/server';

import { TRACK_COOKIE } from '@/lib/cart/cookies';
import { readCartRequest, redirectTo } from '@/lib/cart/route';

// POST /api/track — the public tracking form (P4-STO-10, rule 22). The order number and phone digits
// travel in the POST body, never in a URL (so not in logs or history): they are handed to the
// /track page in a one-shot httpOnly cookie, and the page asks the API. Nothing is looked up here.

export const dynamic = 'force-dynamic';

export async function POST(request: Request): Promise<Response> {
    const req = await readCartRequest(request);
    const number = String(req.form.get('number') ?? '').trim().slice(0, 32);
    const phone = String(req.form.get('phone') ?? '').trim().slice(0, 20);
    const response: NextResponse = redirectTo(request, req.locale, '/track');
    const value = Buffer.from(JSON.stringify({ number, phone }), 'utf8').toString('base64url');
    response.cookies.set(TRACK_COOKIE, value, {
        httpOnly: true,
        sameSite: 'lax',
        secure: process.env.NODE_ENV === 'production' && !(process.env.SITE_URL ?? '').startsWith('http://'),
        path: '/',
        maxAge: 120,
    });
    return response;
}
