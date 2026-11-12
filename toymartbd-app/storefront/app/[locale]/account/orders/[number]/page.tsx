import type { Metadata } from 'next';
import { notFound } from 'next/navigation';

import { AccountShell } from '@/components/account/AccountShell';
import { StagePill } from '@/components/account/StagePill';
import { DeliveryDetails, OrderHelp, OrderItems, OrderTimeline } from '@/components/order/OrderParts';
import { itemsLabel } from '@/lib/cart/format';
import { paymentLabel } from '@/components/account/OrderCard';
import { accountMessages } from '@/lib/account/messages';
import { getOrder, requireAccount } from '@/lib/account/server';
import { format, getMessages, isLocale } from '@/lib/i18n';
import { formatPrice } from '@/lib/listing/format';
import { orderDateTime, orderItemCount } from '@/lib/order/format';

// /account/orders/[number] — Order detail, Figma 239:12698 (1440) / 237:10066 (390): title + placed
// line, the stage, the OrderTimeline, then items + totals, the delivery address and help — the order
// parts shared with confirmation and tracking (components/order/OrderParts). The API answers 404 for
// any number that is not this customer's, exactly as for one that does not exist.
// Not built yet (no backend): "pay now by bKash", invoice PDF, return / exchange.

export const dynamic = 'force-dynamic';

const NUMBER = /^[A-Za-z0-9-]{3,32}$/;

interface Props {
    params: Promise<{ locale: string; number: string }>;
}

export async function generateMetadata({ params }: Props): Promise<Metadata> {
    const { locale, number } = await params;
    if (!isLocale(locale)) return {};
    return { title: format(accountMessages(locale).meta.order, { number: number.slice(0, 32) }), robots: { index: false, follow: false } };
}

export default async function AccountOrderPage({ params }: Props) {
    const { locale, number } = await params;
    if (!isLocale(locale) || !NUMBER.test(number)) notFound();
    const m = accountMessages(locale);
    const t = getMessages(locale);
    const path = `/account/orders/${number}`;
    const { token, account } = await requireAccount(locale, path);
    const order = await getOrder(token, number, locale);
    if (!order) notFound();

    return (
        <AccountShell locale={locale} path={path} account={account} section="orders" title={format(m.orders.number, { number: order.number })} back={{ path: '/account/orders', label: m.orders.back }}>
            <header className="flex flex-col gap-2">
                <h1 className="t-h1 text-fg-primary tabular-nums">{format(m.orders.number, { number: order.number })}</h1>
                <p className="t-body-sm text-fg-secondary tabular-nums">
                    {format(m.orders.meta, {
                        items: itemsLabel(orderItemCount(order), t.cart),
                        total: formatPrice(order.totals.grand_total),
                        payment: paymentLabel(order, m),
                    })}
                    {order.placed_at ? ` · ${orderDateTime(order.placed_at, t)}` : ''}
                </p>
                <StagePill stage={order.stage} label={t.order.badges[order.stage]} />
            </header>

            <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:gap-6">
                <div className="flex min-w-0 flex-col gap-4 lg:grow">
                    <OrderTimeline order={order} t={t} />
                </div>
                <aside className="flex flex-col gap-4 lg:w-104 lg:shrink-0">
                    <OrderItems order={order} locale={locale} t={t} totals />
                    <DeliveryDetails order={order} locale={locale} t={t} />
                    <OrderHelp t={t} />
                </aside>
            </div>
        </AccountShell>
    );
}
