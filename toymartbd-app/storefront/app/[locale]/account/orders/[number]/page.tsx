import type { Metadata } from 'next';
import { notFound } from 'next/navigation';

import { AccountShell } from '@/components/account/AccountShell';
import { Icon } from '@/components/icons/Icon';
import type { IconName } from '@/components/icons/paths';
import { buttonClass, buttonIconClass } from '@/components/layout/button-styles';
import { OrderAddressCard } from '@/components/account/OrderAddressCard';
import { OrderItems, OrderTimeline } from '@/components/order/OrderParts';
import { isZero, itemsLabel } from '@/lib/cart/format';
import type { OrderStage } from '@/lib/cart/types';
import { contact } from '@/lib/site-config';
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
// Not built yet (no backend): "pay now by bKash", courier tracking link, invoice PDF, return / exchange.

export const dynamic = 'force-dynamic';

const NUMBER = /^[A-Za-z0-9-]{3,32}$/;

const MOVING = new Set<OrderStage>(['placed', 'confirmed', 'processing', 'shipped']);

/** The status card's tint and icon follow the StagePill (236:2302): moving sky, preparing butter, done mint. */
const STATUS: Record<OrderStage, { tone: string; icon: IconName }> = {
    placed: { tone: 'bg-tile-butter', icon: 'clock' },
    confirmed: { tone: 'bg-tile-butter', icon: 'clock' },
    processing: { tone: 'bg-tile-butter', icon: 'clock' },
    shipped: { tone: 'bg-tile-sky', icon: 'truck' },
    delivered: { tone: 'bg-tile-mint', icon: 'checkCircle' },
    cancelled: { tone: 'bg-subtle', icon: 'x' },
    returned: { tone: 'bg-subtle', icon: 'arrowCounterClockwise' },
};

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

    const placed = order.placed_at ? format(m.orders.placed, { date: orderDateTime(order.placed_at, t) }) : null;
    const meta = format(m.orders.meta, { items: itemsLabel(orderItemCount(order), t.cart), total: formatPrice(order.totals.grand_total), payment: paymentLabel(order, m) });
    const due = order.payment.method === 'cod' && MOVING.has(order.stage) && !isZero(order.totals.due_total) ? format(m.orders.statusDue, { amount: formatPrice(order.totals.due_total) }) : null;

    return (
        <AccountShell
            locale={locale}
            path={path}
            account={account}
            section="orders"
            title={format(m.orders.number, { number: order.number })}
            back={{ path: '/account/orders', label: m.orders.back }}
            heading={{ sub: placed ?? meta, subDesktop: placed ? `${placed} · ${meta}` : meta }}
        >
            <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:gap-6">
                <div className="flex min-w-0 flex-col gap-4 lg:grow">
                    {/* Status card (237:10143): stage-tinted tile, icon + h3 stage, what happens next. The
                        design's "Pay now with bKash" / "Track on Pathao" need a gateway retry from the account
                        and courier tracking links, which the API does not offer yet — not shown. */}
                    <section aria-labelledby="order-status" className={`flex flex-col gap-1.5 rounded-sm p-4 ${STATUS[order.stage].tone}`}>
                        <h2 id="order-status" className="t-h3 flex items-center gap-2 text-fg-primary">
                            <Icon name={STATUS[order.stage].icon} className="size-5.5 shrink-0" />
                            {t.order.badges[order.stage]}
                        </h2>
                        <p className="t-body-sm text-fg-primary tabular-nums">
                            {m.orders.status[order.stage]}
                            {due ? ` ${due}` : ''}
                        </p>
                    </section>
                    <OrderTimeline order={order} locale={locale} t={t} />
                </div>
                <aside className="flex flex-col gap-4 lg:w-104 lg:shrink-0">
                    <OrderItems order={order} locale={locale} t={t} totals />
                    <OrderAddressCard order={order} locale={locale} t={t} m={m} />
                    {/* "Need help? WhatsApp us" — Button Secondary Size=lg, full width (237:10066). Invoice PDF and
                        return / exchange in the design have no backend yet. */}
                    <a href={contact.whatsapp.href} className={`${buttonClass('secondary')} w-full`}>
                        <Icon name="whatsappLogo" className={buttonIconClass.lg} />
                        {m.orders.needHelp}
                    </a>
                </aside>
            </div>
        </AccountShell>
    );
}
