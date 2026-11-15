import type { QuoteInput, TenderMethod } from './api';
import { isMoneyInput } from './format';

/*
 * The register's local cart: only what the cashier enters — lines, phone, coupon, tenders.
 * No price, total or change lives here (rule 1): those come back from the quote.
 */

export type TenderDraft = { method: TenderMethod; amount: string; reference: string };

export type CartState = {
    lines: { variantId: number; quantity: number }[];
    phone: string;
    name: string;
    coupon: string;
    tenders: TenderDraft[];
};

export const emptyCart = (): CartState => ({ lines: [], phone: '', name: '', coupon: '', tenders: [] });

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
        tenders: cart.tenders
            .filter((d) => isMoneyInput(d.amount))
            .map((d) => ({ method: d.method, amount: d.amount.trim(), reference: d.reference.trim() === '' ? null : d.reference.trim() })),
    };
}
