import type { Metadata } from 'next';
import { notFound } from 'next/navigation';

import { CartLineItem } from '@/components/cart/CartLineItem';
import { CartSummary } from '@/components/cart/CartSummary';
import { CouponForm } from '@/components/cart/CouponForm';
import { DestinationForm } from '@/components/cart/DestinationForm';
import { PlainTrust } from '@/components/cart/PlainTrust';
import { Icon } from '@/components/icons/Icon';
import { buttonClass, buttonIconClass } from '@/components/layout/button-styles';
import { PageShell } from '@/components/PageShell';
import { itemsLabel, lineProblem, noticeFor, noticeText } from '@/lib/cart/format';
import { loadCartPage } from '@/lib/cart/page';
import type { Cart } from '@/lib/cart/types';
import { getDeliveryReference } from '@/lib/delivery/fetch';
import { placeName } from '@/lib/delivery/format';
import { format, getMessages, isLocale, localizedPath, type Messages } from '@/lib/i18n';
import { formatPrice } from '@/lib/listing/format';
import { alternatesFor } from '@/lib/seo';

// /cart — P4-STO-08, Figma Cart 130:2302 (390) / 137:3203 (1440), storefront spec §A17–A19, §B5.
// Per visitor (reads the httpOnly cart cookie), so dynamic and noindex. Lines, coupon, the API's
// price breakdown and the checkout CTA; every control is a form POST that works without JS.
// Rule 25b: at most one cross-sell rail here and no upsell — there is no recommendation API yet,
// so the cart carries none.

const PATH = '/cart';

// Per visitor (cookies): never prerendered or cached.
export const dynamic = 'force-dynamic';

interface Props {
    params: Promise<{ locale: string }>;
}

export async function generateMetadata({ params }: Props): Promise<Metadata> {
    const { locale } = await params;
    if (!isLocale(locale)) return {};
    const t = getMessages(locale).cart;
    return { title: t.title, description: t.metaDescription, alternates: alternatesFor(locale, PATH), robots: { index: false, follow: true } };
}

/** Checkout is open when every line can be bought and the coupon (if any) applies; the address comes at checkout. */
function canCheckout(cart: Cart, t: Messages['cart']): boolean {
    return cart.items.length > 0 && cart.items.every((line) => lineProblem(line, t) === null) && (!cart.coupon || cart.coupon.applied);
}

