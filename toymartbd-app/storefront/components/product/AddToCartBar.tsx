'use client';

import { useEffect, useRef } from 'react';

import { Icon } from '@/components/icons/Icon';
import { buttonClass, buttonIconClass } from '@/components/layout/button-styles';
import { WishlistHeart } from '@/components/wishlist/WishlistHeart';
import type { Locale } from '@/lib/i18n/config';
import { CART_FORM_ID, INLINE_CTA_ID } from '@/lib/product/ids';

interface Props {
    price: string;
    summary: string;
    soldOut: boolean;
    labels: { region: string; addToCart: string; notifyMe: string; wishlist: string };
    /** The BarIcon WishlistHeart (spec §A22): product id, page locale, sign-in return path. */
    wishlist: { productId: number; locale: Locale; back: string };
}

// AddToCartBar — Figma 119:1529 (instance 128:1688), storefront spec §A16. Mobile only (absent at
// ≥1024): 76 tall, fixed directly above MobileNav. Its CTA submits the page's cart form (the same
// control as the inline one, via `form=`), so it works without JavaScript — where it simply stays
// up. Once hydrated it slides away while the inline CTA is on screen, and the hidden one is inert.
// The add itself is the cart form's (AddToCartEnhancer), whichever button submits it.
export function AddToCartBar({ price, summary, soldOut, labels, wishlist }: Props) {
    const bar = useRef<HTMLDivElement>(null);

    useEffect(() => {
        const inline = document.getElementById(INLINE_CTA_ID);
        const el = bar.current;
        let observer: IntersectionObserver | undefined;
        if (inline && el) {
            observer = new IntersectionObserver(([entry]) => {
                // Hidden while the inline CTA is visible, or once it is below the fold (not reached yet is fine: show).
                const visible = entry?.isIntersecting ?? false;
                el.toggleAttribute('data-hidden', visible);
                el.inert = visible;
            });
            observer.observe(inline);
        }
        return () => {
            observer?.disconnect();
        };
    }, []);

    return (
        <div
            ref={bar}
            role="region"
            aria-label={labels.region}
            className="pdp-bar fixed inset-x-0 bottom-[calc(--spacing(16)+env(safe-area-inset-bottom))] z-20 flex h-19 items-center gap-3 border-t border-line-brand-subtle bg-surface px-4 shadow-up-nav lg:hidden"
        >
            <div className="flex max-w-32 min-w-0 flex-col">
                <span className="t-price-current-card text-fg-discount tabular-nums">{price}</span>
                <span className={`t-caption truncate ${soldOut ? 'text-fg-tertiary' : 'text-fg-secondary'}`}>{summary}</span>
            </div>
            <WishlistHeart
                productId={wishlist.productId}
                label={labels.wishlist}
                locale={wishlist.locale}
                back={wishlist.back}
                className="shrink-0"
                buttonClassName="flex size-control-md items-center justify-center rounded-full border-(length:--tm-border-width-strong) border-line-brand-subtle bg-surface text-fg-brand"
                iconClassName="size-icon-md"
            />
            {soldOut ? (
                <button type="submit" form={CART_FORM_ID} name="intent" value="notify" className={`${buttonClass('secondary')} min-w-0 grow`}>
                    {labels.notifyMe}
                </button>
            ) : (
                <button type="submit" form={CART_FORM_ID} name="intent" value="add" className={`${buttonClass('primary')} min-w-0 grow`}>
                    <Icon name="shoppingCartFill" className={buttonIconClass.lg} />
                    {labels.addToCart}
                </button>
            )}
        </div>
    );
}
