// Pure, client-safe event vocabulary shared by the browser runtime (lib/tracking/browser.ts) and the
// Route Handlers (lib/tracking/events.ts — which is server-side because it reads the client IP).

/** The only events a browser may report; `purchase` is server-originated (the API fires it from the order). */
export const BROWSER_EVENTS = ['view_item', 'add_to_cart', 'begin_checkout'] as const;
export type BrowserEventName = (typeof BROWSER_EVENTS)[number];

export const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;

/** GA4 ecommerce item, money as the API's decimal strings (never float — rule 6). */
export interface TrackingItem {
    item_id: string;
    item_name: string;
    price: string;
    quantity: number;
    item_brand?: string;
    item_category?: string;
    item_variant?: string;
}

/** Meta standard event per GA4 name (mirrors TrackingEventName::metaName() in the API). */
export const META_EVENT: Record<BrowserEventName | 'purchase', string> = {
    view_item: 'ViewContent',
    add_to_cart: 'AddToCart',
    begin_checkout: 'InitiateCheckout',
    purchase: 'Purchase',
};
