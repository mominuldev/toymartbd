import type { ReactNode } from 'react';

import { hasDelivery, isZero, itemsLabel, minusPrice, signedPrice } from '@/lib/cart/format';
import type { Cart } from '@/lib/cart/types';
import { format, type Locale, type Messages } from '@/lib/i18n';
import { formatPrice } from '@/lib/listing/format';

interface Props {
    cart: Cart;
    locale: Locale;
    t: Messages;
    /** The CTA under the total (cart: Proceed to checkout; checkout: Place order). */
    cta?: ReactNode;
    /** Live region id for the total, so a JS change of destination announces the new figure. */
    totalId?: string;
    className?: string;
}

const row = 't-body flex min-h-7.5 items-center justify-between gap-3';

// CartSummary — Figma 120:3033: "Order summary" card (surface, hairline border, 24 padding). Rows
// are the API's breakdown in pipeline order — subtotal, each promotion, the coupon, delivery — then
// a divider, the total (price-lg) and "Pay cash on delivery". Nothing is added here (rule 1):
// promotion and coupon rows are the API's `adjustments`, delivery its `shipping_charge`.
export function CartSummary({ cart, locale, t, cta, totalId, className = '' }: Props) {
    const c = t.cart;
    const bn = locale === 'bn';
    const delivery = cart.delivery;
    const shipping = cart.totals.shipping_charge;
    const free = shipping !== null && isZero(shipping);

    return (
        <section aria-labelledby="cart-summary-title" className={`flex flex-col gap-1 rounded-sm border border-line-default bg-surface p-6 ${className}`}>
            <h2 id="cart-summary-title" className="t-h3 mb-2 text-fg-primary">
                {c.summary}
            </h2>
            <dl className="flex flex-col">
                <div className={row}>
                    <dt className="text-fg-secondary">{format(c.subtotal, { items: itemsLabel(cart.item_count, c) })}</dt>
                    <dd className="tabular-nums text-fg-primary">{formatPrice(cart.totals.subtotal)}</dd>
                </div>
                {cart.adjustments
                    .filter((a) => a.type === 'promotion')
                    .map((a) => (
                        <div key={`p${a.id ?? ''}`} className={row}>
                            {/* "Promotion: Eid sale" — the API's name (label_bn in Bangla). A 0.00 row is a
                                free-delivery promotion (P7): its saving is the delivery line's "Free". */}
                            <dt className="text-fg-secondary">{format(c.promotionRow, { label: (bn ? (a.label_bn ?? a.label) : a.label) ?? c.discount })}</dt>
                            <dd className={`tabular-nums ${isZero(a.amount.replace(/^-/, '')) ? 'text-status-in-stock' : 'text-fg-discount'}`}>{isZero(a.amount.replace(/^-/, '')) ? c.free : signedPrice(a.amount)}</dd>
                        </div>
                    ))}
                {cart.coupon?.applied && !isZero(cart.totals.coupon_discount) ? (
                    <div className={row}>
                        <dt className="text-fg-secondary">{format(c.coupon, { code: cart.coupon.code })}</dt>
                        <dd className="tabular-nums text-fg-discount">{minusPrice(cart.totals.coupon_discount)}</dd>
                    </div>
                ) : null}
                <div className={row}>
                    <dt className="text-fg-secondary">{c.delivery}</dt>
                    <dd className={`tabular-nums ${free ? 'text-status-in-stock' : hasDelivery(cart) ? 'text-fg-primary' : 'text-fg-tertiary'}`}>
                        {shipping === null ? c.selectDistrict : free ? c.free : formatPrice(shipping)}
                    </dd>
                </div>
            </dl>
            {free ? (
                <p className="t-caption-strong py-2 text-status-in-stock">{c.freeGot}</p>
            ) : delivery?.free_shipping.remaining && !isZero(delivery.free_shipping.remaining) ? (
                <p className="t-caption-strong py-2 text-fg-secondary">{format(c.freeNudge, { amount: formatPrice(delivery.free_shipping.remaining) })}</p>
            ) : null}
            <div className="mt-1 flex items-center justify-between gap-3 border-t border-line-default pt-3">
                <span className="t-h3 text-fg-primary">{c.total}</span>
                <output id={totalId} aria-live="polite" className="t-price-lg tabular-nums text-fg-price">
                    {formatPrice(cart.totals.grand_total)}
                </output>
            </div>
            <p className="t-caption text-fg-tertiary">{c.payCod}</p>
            {cta ? <div className="mt-4">{cta}</div> : null}
        </section>
    );
}
