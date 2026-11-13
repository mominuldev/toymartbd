import { Icon } from '@/components/icons/Icon';
import { isZero, itemsLabel, minusPrice } from '@/lib/cart/format';
import type { StorefrontOrder } from '@/lib/cart/types';
import { placeName } from '@/lib/delivery/format';
import { format, localizedPath, type Locale, type Messages } from '@/lib/i18n';
import { formatPrice } from '@/lib/listing/format';
import { orderDate, orderDateTime, orderItemCount, orderItemName, timelineSteps } from '@/lib/order/format';
import { contact } from '@/lib/site-config';

// The order views shared by the confirmation page and public tracking (P4-STO-10; Figma Order
// tracking 132:2899 / 161:16502, OrderStep 121:1557, OrderTimeline 121:1558). Server components;
// every figure is the API's.

const card = 'rounded-sm border border-line-default bg-surface p-4 lg:p-4';

const badgeTone: Record<StorefrontOrder['stage'], string> = {
    placed: 'bg-info-subtle text-feedback-info',
    confirmed: 'bg-info-subtle text-feedback-info',
    processing: 'bg-info-subtle text-feedback-info',
    shipped: 'bg-info-subtle text-feedback-info',
    delivered: 'bg-success-subtle text-status-in-stock',
    cancelled: 'bg-danger-subtle text-feedback-danger',
    returned: 'bg-subtle text-fg-secondary',
};

/** "Order TMB-26-00042 · Placed 10 Nov · 3 items · ৳6,825 COD" with the stage badge. */
export function OrderHeaderCard({ order, t }: { order: StorefrontOrder; t: Messages }) {
    const o = t.order;
    const cod = order.payment.method === 'cod';
    return (
        <section aria-labelledby="order-number" className={`${card} flex flex-col gap-3`}>
            <div className="flex items-start justify-between gap-3">
                <div className="flex min-w-0 flex-col gap-0.5">
                    <h2 id="order-number" className="t-h4 text-fg-primary tabular-nums">
                        {format(o.number, { number: order.number })}
                    </h2>
                    <p className="t-caption text-fg-secondary tabular-nums">
                        {format(cod ? o.placedLineCod : o.placedLine, {
                            date: orderDate(order.placed_at, t),
                            items: itemsLabel(orderItemCount(order), t.cart),
                            total: formatPrice(order.totals.grand_total),
                        })}
                    </p>
                </div>
                <span className={`t-badge shrink-0 rounded-full px-2 py-0.5 ${badgeTone[order.stage]}`}>{o.badges[order.stage]}</span>
            </div>
        </section>
    );
}

/** OrderTimeline — Figma 121:1558: a vertical rail of OrderSteps (done ✓ coral, current ring, upcoming grey). */
export function OrderTimeline({ order, t }: { order: StorefrontOrder; t: Messages }) {
    const steps = timelineSteps(order);
    return (
        <section aria-labelledby="order-timeline" className={`${card} px-4 py-5`}>
            <h2 id="order-timeline" className="sr-only">
                {t.order.timelineTitle}
            </h2>
            <ol className="flex flex-col">
                {steps.map((step, i) => {
                    const last = i === steps.length - 1;
                    const reached = step.state !== 'upcoming';
                    const next = steps[i + 1];
                    return (
                        <li key={step.stage} aria-current={step.state === 'current' ? 'step' : undefined} className="relative flex min-h-12.5 gap-3 pb-4 last:pb-0">
                            {!last ? <span aria-hidden="true" className={`absolute start-2.25 top-5 bottom-0 w-0.5 ${next && next.state !== 'upcoming' ? 'bg-brand' : 'bg-line-default'}`} /> : null}
                            <span
                                aria-hidden="true"
                                className={`relative z-10 flex size-5 shrink-0 items-center justify-center rounded-full ${
                                    step.state === 'complete'
                                        ? 'bg-brand text-fg-on-brand'
                                        : step.state === 'current'
                                          ? 'border-2 border-brand bg-surface'
                                          : step.state === 'stopped'
                                            ? 'bg-feedback-danger text-fg-on-danger'
                                            : 'bg-subtle'
                                }`}
                            >
                                {step.state === 'complete' ? <Icon name="check" className="size-3" /> : step.state === 'current' ? <span className="size-2 rounded-full bg-brand" /> : step.state === 'stopped' ? <Icon name="x" className="size-3" /> : null}
                            </span>
                            <span className="flex flex-col gap-0.5">
                                <span className={`t-body-sm ${reached ? 'text-fg-primary' : 'text-fg-tertiary'}`}>{t.order.stages[step.stage]}</span>
                                {step.at ? <span className="t-caption text-fg-secondary tabular-nums">{orderDateTime(step.at, t)}</span> : null}
                            </span>
                        </li>
                    );
                })}
            </ol>
        </section>
    );
}

