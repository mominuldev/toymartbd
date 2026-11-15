import { draftMode } from 'next/headers';
import { NextResponse } from 'next/server';

import { fetchPagePreview } from '@/lib/content/page-layout';
import { PAGE_PREVIEW_COOKIE, previewTokenLifetime } from '@/lib/content/preview';
import { isLocale, localizedPath } from '@/lib/i18n';

// GET /api/preview/page?token=…&locale=en|bn — opens a static page's sections draft in the page
// builder's live preview (owner decision 2026-11-16), exactly as /api/preview/home does for the
// homepage. The token (`page_draft.{page id}.{expires}.{hmac}`) is checked server-side before
// anything is enabled: shape, type and expiry here, then the signature by the API itself
// (GET /storefront/content/pages/preview with the token), which also says which page it opens.
// Only then is draft mode turned on, the token kept in an httpOnly cookie for the page render
// (which re-sends it to the API on every draft read), and the browser sent to that page's URL.
// No API credential ever reaches the browser (rule 28) — the token only reads this one draft.

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

    const lifetime = previewTokenLifetime(token, Math.floor(Date.now() / 1000), 'page_draft');
    if (!token || lifetime === null) return refuse();
    const draft = await fetchPagePreview(locale, token);
    if (draft === null) return refuse();

    (await draftMode()).enable();

    // A relative Location (see ../home/route.ts). The page lives at the site root; its slug was
    // checked against the page-slug pattern by the normaliser.
    const response = new NextResponse(null, { status: 307, headers: { Location: localizedPath(locale, `/${draft.page.slug}`) } });
    // The builder frames the storefront from the admin's origin: cross-site in production.
    const production = process.env.NODE_ENV === 'production';
    response.cookies.set(PAGE_PREVIEW_COOKIE, token, {
        httpOnly: true,
        secure: production,
        sameSite: production ? 'none' : 'lax',
        path: '/',
        maxAge: lifetime,
    });
    response.headers.set('Cache-Control', 'no-store');
    return response;
}
