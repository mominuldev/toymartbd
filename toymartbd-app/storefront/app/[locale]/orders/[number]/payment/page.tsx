import { randomUUID } from 'node:crypto';

import type { Metadata } from 'next';
import { cookies } from 'next/headers';
import { notFound } from 'next/navigation';

import { Icon } from '@/components/icons/Icon';
import { buttonClass } from '@/components/layout/button-styles';
import { OrderHeaderCard, OrderHelp, OrderItems } from '@/components/order/OrderParts';
import { PageShell } from '@/components/PageShell';
import type { StorefrontOrder } from '@/lib/cart/types';
import { ORDER_COOKIE } from '@/lib/cart/cookies';
import { validToken } from '@/lib/cart/server';
import { ORDER_NUMBER } from '@/lib/checkout/form';
import { format, getMessages, isLocale, localizedPath, type Messages } from '@/lib/i18n';
import { formatPrice } from '@/lib/listing/format';
import { PAYMENT_FLASH_COOKIE } from '@/lib/payments/cookies';
import { getPlacedOrder } from '@/lib/order/fetch';
import { contact } from '@/lib/site-config';
import { privateMetadata } from '@/lib/seo/metadata';

// /orders/{number}/payment — where the customer lands after SSLCommerz (success, fail or cancel)
// and after a retry that could not open a session (P4-PAY-03/04). It NEVER trusts the URL, a query
// parameter or the gateway's POST: it re-reads the order from the API, server-side, with the placing
// cart's cookie (as the confirmation page does), and shows what the API says:
//
//   paid → received · at the gateway → confirming (continue / check again) · failed or cancelled
//   with retry allowed → payment failed + "Try payment again" (a new session, same stock hold) ·
//   order cancelled → the window lapsed · held for review → we will call.
//
// No Figma frame of its own yet: built from the confirmation page's parts. noindex, never cached.

export const dynamic = 'force-dynamic';

interface Props {
    params: Promise<{ locale: string; number: string }>;
}

type View = 'paid' | 'pending' | 'failed' | 'expired' | 'review';

function viewOf(order: StorefrontOrder): View {
    const p = order.payment;
    if (['paid', 'partially_refunded', 'refunded'].includes(p.status)) return 'paid';
    if (order.stage === 'cancelled') return 'expired';
    if (p.retry_allowed) return 'failed';
    if (p.next_action || p.status === 'pending') return 'pending';
    return 'review';
}

const tone: Record<View, { box: string; icon: 'checkCircle' | 'clock' | 'warningCircle' | 'info' }> = {
    paid: { box: 'bg-success-subtle', icon: 'checkCircle' },
    pending: { box: 'bg-info-subtle', icon: 'clock' },
    failed: { box: 'bg-danger-subtle', icon: 'warningCircle' },
    expired: { box: 'bg-warning-subtle', icon: 'info' },
    review: { box: 'bg-warning-subtle', icon: 'info' },
};

function reasonText(reason: string | undefined, t: Messages): string | null {
    if (!reason) return null;
    const reasons = t.payment.reasons as Record<string, string>;
    return format(reasons[reason] ?? t.payment.reasons.failed, { phone: contact.hotline.display });
}

export async function generateMetadata({ params }: Props): Promise<Metadata> {
    const { locale, number } = await params;
    if (!isLocale(locale)) return {};
    return privateMetadata(locale, `/orders/${encodeURIComponent(number)}/payment`, format(getMessages(locale).payment.title, { number: decodeURIComponent(number).toUpperCase() }));
}

