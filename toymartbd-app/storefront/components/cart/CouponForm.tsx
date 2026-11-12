import { Icon } from '@/components/icons/Icon';
import { buttonClass } from '@/components/layout/button-styles';
import { minusPrice, noticeText } from '@/lib/cart/format';
import type { Cart, CartNotice } from '@/lib/cart/types';
import { format, type Locale, type Messages } from '@/lib/i18n';
import { CartForm } from './CartForm';

interface Props {
    coupon: Cart['coupon'];
    couponDiscount: string;
    locale: Locale;
    t: Messages;
    /** Flash notice about the coupon from a no-JS post (the API's rejection reason). */
    notice: CartNotice | null;
    /** Where a no-JS post lands afterwards. */
    next?: 'cart' | 'checkout';
}

// CouponInput — Figma 120:2862. Collapsed: ticket glyph + "Have a coupon?" + caret (a native
// <details>, so it opens without JavaScript). Applied: the success-subtle strip "SAVE10 applied ·
// −৳425 · ×". Not applied: the code stays on the cart with the API's localised reason (cart.md),
// and the field is open to replace it. Every action is a POST to /api/cart/coupon.
export function CouponForm({ coupon, couponDiscount, locale, t, notice, next = 'cart' }: Props) {
    const c = t.cart;
    const flash = notice ? noticeText(notice, c.notices) : null;
    const hidden = (
        <>
            <input type="hidden" name="locale" value={locale} />
            <input type="hidden" name="next" value={next} />
        </>
    );

    if (coupon?.applied) {
        return (
            <CartForm action="/api/cart/coupon" notices={c.notices} initialError={flash} errorClassName="t-caption text-feedback-danger" className="flex flex-col gap-2">
                {hidden}
                <div className="flex h-12 items-center gap-2 rounded-xs bg-success-subtle ps-3 pe-1">
                    <Icon name="checkCircle" className="size-4.5 text-status-in-stock" />
                    <p className="t-body-sm-strong min-w-0 grow text-fg-primary">{format(c.couponApplied, { code: coupon.code })}</p>
                    {!/^0+(\.0+)?$/.test(couponDiscount) ? <p className="t-caption-strong tabular-nums text-fg-discount">{minusPrice(couponDiscount)}</p> : null}
                    <button
                        type="submit"
                        name="intent"
                        value="remove"
                        aria-label={c.couponRemove}
                        className="relative flex size-9 items-center justify-center rounded-full text-fg-primary after:absolute after:-inset-1 after:content-[''] hover:bg-fg-primary/8"
                    >
                        <Icon name="x" className="size-icon-sm" />
                    </button>
                </div>
            </CartForm>
        );
    }

    const rejected = coupon && !coupon.applied ? coupon : null;
    return (
        <details open={Boolean(rejected || flash)} className="group flex flex-col">
            <summary className="t-body-sm-strong flex h-11 w-fit cursor-pointer list-none items-center gap-2 text-fg-brand [&::-webkit-details-marker]:hidden">
                <Icon name="ticket" className="size-icon-md" />
                {c.couponToggle}
                <Icon name="caretDown" className="size-icon-sm transition-transform group-open:rotate-180" />
            </summary>
            <CartForm action="/api/cart/coupon" notices={c.notices} initialError={flash} errorClassName="t-caption text-feedback-danger" className="flex flex-col gap-2 pt-1">
                {hidden}
                <div className="flex items-center gap-2">
                    <label className="min-w-0 grow">
                        <span className="sr-only">{c.couponLabel}</span>
                        <input
                            name="code"
                            type="text"
                            autoComplete="off"
                            autoCapitalize="characters"
                            spellCheck={false}
                            maxLength={64}
                            defaultValue={rejected?.code ?? ''}
                            placeholder={c.couponPlaceholder}
                            aria-invalid={rejected || flash ? true : undefined}
                            className={`t-body h-11 w-full rounded-full border bg-surface px-4 text-fg-primary uppercase placeholder:text-fg-tertiary placeholder:normal-case ${rejected || flash ? 'border-line-danger' : 'border-line-strong'}`}
                        />
                    </label>
                    <button type="submit" name="intent" value="apply" className={`${buttonClass('secondary', 'md')} min-w-0`}>
                        {c.couponApply}
                    </button>
                </div>
                {rejected && !flash ? (
                    <p className="t-caption text-feedback-danger">
                        {format(c.couponNotApplied, { code: rejected.code })}
                        {rejected.message ? ` — ${rejected.message}` : ''}
                    </p>
                ) : null}
            </CartForm>
        </details>
    );
}
