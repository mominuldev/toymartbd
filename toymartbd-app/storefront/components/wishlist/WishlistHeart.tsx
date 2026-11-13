import { Icon } from '@/components/icons/Icon';
import type { Locale } from '@/lib/i18n';

interface Props {
    productId: number;
    /** "Save {name} to wishlist" (spec §A22 a11y); `aria-pressed` carries the saved state. */
    label: string;
    locale: Locale;
    /** Locale-less path of the page the heart is on: where a signed-out shopper returns after sign-in. */
    back: string;
    /** Positions the heart (the form is the positioned / grid item). */
    className?: string;
    /** The circle: size, surface, border, shadow. */
    buttonClassName: string;
    iconClassName: string;
}

// WishlistButton — storefront spec §A22 (CardOverlay on a card photo, Inline beside the PDP CTA,
// BarIcon in the AddToCartBar). A real `<form method="post">` to /api/account/wishlist, so it works
// without JavaScript: the post saves the toy and lands on the wishlist, or — signed out — goes to
// sign-in and back to this page. WishlistHearts (mounted once per page) enhances every heart: it
// reads the signed-in customer's saved ids from a small private endpoint, fills the saved hearts
// (outline `color/text/secondary` → filled `color/bg/brand`), toggles in place and announces it.
// The page itself stays the same cached HTML for everyone; the filled state is client-side only.
export function WishlistHeart({ productId, label, locale, back, className = '', buttonClassName, iconClassName }: Props) {
    return (
        <form method="post" action="/api/account/wishlist" data-wishlist-form="" className={className}>
            <input type="hidden" name="product_id" value={productId} />
            <input type="hidden" name="locale" value={locale} />
            <input type="hidden" name="back" value={back} />
            <button
                type="submit"
                name="intent"
                value="add"
                aria-pressed="false"
                aria-label={label}
                data-wishlist-heart={productId}
                className={`group relative transition-transform duration-(--tm-duration-fast) ease-standard motion-safe:active:scale-90 in-aria-busy:opacity-60 ${buttonClassName}`}
            >
                <Icon name="heart" className={`${iconClassName} group-aria-pressed:hidden`} />
                <Icon name="heartFill" className={`${iconClassName} hidden text-brand group-aria-pressed:block`} />
            </button>
        </form>
    );
}
