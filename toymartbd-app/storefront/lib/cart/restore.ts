import 'server-only';

import { ApiError, send } from '../api';
import type { Locale } from '../i18n/config';
import type { Cart } from './types';

// Abandoned-cart recovery link (P7, docs/api/cart-recovery.md). The SMS carries a one-purpose
// restore token — never the cart token and no PII. The Next server exchanges it for the cart
// (POST /storefront/cart/restore) and keeps the cart token in its httpOnly cookie (rule 28).

/** Shape of a recovery token: what the API mints (24 letters/digits); anything else is not sent. */
export const RESTORE_TOKEN = /^[A-Za-z0-9]{16,64}$/;

/** The restored cart, or null for an unknown / expired / already-ordered link. Never logs the token. */
export async function restoreCart(token: string, locale: Locale): Promise<Cart | null> {
    if (!RESTORE_TOKEN.test(token)) return null;
    try {
        const { data } = await send<Cart | { data: Cart }>('POST', 'storefront/cart/restore', { locale, body: { token } });
        return 'data' in data ? data.data : data;
    } catch (error) {
        if (error instanceof ApiError && (error.isNotFound || error.status === 422)) return null;
        throw error;
    }
}
