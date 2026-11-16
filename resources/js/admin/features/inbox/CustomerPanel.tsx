import { useState, type ComponentType, type ReactNode } from 'react';
import { Link } from 'react-router';
import { CurrencyCircleDollarIcon, PackageIcon, PlusIcon, TruckIcon, type IconProps } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { can, useMe } from '@/features/auth/api';
import { Taka } from '@/features/customers';
import { Button } from '@/components/ui/Button';
import { SearchField } from '@/components/ui/SearchField';
import { StatusChip, type StatusTone } from '@/components/ui/StatusChip';
import { useToast } from '@/components/ui/Toast';
import { ApiError } from '@/lib/api';
import { canReplyFreely, isWebForm, useProductOptions, useQuickReply, type ConversationDetail, type InboxCustomer } from './api';
import { langProps } from './parts';

const orderTone: Record<string, StatusTone> = {
    delivered: 'success',
    completed: 'success',
    cancelled: 'danger',
    returned: 'danger',
    partially_returned: 'warning',
    on_hold: 'warning',
    shipped: 'info',
};

/**
 * Figma B · 22 right column: the customer (orders, OUR risk score labelled as ours — rule
 * 24 — and lifetime value, all from the server), the last order, and quick actions. "Re-send
 * with ৳300 advance" from the frame is left out: there is no partial-advance flow to send.
 */
export function CustomerPanel({ detail }: { detail: ConversationDetail }) {
    const { t } = useI18n();
    const customer = detail.customer;

    return (
        <aside aria-label={t('inbox.customer')} className="flex min-h-0 flex-1 flex-col gap-6 overflow-y-auto border-line-default bg-surface px-4 py-5 xl:border-s">
            <section className="flex flex-col gap-3">
                <h2 className="t-admin-overline text-fg-secondary">{t('inbox.customer')}</h2>
                {customer ? <CustomerFacts customer={customer} /> : <p className="t-admin-caption text-fg-secondary">{t(detail.channel === 'messenger' ? 'inbox.customer.noneMessenger' : 'inbox.customer.none')}</p>}
            </section>

            {customer && (
                <section className="flex flex-col gap-3">
                    <h2 className="t-admin-overline text-fg-secondary">{t('inbox.customer.lastOrder')}</h2>
                    {customer.last_order ? <LastOrder order={customer.last_order} /> : <p className="t-admin-caption text-fg-secondary">{t('inbox.customer.noOrders')}</p>}
                </section>
            )}

            <QuickActions detail={detail} />
        </aside>
    );
}

function Fact({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="flex items-baseline justify-between gap-3 py-1.5">
            <dt className="t-admin-body text-fg-secondary">{label}</dt>
            <dd className="t-admin-body-strong text-fg-primary tabular">{children}</dd>
        </div>
    );
}

function CustomerFacts({ customer }: { customer: InboxCustomer }) {
    const { t } = useI18n();
    return (
        <dl className="flex flex-col">
            <Fact label={t('inbox.customer.orders')}>{t('inbox.customer.ordersValue', { orders: customer.orders_count, delivered: customer.delivered_orders_count })}</Fact>
            <Fact label={t('inbox.customer.risk')}>
                {customer.risk ? t('inbox.customer.riskValue', { level: t(`inbox.customer.risk.${customer.risk.level}` as MessageKey), score: customer.risk.score }) : <span className="t-admin-caption text-fg-tertiary">{t('inbox.customer.riskNone')}</span>}
            </Fact>
            <Fact label={t('inbox.customer.ltv')}>
                <Taka amount={customer.lifetime_value} />
            </Fact>
            <Link to={`/customers/${customer.id}`} className="pt-1 t-admin-caption-strong text-fg-link hover:underline">
                {t('inbox.customer.openProfile')}
            </Link>
        </dl>
    );
}

function LastOrder({ order }: { order: NonNullable<InboxCustomer['last_order']> }) {
    const { t } = useI18n();
    const items = langProps(order.items_summary);
    return (
        <Link to={`/orders/${order.id}`} className="flex flex-col gap-2 rounded-md bg-subtle p-3 hover:bg-brand-subtle">
            <span className="t-admin-mono-sm text-fg-primary">
                {order.number} · <Taka amount={order.grand_total} />
            </span>
            <span>
                <StatusChip label={t(`orders.status.${order.status}` as MessageKey)} tone={orderTone[order.status] ?? 'neutral'} />
            </span>
            {order.items_summary && (
                <span lang={items.lang} className={`t-admin-caption text-fg-secondary ${items.className}`}>
                    {order.items_summary}
                    {order.items_count > 1 && ` ${t('inbox.customer.moreItems', { count: order.items_count - 1 })}`}
                </span>
            )}
        </Link>
    );
}

