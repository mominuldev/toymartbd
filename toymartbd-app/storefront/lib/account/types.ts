import type { Schema } from '@toymart/api-types';

// Client-safe account types, generated from the API's OpenAPI document (rule 27).

export type Account = Schema<'StorefrontAccount'>;
export type AccountSession = Schema<'StorefrontAccountSession'>;
export type OtpIssued = Schema<'StorefrontOtpIssued'>;
export type SavedAddress = Schema<'StorefrontAddress'>;
export type OrderSummary = Schema<'StorefrontOrderSummary'>;
export type WishlistItem = Schema<'StorefrontWishlistItem'>;
export type AccountConsents = Schema<'StorefrontAccountConsents'>;

export const ORDER_GROUPS = ['active', 'delivered', 'cancelled', 'returned'] as const;
export type OrderGroup = (typeof ORDER_GROUPS)[number];

export interface OrderPage {
    data: OrderSummary[];
    meta: { next_cursor: string | null };
    summary: Record<OrderGroup, number>;
}

/** What a form post leaves for the next page render (base64url JSON in ACCOUNT_FLASH_COOKIE). */
export interface AccountFlash {
    kind: 'error' | 'success';
    /** A message key from the account catalog (`notices.*`), or the API's own localised sentence. */
    message: string | null;
    /** Field errors, already localised by the API. */
    errors: Record<string, string>;
    /** What the customer typed, so a no-JS round trip keeps it. */
    draft: Record<string, string>;
}
