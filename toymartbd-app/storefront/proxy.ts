import { NextResponse, type NextRequest } from 'next/server';

import { CHECKOUT_FLASH_COOKIE, FLASH_COOKIE, TRACK_COOKIE } from './lib/cart/cookies';
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

    let response: NextResponse;
    if (route.action === 'next') {
        response = NextResponse.next({ request: { headers } });
    } else {
        const url = request.nextUrl.clone();
        url.pathname = route.pathname;
        headers.set(LOCALE_REWRITE_HEADER, '1');
        response = NextResponse.rewrite(url, { request: { headers } });
    }
    // One-shot cookies (the cart notice, checkout errors, a tracking lookup): this page render reads
    // them from the request, so the response expires them and a reload does not repeat them.
    // A raw Set-Cookie on purpose: `response.cookies.delete()` would also strip the cookie from THIS
    // request (Next mirrors proxy cookies into the render), so the page would never see it.
    for (const name of [FLASH_COOKIE, CHECKOUT_FLASH_COOKIE, TRACK_COOKIE]) {
        if (request.cookies.has(name)) response.headers.append('Set-Cookie', `${name}=; Path=/; Max-Age=0; HttpOnly; SameSite=Lax`);
    }
    return response;
}

export const config = {
    // Pages only: not route handlers, Next internals, or files with an extension.
    matcher: ['/((?!api/|_next/|.*\\.[a-zA-Z0-9]+$).*)'],
};
