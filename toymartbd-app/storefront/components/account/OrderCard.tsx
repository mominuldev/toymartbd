import { Icon } from '@/components/icons/Icon';
import { itemsLabel } from '@/lib/cart/format';
import type { AccountMessages } from '@/lib/account/messages';
import type { OrderSummary } from '@/lib/account/types';
import { format, localizedPath, type Locale, type Messages } from '@/lib/i18n';
import { formatPrice } from '@/lib/listing/format';
import { orderDate } from '@/lib/order/format';
import { contact } from '@/lib/site-config';
import { StagePill } from './StagePill';
import { card, smallButton } from './styles';

// AccountOrderCard — Figma 236:2302 (Processing · On the way · Delivered · Cancelled). Number + placed
// date, the stage pill, up to three 56px thumbnails and "+N", "4 items · ৳3,365 · Cash on delivery",
// then actions that follow the stage: "Track order" while it is moving, "Order details" after.
// Every figure is the API's (rule 1).

const MOVING = new Set(['placed', 'confirmed', 'processing', 'shipped']);

export function paymentLabel(order: Pick<OrderSummary, 'payment'>, m: AccountMessages): string {
    if (order.payment.method === 'cod') return m.orders.cod;
    return order.payment.status === 'paid' ? m.orders.paid : (order.payment.method ?? '');
}

export function OrderCard({ order, locale, t, m }: { order: OrderSummary; locale: Locale; t: Messages; m: AccountMessages }) {
    const href = localizedPath(locale, `/account/orders/${order.number}`);
    const moving = MOVING.has(order.stage);
    return (
        <article className={`${card} flex flex-col gap-3`}>
            <header className="flex min-w-0 flex-col gap-0.5">
                <h3 className="t-body-sm-strong text-fg-primary tabular-nums">
                    <a href={href} className="hover:underline">
                        {format(m.orders.number, { number: order.number })}
                    </a>
                </h3>
                {order.placed_at ? <p className="t-caption text-fg-tertiary tabular-nums">{format(m.orders.placed, { date: orderDate(order.placed_at, t) })}</p> : null}
            </header>
            <StagePill stage={order.stage} label={t.order.badges[order.stage]} />
            {order.preview.length > 0 ? (
                <ul className="flex items-center gap-2">
                    {order.preview.map((item, i) => {
                        const alt = (locale === 'bn' ? item.image?.alt_bn : item.image?.alt) ?? (locale === 'bn' && item.name_bn ? item.name_bn : item.name);
                        return (
                            <li key={i} className={`size-14 shrink-0 overflow-hidden rounded-xs-plus ${item.image ? 'bg-image' : 'bg-tile-pink'}`}>
                                {item.image ? <img src={item.image.url} alt={alt} width={56} height={56} loading="lazy" className="size-full object-cover" /> : <span className="sr-only">{alt}</span>}
                            </li>
                        );
                    })}
                    {order.more_items > 0 ? (
                        <li className="t-body-sm-strong flex size-14 shrink-0 items-center justify-center rounded-xs-plus bg-subtle text-fg-secondary tabular-nums">
                            <span aria-hidden="true">{format(m.orders.more, { count: String(order.more_items) })}</span>
                            <span className="sr-only">{format(m.orders.moreLabel, { count: String(order.more_items) })}</span>
                        </li>
                    ) : null}
                </ul>
            ) : null}
            <p className="t-caption text-fg-secondary tabular-nums">
                {format(m.orders.meta, { items: itemsLabel(order.item_count, t.cart), total: formatPrice(order.grand_total), payment: paymentLabel(order, m) })}
            </p>
            <div className="flex gap-2">
                {moving ? (
                    <>
                        <a href={href} className={`${smallButton.primary} grow basis-0`}>
                            <Icon name="truck" className="size-4" />
                            {m.orders.track}
                        </a>
                        {/* Same help channel as the order pages' OrderHelp card (placeholder number until the owner confirms it). */}
                        <a href={contact.whatsapp.href} className={`${smallButton.outline} grow basis-0`}>
                            <Icon name="shoppingBagOpenFill" className="size-4" />
                            {m.orders.help}
                        </a>
                    </>
                ) : (
                    <a href={href} className={`${smallButton.outline} grow`}>
                        <Icon name="shoppingBagOpenFill" className="size-4" />
                        {m.orders.details}
                    </a>
                )}
            </div>
        </article>
    );
}
