import { NextResponse, type NextRequest } from 'next/server';

import { LOCALE_REWRITE_HEADER, resolveLocaleRoute } from './lib/locale-route';

export function proxy(request: NextRequest) {
    // Next 16 runs the proxy again for the rewritten `/en/...` path. The marker header lets that
    // second pass through instead of hitting the `/en` canonicalisation redirect.
    if (request.headers.get(LOCALE_REWRITE_HEADER) === '1') return NextResponse.next();

    const route = resolveLocaleRoute(request.nextUrl.pathname);
    if (route.action === 'next') return NextResponse.next();

    const url = request.nextUrl.clone();
    url.pathname = route.pathname;
    if (route.action === 'redirect') return NextResponse.redirect(url, 308);

    const headers = new Headers(request.headers);
    headers.set(LOCALE_REWRITE_HEADER, '1');
    return NextResponse.rewrite(url, { request: { headers } });
}

export const config = {
    // Pages only: not route handlers, Next internals, or files with an extension.
    matcher: ['/((?!api/|_next/|.*\\.[a-zA-Z0-9]+$).*)'],
};
