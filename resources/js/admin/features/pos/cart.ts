import type { MessageKey } from '@/i18n/messages';
import type { QuoteInput, TenderMethod } from './api';
import { isMoneyInput } from './format';

/*
 * The register's local cart: only what the cashier enters — lines, phone, coupon, the discount
 * asked for (a percent or taka and a reason; the server prices it), tenders.
 * No price, total or change lives here (rule 1): those come back from the quote.
 */

export type TenderDraft = { method: TenderMethod; amount: string; reference: string };

/** The fixed discount reasons (server enum DiscountReason), in the order the dropdown lists them. */
export const DISCOUNT_REASONS = ['regular_customer', 'bulk_purchase', 'damaged_packaging', 'price_match', 'staff_purchase', 'promotional', 'other'] as const;
export type DiscountReason = (typeof DISCOUNT_REASONS)[number];

export function isDiscountReason(value: string | null | undefined): value is DiscountReason {
    return (DISCOUNT_REASONS as readonly string[]).includes(value ?? '');
}

/** A reason code's label in the register's language — the totals row and the receipt use it too. */
export function discountReasonKey(reason: DiscountReason): MessageKey {
    return `pos.discount.reason.${reason}`;
}

export type DiscountDraft = { type: 'percent' | 'amount'; value: string; reason: DiscountReason };

export type CartState = {
    lines: { variantId: number; quantity: number }[];
    phone: string;
    name: string;
    coupon: string;
    discount: DiscountDraft | null;
    tenders: TenderDraft[];
};

export const emptyCart = (): CartState => ({ lines: [], phone: '', name: '', coupon: '', discount: null, tenders: [] });

/** Rule 30's pattern — only to decide whether to send the phone yet; the server validates. */
const PHONE = /^(?:\+?880|0)1[3-9]\d{8}$/;

export function cleanPhone(raw: string): string | null {
    const digits = raw.replace(/[\s\-.()]/g, '');
    return PHONE.test(digits) ? digits : null;
}

export function addLine(cart: CartState, variantId: number, by = 1): CartState {
    const existing = cart.lines.find((l) => l.variantId === variantId);
    const lines = existing
        ? cart.lines.map((l) => (l.variantId === variantId ? { ...l, quantity: Math.max(0, l.quantity + by) } : l)).filter((l) => l.quantity > 0)
        : by > 0
          ? [...cart.lines, { variantId, quantity: by }]
          : cart.lines;
    return { ...cart, lines };
}

export function removeLine(cart: CartState, variantId: number): CartState {
    return { ...cart, lines: cart.lines.filter((l) => l.variantId !== variantId) };
}

/** The quote / charge body: tenders with an amount the API accepts, the phone once it is a phone. */
export function quoteBody(cart: CartState): QuoteInput {
    const phone = cleanPhone(cart.phone);
    return {
        lines: cart.lines.map((l) => ({ variant_id: l.variantId, quantity: l.quantity })),
        coupon_code: cart.coupon.trim() === '' ? null : cart.coupon.trim(),
        customer_phone: phone,
        // Sent so the server can apply the Due guard (a named customer) before charging.
        customer_name: phone && cart.name.trim() !== '' ? cart.name.trim() : null,
        discount: cart.discount ?? undefined,
        tenders: cart.tenders
            .filter((d) => isMoneyInput(d.amount))
            .map((d) => ({ method: d.method, amount: d.amount.trim(), reference: d.reference.trim() === '' ? null : d.reference.trim() })),
    };
}
