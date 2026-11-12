import { format, type Locale, type Messages } from '../i18n';
import { formatPrice } from '../listing/format';
import type { Cart, CartLine, CartNotice } from './types';

// Display only (rule 1): every figure on the cart, checkout and order pages is the API's string;
// these helpers pick the locale's words around it and never add, subtract or round.

/** Product name in the page's locale; Bangla falls back to the English name marked `lang="en"`. */
export function lineName(line: Pick<CartLine, 'product'>, locale: Locale): { text: string; lang?: 'en' } {
    if (locale === 'bn') return line.product.name_bn ? { text: line.product.name_bn } : { text: line.product.name, lang: 'en' };
    return { text: line.product.name };
}

/** The variant's own name, else its attribute values ("Large · Red"), in the page's language. */
export function variantName(line: Pick<CartLine, 'variant'>, locale: Locale): string | null {
    const name = (locale === 'bn' ? (line.variant.name_bn ?? line.variant.name) : line.variant.name) ?? null;
    if (name) return name;
    const values = (line.variant.attributes ?? []).map((a) => (locale === 'bn' ? (a.value_bn ?? a.value) : a.value));
    return values.length > 0 ? values.join(' · ') : null;
}

/** "3 items" / "1 item" / "3টি পণ্য". */
export function itemsLabel(count: number, t: Messages['cart']): string {
    return count === 1 ? t.itemsOne : format(t.items, { count: String(count) });
}

/**
 * A flash / JSON notice as words: catalog keys (`gone`, `failed`, …) are translated; anything else
 * is the API's own localised sentence ("Only 3 left in stock."), shown verbatim.
 */
export function noticeText(notice: CartNotice, notices: Record<string, string>): string {
    return notices[notice.message] ?? notice.message;
}

/** The notice for one target (a line id, `coupon`, `add`, `cart`), if the flash is about it. */
export function noticeFor(notice: CartNotice | null, target: string): CartNotice | null {
    return notice && notice.target === target ? notice : null;
}

/** Whether a line blocks checkout, and the words for why (the StockAlert slot). */
export function lineProblem(line: CartLine, t: Messages['cart']): string | null {
    if (!line.sellable) return t.unavailable;
    if (!line.in_stock) return format(t.notInStock, { count: String(line.available_quantity) });
    return null;
}

/**
 * "Only 2 left" when the line is at or over the API's `available_quantity` (the stepper's ceiling),
 * "Out of stock" at zero. No threshold of our own: the cart carries no stock state beyond these.
 */
export function lowStockLabel(line: CartLine, t: Messages['cart']): string | null {
    if (!line.sellable) return null;
    if (line.available_quantity <= 0) return t.outOfStock;
    return line.quantity >= line.available_quantity ? format(t.onlyLeft, { count: String(line.available_quantity) }) : null;
}

/** The cart has a delivery figure (a serviced destination was given). */
export function hasDelivery(cart: Pick<Cart, 'totals'>): boolean {
    return cart.totals.shipping_charge !== null;
}

/** Zero as the API writes it ("0.00", "0"): a display test, not arithmetic. */
export function isZero(amount: string | null | undefined): boolean {
    return typeof amount === 'string' && /^0+(?:\.0+)?$/.test(amount.trim());
}

/** An adjustment amount as the API signs it: "-200.00" → "−৳200" (a typographic minus), "130.00" → "৳130". */
export function signedPrice(amount: string): string {
    const trimmed = amount.trim();
    return trimmed.startsWith('-') ? `−${formatPrice(trimmed.slice(1))}` : formatPrice(trimmed);
}

/** A discount the API gives unsigned ("425.00") shown as money off: "−৳425". */
export function minusPrice(amount: string): string {
    return `−${formatPrice(amount.trim().replace(/^-/, ''))}`;
}
