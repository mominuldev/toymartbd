import { NextResponse, type NextFetchEvent, type NextRequest } from 'next/server';

import { ACCOUNT_FLASH_COOKIE } from './lib/account/cookies';
import { CHECKOUT_FLASH_COOKIE, FLASH_COOKIE, TRACK_COOKIE } from './lib/cart/cookies';
import { contentSecurityPolicy, isNonceRoute, newNonce } from './lib/csp';
import { MAINTENANCE_RETRY_AFTER_SECONDS, maintenanceOn } from './lib/maintenance';
import { splitLocalePath } from './lib/i18n/config';
import { LOCALE_HEADER, LOCALE_REWRITE_HEADER, PATH_HEADER, resolveLocaleRoute } from './lib/locale-route';
import { recordHit, redirectIndex } from './lib/seo/redirect-map';
import { resolveRedirect } from './lib/seo/redirects';

export async function proxy(request: NextRequest, event: NextFetchEvent) {
    // Next 16 runs the proxy again for the rewritten `/en/...` path. The marker header lets that
    // second pass through instead of hitting the `/en` canonicalisation redirect.
    if (request.headers.get(LOCALE_REWRITE_HEADER) === '1') return NextResponse.next();

    const { pathname } = request.nextUrl;

    // Slug history + manual redirects (docs/api/seo.md): old URLs 301 to the current one in both
    // locales, before any page renders. Laravel never lists a live URL, so a page always wins.
    if (request.method === 'GET' || request.method === 'HEAD') {
        const decision = resolveRedirect(await redirectIndex(), pathname, request.nextUrl.search);
        if (decision) {
            if (decision.id !== null) event.waitUntil(recordHit(decision.id));
            if (!decision.location.startsWith('/')) return NextResponse.redirect(new URL(decision.location), decision.status);
            const url = request.nextUrl.clone();
            const [path, query] = decision.location.split('?', 2) as [string, string?];
            url.pathname = path;
            url.search = query ? `?${query}` : '';
            return NextResponse.redirect(url, decision.status);
        }
    }

    const route = resolveLocaleRoute(pathname);

    // Maintenance switch (docs/ops/DEPLOYMENT.md §5): with STOREFRONT_MAINTENANCE=1 every page
    // answers 503 + Retry-After with the maintenance page, so crawlers keep the indexed pages
    // and shoppers never reach a half-working cart or checkout during a destructive migration.
    if (maintenanceOn(process.env) && route.action !== 'redirect') {
        // A rewrite cannot carry a 503 (Next keeps the page's 200), so render the maintenance page
        // with an internal request — the rewrite marker sends it straight through this proxy — and
        // answer its HTML ourselves with the status crawlers need.
        const { locale } = splitLocalePath(pathname);
        const page = await fetch(new URL(`/${locale}/maintenance`, request.nextUrl.origin), {
            headers: { [LOCALE_REWRITE_HEADER]: '1', [LOCALE_HEADER]: locale, [PATH_HEADER]: pathname, accept: 'text/html' },
            cache: 'no-store',
        }).catch(() => null);
        const html = page?.ok ? await page.text() : 'Service temporarily unavailable.';
        return new NextResponse(html, {
            status: 503,
            headers: {
                'Content-Type': page?.ok ? 'text/html; charset=utf-8' : 'text/plain; charset=utf-8',
                'Retry-After': String(MAINTENANCE_RETRY_AFTER_SECONDS),
                'Cache-Control': 'no-store',
                'Content-Security-Policy': contentSecurityPolicy(process.env, null),
            },
        });
    }

    if (route.action === 'redirect') {
        const url = request.nextUrl.clone();
        url.pathname = route.pathname;
        return NextResponse.redirect(url, 308);
    }

    // Always overwrite: these must describe this request, never a client-supplied value.
    const headers = new Headers(request.headers);
    const { locale, path } = splitLocalePath(pathname);
    headers.set(LOCALE_HEADER, locale);
    headers.set(PATH_HEADER, pathname);

    // Script CSP (lib/csp.ts, audit S-11). Per-request pages get a fresh nonce: Next reads it from the
    // REQUEST's CSP header and stamps it on its own scripts. ISR pages get the static policy and no
    // request header, so a client-sent CSP header can never put a nonce into cached HTML.
    const nonce = isNonceRoute(path) ? newNonce() : null;
    const csp = contentSecurityPolicy(process.env, nonce);
    headers.delete('content-security-policy');
    headers.delete('content-security-policy-report-only');
    if (nonce) headers.set('content-security-policy', csp);

    let response: NextResponse;
    if (route.action === 'next') {
        response = NextResponse.next({ request: { headers } });
    } else {
        const url = request.nextUrl.clone();
        url.pathname = route.pathname;
        headers.set(LOCALE_REWRITE_HEADER, '1');
        response = NextResponse.rewrite(url, { request: { headers } });
    }
    response.headers.set('Content-Security-Policy', csp);
    // One-shot cookies (the cart notice, checkout errors, a tracking lookup): this page render reads
    // them from the request, so the response expires them and a reload does not repeat them.
    // A raw Set-Cookie on purpose: `response.cookies.delete()` would also strip the cookie from THIS
    // request (Next mirrors proxy cookies into the render), so the page would never see it.
    for (const name of [FLASH_COOKIE, CHECKOUT_FLASH_COOKIE, TRACK_COOKIE, ACCOUNT_FLASH_COOKIE]) {
        if (request.cookies.has(name)) response.headers.append('Set-Cookie', `${name}=; Path=/; Max-Age=0; HttpOnly; SameSite=Lax`);
    }
    return response;
}

export const config = {
    // Pages only: not route handlers, Next internals, or files with an extension.
    matcher: ['/((?!api/|_next/|.*\\.[a-zA-Z0-9]+$).*)'],
};
