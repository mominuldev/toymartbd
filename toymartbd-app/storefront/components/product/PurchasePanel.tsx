import type { ReactNode } from 'react';

import { Icon } from '@/components/icons/Icon';
import { buttonClass, buttonIconClass } from '@/components/layout/button-styles';
import { AddToCartEnhancer } from '@/components/cart/AddToCartEnhancer';
import { format, localizedPath, type Locale, type Messages } from '@/lib/i18n';
import { CART_FORM_ID, INLINE_CTA_ID } from '@/lib/product/ids';
import type { ProductVariant } from '@/lib/product/variants';
import type { TrackingItem } from '@/lib/tracking/types';
import { WishlistHeart } from '@/components/wishlist/WishlistHeart';
import { QuantityStepper } from './QuantityStepper';

interface Props {
    /** The product (wishlist hearts save products, not variants). */
    productId: number;
    /** Locale-less product path without the variant (`/products/x`): sign-in's `?next=` for the heart. */
    productPath: string;
    variant: ProductVariant;
    name: string;
    t: Messages;
    locale: Locale;
    /** Locale-independent product path with the selected variant (`/products/x?variant=5`): where "Notify me" returns. */
    back: string;
    /** DeliveryEstimator slot (P4-STO-06, agent-owned component). */
    estimator: ReactNode;
    trust: ReactNode;
    /** add_to_cart's item (K-01), quantity taken from the form at submit time. */
    trackingItem: TrackingItem;
}

// Quantity + CTAs + delivery estimator + trust strip, in the two Figma orders:
// 390 (128:742): Quantity row · DeliveryEstimator · Add to cart · Buy now · TrustStrip, 16 apart.
// 1440 (135:1222): [Stepper | Add to cart | Wishlist] · Buy now · DeliveryEstimator · TrustStrip, 20 apart.
// One grid; `order` and explicit placement switch between them, so there is one quantity input.
//
// The form POSTs to /api/cart/items (P4-STO-08): without JavaScript, Add → the cart page and Buy
// now → checkout (303s); with it, AddToCartEnhancer sends Add in place and announces the result.
// Cart lines need no Idempotency-Key (cart.md): a repeated add raises the quantity, as it should.
export function PurchasePanel({ productId, productPath, variant, name, t, locale, back, estimator, trust, trackingItem }: Props) {
    const soldOut = variant.stock_state === 'out_of_stock';

    return (
        <div className="grid grid-cols-1 gap-4 lg:grid-cols-[auto_minmax(0,1fr)_auto] lg:gap-x-3 lg:gap-y-5">
            <form id={CART_FORM_ID} method="post" action="/api/cart/items" className="contents">
                <input type="hidden" name="variant_id" value={variant.id} />
                <input type="hidden" name="locale" value={locale} />
                <input type="hidden" name="back" value={back} />
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
            <WishlistHeart
                productId={productId}
                label={format(t.pdp.wishlist, { name })}
                locale={locale}
                back={productPath}
                className="hidden lg:col-start-3 lg:row-start-1 lg:block"
                buttonClassName="flex size-control-lg items-center justify-center rounded-full border border-line-default bg-surface text-fg-secondary"
                iconClassName="size-icon-lg"
            />
            <div className={`order-2 lg:order-none lg:col-span-3 ${soldOut ? 'lg:row-start-2' : 'lg:row-start-3'}`}>{estimator}</div>
            <div className={`order-5 lg:order-none lg:col-span-3 ${soldOut ? 'lg:row-start-3' : 'lg:row-start-4'}`}>{trust}</div>
            <AddToCartEnhancer
                formId={CART_FORM_ID}
                cartHref={localizedPath(locale, '/cart')}
                trackingItem={trackingItem}
                labels={{ added: t.cart.added, viewCart: t.cart.viewCart, failed: t.cart.notices.addFailed, notices: t.cart.notices }}
            />
        </div>
    );
}
