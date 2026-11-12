import type { Schema } from '@toymart/api-types';

// Client-safe cart and order types. The cart comes from the generated OpenAPI types (rule 27);
// the order object is docs/api/checkout.md "StorefrontOrder", hand-mirrored until the API's
// OpenAPI export carries it (then swap to Schema<'StorefrontOrder'>).

export type Cart = Schema<'Cart'>;
export type CartLine = Cart['items'][number];
export type CartAdjustment = Cart['adjustments'][number];

/** Customer-facing order stage (checkout.md): never the internal status. */
export type OrderStage = 'placed' | 'confirmed' | 'processing' | 'shipped' | 'delivered' | 'cancelled' | 'returned';

interface Place {
    id: number;
    name: string;
    name_bn: string;
}

export interface StorefrontOrder {
    number: string;
    stage: OrderStage;
    placed_at: string;
    payment: { method: string; status: string; next_action: { type: string; url: string } | null };
    contact: { name: string; phone_masked: string; email: string | null };
    shipping: {
        name: string;
        phone_masked: string;
        division: Place | null;
        district: Place | null;
        upazila: Place | null;
        area: string | null;
        address_line: string | null;
        landmark: string | null;
        postcode: string | null;
    };
    items: Array<{
        variant_id: number;
        product_id: number;
        slug: string | null;
        name: string;
        name_bn: string | null;
        variant_name: string | null;
        sku: string;
        image: { url: string; alt: string | null; alt_bn: string | null } | null;
        quantity: number;
        unit_price: string;
        discount: string;
        total: string;
    }>;
    coupon_code: string | null;
    totals: {
        subtotal: string;
        promotion_discount: string;
        coupon_discount: string;
        discount_total: string;
        shipping_charge: string;
        grand_total: string;
        paid_total: string;
        due_total: string;
    };
    timeline: Array<{ stage: OrderStage; at: string }>;
}

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
