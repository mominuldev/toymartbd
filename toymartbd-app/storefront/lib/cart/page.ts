import 'server-only';

import { cookies } from 'next/headers';

import type { Locale } from '../i18n/config';
import { getProduct } from '../product/fetch';
import { CART_COOKIE, DEST_COOKIE, FLASH_COOKIE, decodeNotice, getCart, parseDestination, validToken, type Destination } from './server';
import type { Cart, CartNotice } from './types';

// What the cart and checkout pages read per request: the cart (by the httpOnly token), the chosen
// destination, the one-shot notice of a no-JS post, and a photo per line. These pages are dynamic
// (they read cookies) and never cached.

export interface LineImage {
    url: string;
    alt: string | null;
}

export interface CartPageState {
    cart: Cart | null;
    dest: Destination | null;
    notice: CartNotice | null;
    /** By product slug. The Cart resource carries no image (API gap), so it comes from the cached product. */
    images: Record<string, LineImage | null>;
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

    const images: Record<string, LineImage | null> = {};
    if (cart) {
        const slugs = [...new Set(cart.items.map((line) => line.product.slug))];
        const products = await Promise.all(slugs.map((slug) => getProduct(slug, locale).catch(() => null)));
        slugs.forEach((slug, i) => {
            const image = products[i]?.images[0];
            images[slug] = image ? { url: image.thumb_url || image.url, alt: (locale === 'bn' ? (image.alt_bn ?? image.alt) : image.alt) ?? null } : null;
        });
    }

    return { cart, dest, notice, images, failed };
}
