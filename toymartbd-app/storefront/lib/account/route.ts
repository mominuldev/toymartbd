import 'server-only';

import { NextResponse } from 'next/server';

import { readCookie } from '../cart/route';
import { CART_COOKIE, CART_COUNT_COOKIE, CART_MAX_AGE, validToken } from '../cart/server';
import { isLocale, localizedPath, type Locale } from '../i18n/config';
import { ACCOUNT_COOKIE, ACCOUNT_FLASH_COOKIE, OTP_COOKIE, OTP_COOKIE_MAX_AGE } from './cookies';
import { decodeOtp, encodeFlash, encodeOtp, type OtpState } from './flash';
import { validAccountToken } from './server';
import type { AccountFlash } from './types';

// Shared plumbing for the account Route Handlers (app/api/account/*). Every account form is a plain
// `<form method="post">`, so sign-in and every account edit work without JavaScript: the handler
// answers 303 to the page to show next, with a one-shot flash cookie (proxy.ts expires it once a page
// has read it). The customer token is set here, httpOnly + Secure + SameSite=Lax, and never leaves
// the server again except as the bearer of an API call (rule 28).

export interface AccountRequest {
    form: FormData;
    locale: Locale;
    /** The customer's token, if signed in. */
    token: string | null;
    /** The guest cart token (merged into the customer's cart on sign-in). */
    cartToken: string | null;
    otp: OtpState | null;
}

export async function readAccountRequest(request: Request): Promise<AccountRequest> {
    const form = await request.formData().catch(() => new FormData());
    const locale = String(form.get('locale') ?? '');
    const cookie = request.headers.get('cookie') ?? '';
    return {
        form,
        locale: isLocale(locale) ? locale : 'en',
        token: validAccountToken(readCookie(cookie, ACCOUNT_COOKIE)),
        cartToken: validToken(readCookie(cookie, CART_COOKIE)),
        otp: decodeOtp(readCookie(cookie, OTP_COOKIE)),
    };
}

export function field(form: FormData, key: string, max = 500): string {
    return String(form.get(key) ?? '').trim().slice(0, max);
}

const secure = () => process.env.NODE_ENV === 'production' && !(process.env.SITE_URL ?? '').startsWith('http://');

/** 303 to a locale page, relative Location (see lib/cart/route.ts redirectTo for why). */
export function redirect303(locale: Locale, path: string, flash?: Partial<AccountFlash>): NextResponse {
    const response = new NextResponse(null, { status: 303, headers: { Location: localizedPath(locale, path) } });
    if (flash) {
        const value: AccountFlash = { kind: flash.kind ?? 'error', message: flash.message ?? null, errors: flash.errors ?? {}, draft: flash.draft ?? {} };
        response.cookies.set(ACCOUNT_FLASH_COOKIE, encodeFlash(value), { httpOnly: true, sameSite: 'lax', secure: secure(), path: '/', maxAge: 60 });
    }
    return response;
}

export function setOtpCookie(response: NextResponse, state: OtpState): void {
    response.cookies.set(OTP_COOKIE, encodeOtp(state), { httpOnly: true, sameSite: 'lax', secure: secure(), path: '/', maxAge: OTP_COOKIE_MAX_AGE });
}

export function clearOtpCookie(response: NextResponse): void {
    response.cookies.delete(OTP_COOKIE);
}

/** Remember the customer: the token lives exactly as long as the API says it is valid. */
export function setAccountCookie(response: NextResponse, token: string, expiresAt: string): void {
    const seconds = Math.floor((Date.parse(expiresAt) - Date.now()) / 1000);
    response.cookies.set(ACCOUNT_COOKIE, token, { httpOnly: true, sameSite: 'lax', secure: secure(), path: '/', maxAge: Number.isFinite(seconds) && seconds > 0 ? seconds : 60 * 60 * 24 });
}

/** Signing out also forgets the cart on this device: on a shared phone the next person must not see it. */
export function clearAccountCookies(response: NextResponse): void {
    response.cookies.delete(ACCOUNT_COOKIE);
    response.cookies.delete(CART_COOKIE);
    response.cookies.delete(CART_COUNT_COOKIE);
}

/** After sign-in: the merged cart replaces the guest cart in the cookies. */
export function setMergedCart(response: NextResponse, cart: { token: string; item_count: number } | null): void {
    if (!cart || !validToken(cart.token)) return;
    response.cookies.set(CART_COOKIE, cart.token, { httpOnly: true, sameSite: 'lax', secure: secure(), path: '/', maxAge: CART_MAX_AGE });
    response.cookies.set(CART_COUNT_COOKIE, String(cart.item_count), { httpOnly: false, sameSite: 'lax', secure: secure(), path: '/', maxAge: CART_MAX_AGE });
}
