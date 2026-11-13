import type { Cart, StorefrontOrder } from '../cart/types';
import type { ProductVariant, StorefrontProduct } from '../product/variants';
import type { TrackingItem } from './types';

// API objects → GA4-style items. Names are the English catalogue names in both locales so reports
// do not split one product in two. Prices are the API's own strings (rule 1: nothing computed here).

export const TRACKING_CURRENCY = 'BDT';

/** The PDP item for the selected variant, at the price the page shows (`price`: flash or regular). */
export function productItem(product: Pick<StorefrontProduct, 'name' | 'brand' | 'breadcrumbs'>, variant: Pick<ProductVariant, 'sku' | 'name'>, price: string): TrackingItem {
    const category = product.breadcrumbs[product.breadcrumbs.length - 1]?.name;
    return {
        item_id: variant.sku,
        item_name: product.name,
        price,
        quantity: 1,
        ...(product.brand ? { item_brand: product.brand.name } : {}),
        ...(category ? { item_category: category } : {}),
        ...(variant.name ? { item_variant: variant.name } : {}),
    };
}

export function cartItems(cart: Pick<Cart, 'items'>): TrackingItem[] {
    return cart.items.slice(0, 50).map((line) => ({
        item_id: line.variant.sku,
        item_name: line.product.name,
        price: line.unit_price,
        quantity: line.quantity,
        ...(line.variant.name ? { item_variant: line.variant.name } : {}),
    }));
}

export function orderItems(order: Pick<StorefrontOrder, 'items'>): TrackingItem[] {
    return order.items.slice(0, 50).map((line) => ({
        item_id: line.sku,
        item_name: line.name,
        price: line.unit_price,
        quantity: line.quantity,
        ...(line.variant_name ? { item_variant: line.variant_name } : {}),
    }));
}

/** `tracking` on the order confirmation (StorefrontOrder.tracking, docs/api/tracking.md §2.3). */
export type OrderTracking = NonNullable<StorefrontOrder['tracking']>;

/** Narrowed at runtime as well: a malformed or missing block means "fire nothing". */
export function orderTracking(order: unknown): OrderTracking | null {
    const raw = order && typeof order === 'object' && 'tracking' in order ? (order as { tracking: unknown }).tracking : null;
    if (!raw || typeof raw !== 'object') return null;
    const { purchase_event_id, fire_browser_purchase } = raw as Record<string, unknown>;
    if (typeof purchase_event_id !== 'string' || typeof fire_browser_purchase !== 'boolean') return null;
    return { purchase_event_id, fire_browser_purchase };
}
