import type { Schema } from '@toymart/api-types';

// Client-safe cart and order types, generated from the API's OpenAPI document (rule 27):
// `Cart` is CartResource, `StorefrontOrder` is StorefrontOrderResource (docs/api/checkout.md).

export type Cart = Schema<'Cart'>;
export type CartLine = Cart['items'][number];
export type CartAdjustment = Cart['adjustments'][number];

export type StorefrontOrder = Schema<'StorefrontOrder'>;

/** Customer-facing order stage (checkout.md): never the internal status. */
export type OrderStage = StorefrontOrder['stage'];

/** A message the cart page shows once after a no-JS form post (the flash cookie). */
export interface CartNotice {
    kind: 'error' | 'success';
    /** The line it belongs to, or 'coupon' / 'add' / 'cart'. */
    target: string;
    /** The API's localised message, or a catalog key resolved by the page. */
    message: string;
}

/** What a JS-enhanced cart form gets back instead of a redirect. */
export interface CartActionResponse {
    ok: boolean;
    itemCount: number | null;
    notice: CartNotice | null;
}
