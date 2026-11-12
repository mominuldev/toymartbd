import type { Metadata } from 'next';
import { cookies } from 'next/headers';
import { notFound } from 'next/navigation';

import { Icon } from '@/components/icons/Icon';
import { buttonClass } from '@/components/layout/button-styles';
import { DeliveryDetails, OrderHeaderCard, OrderHelp, OrderItems, OrderTimeline } from '@/components/order/OrderParts';
import { PageShell } from '@/components/PageShell';
import { ORDER_COOKIE } from '@/lib/cart/cookies';
import { validToken } from '@/lib/cart/server';
import { ORDER_NUMBER } from '@/lib/checkout/form';
import { format, getMessages, isLocale, localizedPath } from '@/lib/i18n';
import { getPlacedOrder } from '@/lib/order/fetch';

// /orders/{number} — the order confirmation (P4-STO-10). There is no Figma frame of its own: it is
// assembled from the checkout and tracking parts (header card, OrderTimeline, delivery details,
// items) under a thank-you heading. Readable only by the browser that placed the order: the cart
// token, kept in a short-lived httpOnly cookie after checkout, proves it (checkout.md). Anyone
// else — or after the cookie expires — gets the generic "track it instead" message, never a hint
// that the number exists. noindex, never cached.

export const dynamic = 'force-dynamic';

interface Props {
    params: Promise<{ locale: string; number: string }>;
}

export async function generateMetadata({ params }: Props): Promise<Metadata> {
    const { locale } = await params;
    if (!isLocale(locale)) return {};
    return { title: getMessages(locale).order.thanksTitle, robots: { index: false, follow: false } };
}

export default async function OrderConfirmationPage({ params }: Props) {
    const { locale, number: raw } = await params;
    if (!isLocale(locale)) notFound();
    const t = getMessages(locale);
    const o = t.order;
    const number = decodeURIComponent(raw).toUpperCase();
    const token = validToken((await cookies()).get(ORDER_COOKIE)?.value);
    const order = token && ORDER_NUMBER.test(number) ? await getPlacedOrder(number, token, locale).catch(() => null) : null;
    const trackHref = localizedPath(locale, `/track?number=${encodeURIComponent(number)}`);
    const path = `/orders/${number}`;

    if (!order) {
        return (
            <PageShell locale={locale} path={path} top="tight">
                <section className="mx-auto flex max-w-120 flex-col items-center gap-4 rounded-sm bg-surface px-6 py-12 text-center">
                    <h1 className="t-h2 text-fg-primary">{o.notFoundTitle}</h1>
                    <p className="t-body text-fg-secondary">{o.notFoundBody}</p>
                    <a href={trackHref} className={buttonClass('primary')}>
                        {o.track}
                    </a>
                </section>
            </PageShell>
        );
    }

    return (
        <PageShell locale={locale} path={path} top="tight">
            <div className="flex flex-col gap-4 pb-8 lg:gap-6 lg:pb-20">
                <header className="flex items-start gap-3 rounded-sm bg-success-subtle p-4 lg:p-6">
                    <Icon name="checkCircle" className="size-icon-lg shrink-0 text-status-in-stock lg:size-8" />
                    <div className="flex flex-col gap-1">
                        <h1 className="t-h2 text-fg-primary">{o.thanksTitle}</h1>
                        <p className="t-body text-fg-secondary">{format(o.thanksBody, { phone: order.contact.phone_masked })}</p>
                    </div>
                </header>
                <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:gap-6">
                    <div className="flex min-w-0 flex-col gap-4 lg:grow">
                        <OrderHeaderCard order={order} t={t} />
                        <OrderTimeline order={order} t={t} />
                        <DeliveryDetails order={order} locale={locale} t={t} />
                    </div>
                    <aside className="flex flex-col gap-4 lg:w-100 lg:shrink-0">
                        <OrderItems order={order} locale={locale} t={t} totals />
                        <div className="flex flex-col gap-3">
                            {/* An unpaid online order: its payment page re-reads the status and offers a retry (P4-PAY-04). */}
                            {order.payment.online && !['paid', 'partially_refunded', 'refunded'].includes(order.payment.status) && order.stage !== 'cancelled' ? (
                                <a href={localizedPath(locale, `/orders/${order.number}/payment`)} className={`${buttonClass('primary')} w-full`}>
                                    {t.payment.continuePayment}
                                </a>
                            ) : null}
                            <a href={trackHref} className={`${buttonClass('secondary')} w-full`}>
                                {o.track}
                            </a>
                            <a href={localizedPath(locale, '/toys')} className={`${buttonClass('primary')} w-full`}>
                                {o.continue}
                            </a>
                        </div>
                        <OrderHelp t={t} />
                    </aside>
                </div>
            </div>
        </PageShell>
    );
}
