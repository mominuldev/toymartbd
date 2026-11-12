// Cookie names and lifetimes (client- and proxy-safe: names only, no values or secrets).

export const CART_COOKIE = 'tm_cart';
/** Item count for the header badge on cached pages. NOT a credential: readable by client JS. */
export const CART_COUNT_COOKIE = 'tm_cart_count';
/** The cart token of a just-placed order: proves ownership for the confirmation page (checkout.md). */
export const ORDER_COOKIE = 'tm_order';
/** The delivery destination the shopper chose (`districtId:upazilaId`), so totals include delivery. */
export const DEST_COOKIE = 'tm_dest';
/** One-shot notice after a no-JS form post; proxy.ts deletes it once a page has read it. */
export const FLASH_COOKIE = 'tm_flash';

/** The API's cart expiry: 30 days after the last change. */
export const CART_MAX_AGE = 60 * 60 * 24 * 30;
export const ORDER_MAX_AGE = 60 * 60 * 24 * 7;

/** The checkout form as typed, kept only across a no-JS round trip (httpOnly, 30 minutes, cleared on order). */
export const CHECKOUT_DRAFT_COOKIE = 'tm_checkout';
/** One-shot checkout field errors / notice; proxy.ts expires it like FLASH_COOKIE. */
export const CHECKOUT_FLASH_COOKIE = 'tm_checkout_flash';
/** Public tracking lookup (order number + phone digits) between the form POST and the result page. */
export const TRACK_COOKIE = 'tm_track';
export const CHECKOUT_DRAFT_MAX_AGE = 60 * 30;
