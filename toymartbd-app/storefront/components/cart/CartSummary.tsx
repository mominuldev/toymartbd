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
    /** Classes for the CTA's wrapper (checkout hides it below lg, where the Place order bar is pinned). */
    ctaClassName?: string;
    /**
     * `card` (cart, and checkout at 1440) or `checkout`: below lg the checkout shows the bare Totals
     * block of Figma 131:2983 — no title, no COD caption, semibold values, the total in h3.
     */
    variant?: 'card' | 'checkout';
}

const row = 't-body flex min-h-7.5 items-center justify-between gap-3';

// CartSummary — Figma 120:3033: "Order summary" card (surface, hairline border, 24 padding). Rows
// are the API's breakdown in pipeline order — subtotal, each promotion, the coupon, delivery — then
// a divider, the total (price-lg) and "Pay cash on delivery". Nothing is added here (rule 1):
// promotion and coupon rows are the API's `adjustments`, delivery its `shipping_charge`.
export function CartSummary({ cart, locale, t, cta, totalId, className = '', ctaClassName = '', variant = 'card' }: Props) {
    const c = t.cart;
    const bn = locale === 'bn';
    const delivery = cart.delivery;
    const shipping = cart.totals.shipping_charge;
    const free = shipping !== null && isZero(shipping);
    const bare = variant === 'checkout';
    // Below lg the checkout's values are semibold (Figma 131:2983); the cart card keeps them regular.
    const value = bare ? 'tabular-nums max-lg:font-semibold' : 'tabular-nums';

    return (
        <section aria-labelledby="cart-summary-title" className={`flex flex-col gap-1 rounded-sm border border-line-default bg-surface p-6 ${className}`}>
            {/* Figma 120:3033 sets the 440 rail in the mobile ramp at 1440 too: h3 18 (= t-h4 there), price-lg 24. */}
            <h2 id="cart-summary-title" className={`t-h3 text-fg-primary lg:t-h4 ${bare ? 'max-lg:sr-only' : ''}`}>
                {c.summary}
            </h2>
            <dl className="flex flex-col gap-1">
                <div className={row}>
                    <dt className="text-fg-secondary">{format(c.subtotal, { items: itemsLabel(cart.item_count, c) })}</dt>
                    <dd className={`${value} text-fg-primary`}>{formatPrice(cart.totals.subtotal)}</dd>
                </div>
                {cart.adjustments
                    .filter((a) => a.type === 'promotion')
                    .map((a) => (
                        <div key={`p${a.id ?? ''}`} className={row}>
                            {/* "Promotion: Eid sale" — the API's name (label_bn in Bangla). A 0.00 row is a
                                free-delivery promotion (P7): its saving is the delivery line's "Free". */}
                            <dt className="text-fg-secondary">{format(c.promotionRow, { label: (bn ? (a.label_bn ?? a.label) : a.label) ?? c.discount })}</dt>
                            <dd className={`${value} ${isZero(a.amount.replace(/^-/, '')) ? 'text-status-in-stock' : 'text-fg-discount'}`}>{isZero(a.amount.replace(/^-/, '')) ? c.free : signedPrice(a.amount)}</dd>
                        </div>
                    ))}
                {cart.coupon?.applied && !isZero(cart.totals.coupon_discount) ? (
                    <div className={row}>
                        <dt className="text-fg-secondary">{format(c.coupon, { code: cart.coupon.code })}</dt>
                        <dd className={`${value} text-fg-discount`}>{minusPrice(cart.totals.coupon_discount)}</dd>
                    </div>
                ) : null}
                <div className={row}>
                    <dt className="text-fg-secondary">{c.delivery}</dt>
                    <dd className={`${value} ${free ? 'text-status-in-stock' : hasDelivery(cart) ? 'text-fg-primary' : 'text-fg-tertiary'}`}>
                        {shipping === null ? c.selectDistrict : free ? c.free : formatPrice(shipping)}
                    </dd>
                </div>
            </dl>
            {free ? (
                <p className="t-caption-strong py-2 text-status-in-stock">{c.freeGot}</p>
            ) : delivery?.free_shipping.remaining && !isZero(delivery.free_shipping.remaining) ? (
                <p className="t-caption-strong py-2 text-fg-secondary">{format(c.freeNudge, { amount: formatPrice(delivery.free_shipping.remaining) })}</p>
            ) : null}
            <div className="flex items-center justify-between gap-3 border-t border-line-default pt-3">
                <span className="t-h3 text-fg-primary lg:t-h4">{c.total}</span>
                <output id={totalId} aria-live="polite" className={`tabular-nums text-fg-price ${bare ? 'max-lg:t-h3 lg:t-price-current-pdp' : 't-price-lg lg:t-price-current-pdp'}`}>
                    {formatPrice(cart.totals.grand_total)}
                </output>
            </div>
            <p className={`t-caption text-fg-tertiary ${bare ? 'max-lg:hidden' : ''}`}>{c.payCod}</p>
            {cta ? <div className={`mt-3 ${ctaClassName}`}>{cta}</div> : null}
        </section>
    );
}