export default async function OrderPaymentPage({ params }: Props) {
    const { locale, number: raw } = await params;
    if (!isLocale(locale)) notFound();
    const t = getMessages(locale);
    const p = t.payment;
    const number = decodeURIComponent(raw).toUpperCase();
    const jar = await cookies();
    const token = validToken(jar.get(ORDER_COOKIE)?.value);
    const order = token && ORDER_NUMBER.test(number) ? await getPlacedOrder(number, token, locale).catch(() => null) : null;
    const flash = reasonText(jar.get(PAYMENT_FLASH_COOKIE)?.value, t);
    const path = `/orders/${number}/payment`;

    if (!order) {
        return (
            <PageShell locale={locale} path={path} top="tight">
                <section className="mx-auto flex max-w-120 flex-col items-center gap-4 rounded-sm bg-surface px-6 py-12 text-center">
                    <h1 className="t-h2 text-fg-primary">{t.order.notFoundTitle}</h1>
                    <p className="t-body text-fg-secondary">{t.order.notFoundBody}</p>
                    <a href={localizedPath(locale, `/track?number=${encodeURIComponent(number)}`)} className={buttonClass('primary')}>
                        {t.order.track}
                    </a>
                </section>
            </PageShell>
        );
    }

    const view = viewOf(order);
    const vars = { number: order.number, phone: order.contact.phone_masked, amount: formatPrice(order.totals.paid_total) };
    const heading: Record<View, [string, string]> = {
        paid: [p.successTitle, format(p.successBody, vars)],
        pending: [p.pendingTitle, p.pendingBody],
        failed: [p.failedTitle, format(p.failedBody, vars)],
        expired: [p.expiredTitle, format(p.expiredBody, vars)],
        review: [p.reviewTitle, format(p.reviewBody, vars)],
    };
    const [title, body] = heading[view];
    const confirmHref = localizedPath(locale, `/orders/${order.number}`);
    const next = order.payment.next_action;

    return (
        <PageShell locale={locale} path={path} top="tight">
            <div className="flex flex-col gap-4 pb-8 lg:gap-6 lg:pb-20">
                {/* No frame of its own: drawn as the order page's status card (Figma 237:10143) — a tinted tile,
                    22px icon inline with the title, the explanation in body-sm under it. */}
                <header role={view === 'failed' ? 'alert' : 'status'} className={`flex flex-col gap-1.5 rounded-sm p-4 lg:p-6 ${tone[view].box}`}>
                    <h1 className="t-h3 lg:t-h2 flex items-start gap-2 text-fg-primary">
                        <Icon name={tone[view].icon} className="mt-0.5 size-5.5 shrink-0 lg:mt-1" />
                        {title}
                    </h1>
                    <p className="t-body-sm lg:t-body text-fg-primary">{body}</p>
                    {flash ? <p className="t-body-sm-strong text-feedback-danger">{flash}</p> : null}
                </header>
                <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:gap-6">
                    <div className="flex min-w-0 flex-col gap-4 lg:grow">
                        <OrderHeaderCard order={order} t={t} />
                    </div>
                    <aside className="flex flex-col gap-3 lg:w-100 lg:shrink-0">
                        {view === 'failed' ? (
                            <form method="post" action="/api/payments/retry" className="flex flex-col gap-2">
                                <input type="hidden" name="number" value={order.number} />
                                <input type="hidden" name="locale" value={locale} />
                                <input type="hidden" name="idempotency_key" value={randomUUID()} />
                                <button type="submit" className={`${buttonClass('primary')} w-full`}>
                                    {format(p.retry, { total: formatPrice(order.totals.due_total) })}
                                </button>
                            </form>
                        ) : null}
                        {view === 'pending' && next?.type === 'redirect' ? (
                            <a href={next.url} className={`${buttonClass('primary')} w-full`}>
                                {p.continuePayment}
                            </a>
                        ) : null}
                        {view === 'pending' ? (
                            <a href={localizedPath(locale, path)} className={`${buttonClass('secondary')} w-full`}>
                                {p.checkAgain}
                            </a>
                        ) : null}
                        <a href={view === 'expired' ? localizedPath(locale, '/toys') : confirmHref} className={`${buttonClass(view === 'paid' || view === 'expired' ? 'primary' : 'secondary')} w-full`}>
                            {view === 'expired' ? p.shopAgain : p.viewOrder}
                        </a>
                        <OrderItems order={order} locale={locale} t={t} totals />
                        <OrderHelp t={t} />
                    </aside>
                </div>
            </div>
        </PageShell>
    );
}