/** Delivery details: Deliver to · Payment · Courier (three columns at 1440, stacked at 390). */
export function DeliveryDetails({ order, locale, t }: { order: StorefrontOrder; locale: Locale; t: Messages }) {
    const o = t.order;
    const bn = locale === 'bn';
    const s = order.shipping;
    const places = [s.area, s.upazila ? placeName(s.upazila, bn) : null, s.district ? placeName(s.district, bn) : null].filter(Boolean).join(', ');
    const cod = order.payment.method === 'cod';
    const label = 't-overline text-fg-tertiary';
    return (
        <section aria-labelledby="order-delivery" className={`${card} flex flex-col gap-4 lg:p-5`}>
            <h2 id="order-delivery" className="t-h4 text-fg-primary">
                {o.deliveryDetails}
            </h2>
            <dl className="t-body-sm grid gap-4 text-fg-primary lg:grid-cols-3">
                <div className="flex flex-col gap-1">
                    <dt className={label}>{o.deliverTo}</dt>
                    <dd>{s.name}</dd>
                    {s.address_line ? <dd>{s.address_line}</dd> : null}
                    {s.landmark ? <dd className="text-fg-secondary">{s.landmark}</dd> : null}
                    <dd>
                        {places}
                        {s.postcode ? ` ${s.postcode}` : ''} · <span className="tabular-nums">{s.phone_masked}</span>
                    </dd>
                </div>
                <div className="flex flex-col gap-1">
                    <dt className={label}>{o.payment}</dt>
                    <dd>{cod ? o.cod : order.payment.status === 'paid' ? `${t.payment.onlineShort} · ${o.paid}` : t.payment.onlineShort}</dd>
                    <dd className="tabular-nums">{cod && !isZero(order.totals.due_total) ? format(o.codDue, { amount: formatPrice(order.totals.due_total) }) : o.paid}</dd>
                </div>
                <div className="flex flex-col gap-1">
                    <dt className={label}>{o.courier}</dt>
                    <dd className="text-fg-secondary">{o.courierPending}</dd>
                </div>
            </dl>
        </section>
    );
}

/** "In this order": 48 thumbnails, name, × qty, line total. */
export function OrderItems({ order, locale, t, totals = false }: { order: StorefrontOrder; locale: Locale; t: Messages; totals?: boolean }) {
    const o = t.order;
    const row = 't-body-sm flex items-center justify-between gap-3';
    return (
        <section aria-labelledby="order-items" className={`${card} flex flex-col gap-3`}>
            <h2 id="order-items" className="t-h4 text-fg-primary">
                {o.itemsTitle}
            </h2>
            <ul className="flex flex-col gap-3">
                {order.items.map((item) => {
                    const name = orderItemName(item, locale);
                    const inner = (
                        <span lang={name.lang} className="t-body-sm line-clamp-2 text-fg-primary">
                            {name.text}
                        </span>
                    );
                    return (
                        <li key={item.variant_id} className="flex items-center gap-3">
                            <span className="size-12 shrink-0 overflow-hidden rounded-xs border border-line-default bg-tile-pink">
                                {item.image ? <img src={item.image.url} alt="" width={48} height={48} loading="lazy" className="size-full object-cover" /> : null}
                            </span>
                            <span className="flex min-w-0 grow flex-col">
                                {item.slug ? (
                                    <a href={localizedPath(locale, `/products/${item.slug}`)} className="hover:underline">
                                        {inner}
                                    </a>
                                ) : (
                                    inner
                                )}
                                <span className="t-caption text-fg-tertiary tabular-nums">
                                    {item.variant_name ? `${item.variant_name} · ` : ''}
                                    {format(o.qty, { count: String(item.quantity) })}
                                </span>
                            </span>
                            <span className="t-body-sm-strong shrink-0 text-fg-primary tabular-nums">{formatPrice(item.total)}</span>
                        </li>
                    );
                })}
            </ul>
            {totals ? (
                <dl className="flex flex-col gap-1 border-t border-line-default pt-3">
                    <div className={row}>
                        <dt className="text-fg-secondary">{format(t.cart.subtotal, { items: itemsLabel(orderItemCount(order), t.cart) })}</dt>
                        <dd className="tabular-nums">{formatPrice(order.totals.subtotal)}</dd>
                    </div>
                    {!isZero(order.totals.discount_total) ? (
                        <div className={row}>
                            <dt className="text-fg-secondary">{order.coupon_code ? format(t.cart.coupon, { code: order.coupon_code }) : t.cart.discount}</dt>
                            <dd className="tabular-nums text-fg-discount">{minusPrice(order.totals.discount_total)}</dd>
                        </div>
                    ) : null}
                    <div className={row}>
                        <dt className="text-fg-secondary">{t.cart.delivery}</dt>
                        <dd className={`tabular-nums ${isZero(order.totals.shipping_charge) ? 'text-status-in-stock' : ''}`}>{isZero(order.totals.shipping_charge) ? t.cart.free : formatPrice(order.totals.shipping_charge)}</dd>
                    </div>
                    <div className="flex items-center justify-between gap-3 pt-2">
                        <dt className="t-h4 text-fg-primary">{t.cart.total}</dt>
                        <dd className="t-price-current-card tabular-nums text-fg-price">{formatPrice(order.totals.grand_total)}</dd>
                    </div>
                </dl>
            ) : null}
        </section>
    );
}

/** The pink WhatsApp help card ("Questions about this order?"). */
export function OrderHelp({ t }: { t: Messages }) {
    return (
        <a href={contact.whatsapp.href} className="flex items-center gap-3 rounded-sm bg-tile-pink p-4 hover:shadow-e1">
            <span aria-hidden="true" className="flex size-9 shrink-0 items-center justify-center rounded-full bg-status-in-stock text-fg-inverse">
                <Icon name="whatsappLogo" className="size-icon-md" />
            </span>
            <span className="flex flex-col">
                <span className="t-body-sm-strong text-fg-primary">{t.order.helpTitle}</span>
                <span className="t-caption text-fg-secondary">{t.order.helpBody}</span>
            </span>
        </a>
    );
}
