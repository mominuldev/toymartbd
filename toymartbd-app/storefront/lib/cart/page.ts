import 'server-only';

import { cookies } from 'next/headers';

import type { Locale } from '../i18n/config';
import { CART_COOKIE, DEST_COOKIE, FLASH_COOKIE, decodeNotice, getCart, parseDestination, validToken, type Destination } from './server';
import type { Cart, CartNotice } from './types';

// What the cart and checkout pages read per request: the cart (by the httpOnly token), the chosen
// destination and the one-shot notice of a no-JS post. Each cart line carries its own photo and
// attribute labels, so nothing is fetched per product. These pages are dynamic (they read cookies)
// and never cached.

export interface CartPageState {
    cart: Cart | null;
    dest: Destination | null;
    notice: CartNotice | null;
    /** The API could not be reached: show an error, never an empty cart. */
    failed: boolean;
}

export async function loadCartPage(locale: Locale): Promise<CartPageState> {
    const jar = await cookies();
    const token = validToken(jar.get(CART_COOKIE)?.value);
    const dest = parseDestination(jar.get(DEST_COOKIE)?.value);
    const notice = decodeNotice(jar.get(FLASH_COOKIE)?.value);

    let cart: Cart | null = null;
    let failed = false;
    try {
        cart = await getCart(token, dest, locale);
    } catch {
        failed = true;
    }

    return { cart, dest, notice, failed };
}
