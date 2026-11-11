import { NextResponse, type NextRequest } from 'next/server';

import { splitLocalePath } from './lib/i18n/config';
import { LOCALE_HEADER, LOCALE_REWRITE_HEADER, PATH_HEADER, resolveLocaleRoute } from './lib/locale-route';

export function proxy(request: NextRequest) {
    // Next 16 runs the proxy again for the rewritten `/en/...` path. The marker header lets that
    // second pass through instead of hitting the `/en` canonicalisation redirect.
    if (request.headers.get(LOCALE_REWRITE_HEADER) === '1') return NextResponse.next();

    const { pathname } = request.nextUrl;
    const route = resolveLocaleRoute(pathname);

    if (route.action === 'redirect') {
        const url = request.nextUrl.clone();
        url.pathname = route.pathname;
        return NextResponse.redirect(url, 308);
    }

    // Always overwrite: these must describe this request, never a client-supplied value.
    const headers = new Headers(request.headers);
    headers.set(LOCALE_HEADER, splitLocalePath(pathname).locale);
    headers.set(PATH_HEADER, pathname);

    if (route.action === 'next') return NextResponse.next({ request: { headers } });

    const url = request.nextUrl.clone();
    url.pathname = route.pathname;
    headers.set(LOCALE_REWRITE_HEADER, '1');
    return NextResponse.rewrite(url, { request: { headers } });
}

export const config = {
    // Pages only: not route handlers, Next internals, or files with an extension.
    matcher: ['/((?!api/|_next/|.*\\.[a-zA-Z0-9]+$).*)'],
};