export default async function CartPage({ params }: Props) {
    const { locale } = await params;
    if (!isLocale(locale)) notFound();
    const t = getMessages(locale);
    const c = t.cart;
    const [{ cart, dest, notice, failed }, reference] = await Promise.all([loadCartPage(locale), getDeliveryReference(locale)]);
    const count = cart?.item_count ?? 0;
    const empty = !cart || cart.items.length === 0;
    const general = notice && (notice.target === 'cart' || notice.target === 'add') ? noticeText(notice, c.notices) : null;

    const district = dest ? reference.districts.find((d) => d.id === dest.districtId) : undefined;
    const place = cart?.delivery ? placeName(cart.delivery.upazila ?? cart.delivery.district, locale === 'bn') : district ? placeName(district, locale === 'bn') : null;
    const ready = cart ? canCheckout(cart, c) : false;
    const checkoutHref = localizedPath(locale, '/checkout');

    return (
        <PageShell locale={locale} path={PATH} top="tight" cartCount={count} bottomBar={!empty}>
            <div className="flex flex-col gap-4 pb-8 lg:gap-6 lg:pb-20">
                <header className="-mx-gutter-mobile flex flex-col gap-0.5 bg-subtle px-gutter-mobile pb-4 md:mx-0 md:bg-transparent md:px-0 lg:flex-row lg:items-baseline lg:gap-3 lg:pb-0">
                    <h1 className="t-h1 text-fg-primary">{c.title}</h1>
                    {!empty ? (
                        <p className="t-body text-fg-secondary">
                            {itemsLabel(count, c)}
                            {place ? ` · ${format(c.deliveryTo, { place })}` : ''}
                        </p>
                    ) : null}
                </header>

                {general ? (
                    <p role="alert" className="t-body-sm-strong flex items-center gap-2 rounded-xs bg-warning-subtle px-3 py-2 text-status-low-stock">
                        <Icon name="warningCircle" className="size-icon-md shrink-0" />
                        {general}
                    </p>
                ) : null}

                {failed ? (
                    <p role="alert" className="t-body rounded-sm bg-surface p-6 text-fg-primary">
                        {c.notices.failed}
                    </p>
                ) : empty ? (
                    <section className="flex flex-col items-center gap-4 rounded-sm bg-surface px-6 py-12 text-center">
                        <span aria-hidden="true" className="flex size-20 items-center justify-center rounded-full bg-brand-subtle text-fg-brand">
                            <Icon name="shoppingBagOpen" className="size-icon-category" />
                        </span>
                        <h2 className="t-h2 text-fg-primary">{c.emptyTitle}</h2>
                        <p className="t-body max-w-100 text-fg-secondary">{c.emptyBody}</p>
                        <a href={localizedPath(locale, '/toys')} className={buttonClass('primary')}>
                            {c.browseAll}
                        </a>
                    </section>
                ) : (
                    <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:gap-8">
                        <section aria-labelledby="cart-lines" className="-mx-gutter-mobile min-w-0 md:mx-0 lg:grow">
                            <h2 id="cart-lines" className="sr-only">
                                {c.lines}
                            </h2>
                            <ul className="overflow-hidden border-y border-line-default bg-surface md:rounded-sm md:border">
                                {cart.items.map((line) => (
                                    <CartLineItem key={line.id} line={line} locale={locale} t={t} notice={noticeFor(notice, String(line.id))} />
                                ))}
                            </ul>
                        </section>

                        <div className="flex flex-col gap-4 lg:w-110 lg:shrink-0">
                            <CouponForm coupon={cart.coupon} couponDiscount={cart.totals.coupon_discount} locale={locale} t={t} notice={noticeFor(notice, 'coupon')} />
                            <DestinationForm reference={reference} dest={dest} locale={locale} t={t} />
                            <CartSummary
                                cart={cart}
                                locale={locale}
                                t={t}
                                totalId="cart-total"
                                cta={
                                    ready ? (
                                        <a href={checkoutHref} className={`${buttonClass('primary')} w-full`}>
                                            {c.checkout}
                                        </a>
                                    ) : (
                                        <p role="status" className="t-body-sm-strong rounded-xs bg-warning-subtle px-3 py-2 text-status-low-stock">
                                            {c.notReady}
                                        </p>
                                    )
                                }
                            />
                            <PlainTrust t={t} />
                        </div>
                    </div>
                )}
            </div>

            {!empty && cart ? (
                // CartTotalBar — Figma 120:3034: mobile only, fixed above MobileNav; total + Checkout.
                <div className="fixed inset-x-0 bottom-[calc(--spacing(16)+env(safe-area-inset-bottom))] z-20 flex h-19 items-center gap-3 border-t border-line-brand-subtle bg-surface px-4 shadow-up-nav lg:hidden">
                    <a href="#cart-summary-title" className="flex min-w-0 grow items-center gap-3" aria-label={c.showSummary}>
                        <span className="flex flex-col">
                            <span className="t-caption text-fg-secondary">{c.total}</span>
                            <span className="t-price-current-card tabular-nums text-fg-price">{formatPrice(cart.totals.grand_total)}</span>
                        </span>
                        <Icon name="caretUp" className="size-icon-md text-fg-primary" />
                    </a>
                    {ready ? (
                        <a href={checkoutHref} className={`${buttonClass('primary')} w-50 shrink-0`}>
                            {c.checkoutShort}
                        </a>
                    ) : (
                        <span aria-disabled="true" className={`${buttonClass('primary')} w-50 shrink-0 opacity-40`}>
                            <Icon name="warning" className={buttonIconClass.md} />
                            {c.checkoutShort}
                        </span>
                    )}
                </div>
            ) : null}
        </PageShell>
    );
}