function Action({ icon: Icon, label, reason, onClick, to }: { icon: ComponentType<IconProps>; label: string; reason?: string | null; onClick?: () => void; to?: string }) {
    const className = 'flex w-full items-center gap-2 rounded-sm px-2 py-2 text-start t-admin-body-strong text-fg-primary hover:bg-subtle';
    const content = (
        <>
            <Icon size={18} aria-hidden className="shrink-0" />
            <span className="flex flex-col">
                <span>{label}</span>
                {reason && <span className="t-admin-caption font-normal text-fg-tertiary">{reason}</span>}
            </span>
        </>
    );
    if (to && !reason) {
        return (
            <li>
                <Link to={to} className={className}>
                    {content}
                </Link>
            </li>
        );
    }
    return (
        <li>
            <button type="button" disabled={Boolean(reason)} onClick={onClick} className={`${className} disabled:cursor-not-allowed disabled:bg-transparent disabled:text-fg-disabled`}>
                {content}
            </button>
        </li>
    );
}

function QuickActions({ detail }: { detail: ConversationDetail }) {
    const { t } = useI18n();
    const { data: me } = useMe();
    const { notify } = useToast();
    const quick = useQuickReply(detail.id);
    const [picking, setPicking] = useState(false);
    const canReply = can(me, 'inbox.reply');
    if (!canReply) return null;

    const windowReason = canReplyFreely(detail.reply.state) ? null : t(isWebForm(detail.channel) ? 'inbox.quick.webFormReason' : 'inbox.quick.windowReason');
    const lastOrder = detail.customer?.last_order ?? null;
    const send = (kind: 'product_card' | 'tracking_link', targetId: number) =>
        quick.mutate(
            { kind, target_id: targetId },
            {
                onSuccess: () => {
                    setPicking(false);
                    notify(t('inbox.toast.sent'));
                },
                onError: (e) => notify(e instanceof ApiError && e.problem.detail ? e.problem.detail : t('inbox.toast.failed'), 'danger'),
            },
        );
    // Create order from chat → the existing create-order screen, which preselects the source and
    // fills the customer's phone from `customer_id` (OrderComposer).
    const source = detail.channel === 'whatsapp' ? 'whatsapp' : detail.channel === 'messenger' ? 'facebook' : 'admin';
    const createOrder = `/orders/new?source=${source}${detail.customer ? `&customer_id=${detail.customer.id}` : ''}`;

    return (
        <section className="flex flex-col gap-2">
            <h2 className="t-admin-overline text-fg-secondary">{t('inbox.quick')}</h2>
            <ul className="flex flex-col">
                <Action icon={PackageIcon} label={t('inbox.quick.product')} reason={windowReason} onClick={() => setPicking((p) => !p)} />
                {picking && !windowReason && <ProductPicker onPick={(id) => send('product_card', id)} onCancel={() => setPicking(false)} busy={quick.isPending} />}
                <Action icon={CurrencyCircleDollarIcon} label={t('inbox.quick.bkash')} reason={t('inbox.quick.bkashReason')} />
                <Action
                    icon={TruckIcon}
                    label={t('inbox.quick.tracking')}
                    reason={windowReason ?? (lastOrder ? null : t('inbox.quick.trackingReason'))}
                    onClick={() => lastOrder && send('tracking_link', lastOrder.id)}
                />
                {can(me, 'orders.create') && <Action icon={PlusIcon} label={t('inbox.quick.createOrder')} to={createOrder} />}
            </ul>
        </section>
    );
}

function ProductPicker({ onPick, onCancel, busy }: { onPick: (id: number) => void; onCancel: () => void; busy: boolean }) {
    const { t, locale } = useI18n();
    const [search, setSearch] = useState('');
    const products = useProductOptions(search.trim(), true);
    return (
        <li className="flex flex-col gap-2 rounded-md border border-line-default p-2">
            <SearchField label={t('inbox.quick.productSearch')} placeholder={t('inbox.quick.productSearch')} value={search} onChange={(e) => setSearch(e.target.value)} />
            <ul className="flex max-h-56 flex-col overflow-y-auto">
                {(products.data ?? []).map((p) => {
                    const name = locale === 'bn' && p.name_bn ? p.name_bn : p.name;
                    return (
                        <li key={p.id}>
                            <button
                                type="button"
                                disabled={busy}
                                onClick={() => onPick(p.id)}
                                aria-label={t('inbox.quick.productSend', { name })}
                                className="w-full truncate rounded-sm px-2 py-1.5 text-start t-admin-body text-fg-primary hover:bg-subtle"
                            >
                                {name}
                            </button>
                        </li>
                    );
                })}
                {products.data?.length === 0 && <li className="px-2 py-1.5 t-admin-caption text-fg-secondary">{t('inbox.quick.productNone')}</li>}
            </ul>
            <Button variant="ghost" size="sm" onClick={onCancel}>
                {t('inbox.quick.cancel')}
            </Button>
        </li>
    );
}
