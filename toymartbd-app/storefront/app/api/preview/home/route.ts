import { draftMode } from 'next/headers';
import { NextResponse } from 'next/server';

import { fetchHomePreview } from '@/lib/content/home';
import { HOME_PREVIEW_COOKIE, previewTokenLifetime } from '@/lib/content/preview';
import { isLocale, localizedPath } from '@/lib/i18n';

// GET /api/preview/home?token=…&locale=en|bn — opens the homepage builder's live preview (Phase 7).
// The admin's iframe points here. The token is checked server-side before anything is enabled:
// shape and expiry here, then the signature by the API itself (GET /storefront/content/home/preview
// with the token). Only then is Next.js draft mode turned on and the token kept in an httpOnly
// cookie for the page render, which re-sends it to the API on every draft read. No API
// credential ever reaches the browser (rule 28) — the token only reads this one draft.

export const dynamic = 'force-dynamic';

function refuse(): Response {
    return Response.json(
        { type: 'https://toymartbd.com/problems/forbidden', title: 'Forbidden', status: 403, detail: 'The preview link is invalid or has expired. Reopen the preview from the admin.' },
        { status: 403, headers: { 'Content-Type': 'application/problem+json', 'Cache-Control': 'no-store' } },
    );
}

export async function GET(request: Request): Promise<Response> {
    const url = new URL(request.url);
    const token = url.searchParams.get('token');
    const localeParam = url.searchParams.get('locale') ?? 'en';
    const locale = isLocale(localeParam) ? localeParam : 'en';

    const lifetime = previewTokenLifetime(token, Math.floor(Date.now() / 1000));
    if (!token || lifetime === null) return refuse();
    if ((await fetchHomePreview(locale, token)) === null) return refuse();

    (await draftMode()).enable();

    const response = NextResponse.redirect(new URL(localizedPath(locale, '/'), url), 307);
    // The builder shows the storefront in an iframe on the admin's origin: in production that is
    // a cross-site frame, where only SameSite=None cookies are sent.
    const production = process.env.NODE_ENV === 'production';
    response.cookies.set(HOME_PREVIEW_COOKIE, token, {
        httpOnly: true,
        secure: production,
        sameSite: production ? 'none' : 'lax',
        path: '/',
        maxAge: lifetime,
    });
    response.headers.set('Cache-Control', 'no-store');
    return response;
}
