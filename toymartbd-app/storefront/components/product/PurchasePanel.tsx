import type { ReactNode } from 'react';

import { Icon } from '@/components/icons/Icon';
import { buttonClass, buttonIconClass } from '@/components/layout/button-styles';
import { format, type Messages } from '@/lib/i18n';
import { CART_FORM_ID, INLINE_CTA_ID } from '@/lib/product/ids';
import type { ProductVariant } from '@/lib/product/variants';
import { QuantityStepper } from './QuantityStepper';

interface Props {
    variant: ProductVariant;
    name: string;
    t: Messages;
    /** Locale-prefixed product URL with the selected variant (`/products/x?variant=5`). */
    action: string;
    /** DeliveryEstimator slot (P4-STO-06, agent-owned component). */
    estimator: ReactNode;
    trust: ReactNode;
}

// Quantity + CTAs + delivery estimator + trust strip, in the two Figma orders:
// 390 (128:742): Quantity row · DeliveryEstimator · Add to cart · Buy now · TrustStrip, 16 apart.
// 1440 (135:1222): [Stepper | Add to cart | Wishlist] · Buy now · DeliveryEstimator · TrustStrip, 20 apart.
// One grid; `order` and explicit placement switch between them, so there is one quantity input.
//
// TODO(P4-CRT-01): the cart does not exist yet. The form is a deliberate no-op — it re-requests this
// same page (GET, nothing is stored, no "added" message is faked) and, with JavaScript, PurchaseEnhancer
// cancels the submit. Wire it to the cart endpoint (Idempotency-Key not needed for cart lines) then.
export function PurchasePanel({ variant, name, t, action, estimator, trust }: Props) {
    const soldOut = variant.stock_state === 'out_of_stock';

    return (
        <div className="grid grid-cols-1 gap-4 lg:grid-cols-[auto_minmax(0,1fr)_auto] lg:gap-x-3 lg:gap-y-5">
            <form id={CART_FORM_ID} method="get" action={action} data-todo="P4-CRT-01" className="contents">
                <input type="hidden" name="variant" value={variant.id} />
                <div className="order-1 flex items-center justify-between gap-3 lg:order-none lg:col-start-1 lg:row-start-1">
                    <span className="t-body-strong text-fg-primary lg:sr-only">{t.pdp.quantity}</span>
                    <QuantityStepper
                        max={variant.available}
                        disabled={soldOut}
                        labels={{
                            quantity: t.pdp.quantity,
                            increase: t.pdp.increase,
                            decrease: t.pdp.decrease,
                            maxReached: format(t.pdp.maxReached, { max: String(variant.available) }),
                        }}
                    />
                </div>
                <div id={INLINE_CTA_ID} className="order-3 lg:order-none lg:col-start-2 lg:row-start-1">
                    {soldOut ? (
                        <button type="submit" name="intent" value="notify" className={`${buttonClass('secondary')} w-full`}>
                            {t.pdp.notifyMe}
                        </button>
                    ) : (
                        <button type="submit" name="intent" value="add" className={`${buttonClass('primary')} w-full`}>
                            <Icon name="shoppingCartFill" className={buttonIconClass.lg} />
                            {t.pdp.addToCart}
                        </button>
                    )}
                </div>
                {soldOut ? null : (
                    <div className="order-4 lg:order-none lg:col-span-3 lg:row-start-2">
                        <button type="submit" name="intent" value="buy_now" className={`${buttonClass('secondary')} w-full`}>
                            {t.pdp.buyNow}
                        </button>
                    </div>
                )}
            </form>
            <button
                type="button"
                disabled
                aria-label={format(t.pdp.wishlist, { name })}
                className="hidden size-control-lg items-center justify-center rounded-full border border-line-default bg-surface text-fg-secondary lg:col-start-3 lg:row-start-1 lg:flex"
            >
                <Icon name="heart" className="size-icon-lg" />
            </button>
            <div className={`order-2 lg:order-none lg:col-span-3 ${soldOut ? 'lg:row-start-2' : 'lg:row-start-3'}`}>{estimator}</div>
            <div className={`order-5 lg:order-none lg:col-span-3 ${soldOut ? 'lg:row-start-3' : 'lg:row-start-4'}`}>{trust}</div>
        </div>
    );
}
