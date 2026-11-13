import { useMemo, useState, type ReactNode } from 'react';
import { useParams } from 'react-router';
import {
    ArrowUUpLeftIcon,
    CheckCircleIcon,
    CopyIcon,
    CpuIcon,
    EyeIcon,
    GearIcon,
    MapPinIcon,
    NoteIcon,
    PackageIcon,
    PhoneIcon,
    PlugsIcon,
    ShieldIcon,
    TruckIcon,
    WarningIcon,
} from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { Avatar } from '@/components/shell/Avatar';
import { ContentHeader } from '@/components/shell/ContentHeader';
import { Button } from '@/components/ui/Button';
import { EmptyState } from '@/components/ui/EmptyState';
import { inputClass } from '@/components/ui/Field';
import { IconButton } from '@/components/ui/IconButton';
import { QueryError } from '@/components/ui/QueryError';
import { Select } from '@/components/ui/Select';
import { useToast } from '@/components/ui/Toast';
import { storeOrderNoteRequestSchema } from '@toymart/api-types/zod';
import { useAddNote, useOrder, useVerifyPayment, type Order, type RiskLevel } from './api';
import { Taka, dhakaParts, initials, isZeroAmount, takaText } from './format';
import { OrderActions, problemText } from './OrderActions';
import { OrderStatusBadge, PaymentChip, methodLabel, paymentLabel, riskTint, sourceLabel, statusLabel } from './parts';
import { OrderStepper } from './Stepper';
import { OrderRefundPanel } from '@/features/returns';

const card = 'flex flex-col gap-4 rounded-md border border-line-default bg-surface p-5 shadow-e1';

/**
 * Order detail (Figma "03 Order detail" 206:1475, Theme B colours): header with the inline
 * triad and the server's available actions, the three-track OrderStatusStepper, then a 744
 * main column (items + totals, payments, shipment, activity) and a 376 rail (customer,
 * delivery address, our risk score and the courier-evidence panel). Single column below lg.
 */
export function OrderDetailPage() {
    const { t, locale } = useI18n();
    const { orderId } = useParams();
    const id = Number(orderId);
    const order = useOrder(id);

    if (order.isError) return <QueryError error={order.error} onRetry={() => void order.refetch()} />;
    if (order.isPending)
        return (
            <p className="t-admin-body text-fg-tertiary" aria-busy="true">
                {t('common.loading')}
            </p>
        );

    const o = order.data;
    const placed = o.placed_at ? dhakaParts(o.placed_at, locale) : null;
    const itemCount = o.items.reduce((sum, item) => sum + item.quantity, 0);

    return (
        <section className="flex flex-col gap-6">
            <ContentHeader
                breadcrumb={[{ label: t('nav.group.sell') }, { label: t('orders.crumb'), to: '/orders' }, { label: o.number }]}
                title={o.number}
                subtitle={
                    <>
                        <span className="mb-1.5 flex">
                            <OrderStatusBadge form="inline" order={{ ...o, paid: o.totals.paid_total, refunded: o.totals.refunded_total }} />
                        </span>
                        <span className="tabular">
                            {t('orders.detail.subtitle', {
                                placed: placed ? `${placed.dayYear}, ${placed.time}` : '—',
                                source: t(sourceLabel(o.source)),
                                count: itemCount,
                                total: takaText(o.totals.grand_total),
                                method: t(methodLabel(o.payment_method)),
                            })}
                        </span>
                    </>
                }
                actions={<OrderActions order={o} />}
            />

            {o.status === 'cancelled' && o.cancel_reason && (
                <p className="flex items-start gap-2 rounded-md border-l-3 border-feedback-danger bg-subtle px-4 py-3 t-admin-body text-fg-primary">
                    <WarningIcon size={16} aria-hidden className="mt-0.5 shrink-0 text-feedback-danger" />
                    <span>
                        {t('orders.detail.cancelledBecause', { reason: t(`orders.cancelReason.${o.cancel_reason}` as MessageKey) })}
                        {o.cancel_note && <span className="text-fg-secondary"> — {o.cancel_note}</span>}
                    </span>
                </p>
            )}

            <OrderStepper order={o} />

            <div className="grid grid-cols-1 items-start gap-6 lg:grid-cols-[minmax(0,1fr)_17.5rem] xl:grid-cols-[minmax(0,1fr)_23.5rem]">
                <div className="flex min-w-0 flex-col gap-6">
                    <ItemsCard order={o} />
                    <PaymentsCard order={o} />
                    <OrderRefundPanel orderId={o.id} />
                    <section className={card} aria-labelledby="order-shipment">
                        <h2 id="order-shipment" className="t-admin-section text-fg-primary">
                            {t('orders.shipment.title')}
                        </h2>
                        <EmptyState reason="notConnected" title={t('orders.shipment.empty')} body={t('orders.shipment.emptyBody')} className="border-dashed bg-subtle" />
                    </section>
                    <ActivityCard order={o} />
                </div>
                <div className="flex min-w-0 flex-col gap-6">
                    <CustomerCard order={o} />
                    <AddressCard order={o} />
                    <RiskCard order={o} />
                    <CourierEvidenceCard />
                </div>
            </div>
        </section>
    );
}

function CardHeader({ id, title, sub, action }: { id: string; title: string; sub?: string; action?: ReactNode }) {
    return (
        <div className="flex flex-wrap items-start justify-between gap-2">
            <div className="flex min-w-0 flex-col gap-0.5">
                <h2 id={id} className="t-admin-section text-fg-primary">
                    {title}
                </h2>
                {sub && <p className="t-admin-caption text-fg-tertiary">{sub}</p>}
            </div>
            {action}
        </div>
    );
}

function ItemsCard({ order }: { order: Order }) {
    const { t, locale } = useI18n();
    // A line removed by an edit stays on the order at quantity 0 (items are never deleted, P4-ORD-07).
    const lines = order.items.filter((item) => item.quantity > 0);
    const anyCost = order.items.some((item) => item.cost_price_snapshot !== null);
    const tot = order.totals;
    const delivered = order.status === 'delivered' || order.status === 'completed';

    return (
        <section className={card} aria-labelledby="order-items">
            <CardHeader id="order-items" title={t('orders.items.title', { count: lines.length })} sub={anyCost ? t('orders.items.costNote') : undefined} />
            <ul className="flex flex-col">
                {lines.map((item) => {
                    const name = locale === 'bn' ? (item.product_name_bn ?? item.product_name) : item.product_name;
                    return (
                        <li key={item.id} className="grid grid-cols-[3rem_minmax(0,1fr)_auto] items-center gap-x-3 gap-y-2 border-t border-line-default py-3 first:border-t-0 sm:grid-cols-[3rem_minmax(0,1fr)_minmax(0,13.75rem)_auto]">
                            <span aria-hidden className="grid size-12 place-items-center rounded-sm bg-subtle text-fg-tertiary">
                                <PackageIcon size={22} />
                            </span>
                            <div className="min-w-0">
                                <p className="truncate t-admin-body-strong text-fg-primary">
                                    {name}
                                    {item.variant_name && <span className="t-admin-body text-fg-secondary"> · {item.variant_name}</span>}
                                </p>
                                <p className="t-admin-mono-sm text-fg-tertiary">{item.sku}</p>
                            </div>
                            <div className="col-start-2 flex min-w-0 flex-col items-start gap-1 sm:col-start-auto">
                                {item.quantity_returned > 0 ? (
                                    <ItemChip icon={<ArrowUUpLeftIcon size={14} aria-hidden className="text-feedback-danger" />} label={t('orders.items.returned', { count: item.quantity_returned })} />
                                ) : delivered ? (
                                    <ItemChip icon={<CheckCircleIcon size={14} aria-hidden className="text-feedback-success" />} label={t('orders.status.delivered')} />
                                ) : item.quantity_shipped > 0 ? (
                                    <ItemChip icon={<TruckIcon size={14} aria-hidden className="text-feedback-info" />} label={t('orders.items.shipped', { count: item.quantity_shipped })} />
                                ) : null}
                                {item.cost_price_snapshot !== null && <p className="t-admin-caption tabular text-fg-tertiary">{t('orders.items.cost', { amount: takaText(item.cost_price_snapshot) })}</p>}
                            </div>
                            <p className="col-start-3 row-start-1 flex items-baseline gap-4 justify-self-end t-admin-num tabular whitespace-nowrap text-fg-primary sm:col-start-4">
                                <span className="text-fg-secondary">
                                    <Taka amount={item.unit_price} /> × {item.quantity}
                                </span>
                                <span className="min-w-18 text-end">
                                    <Taka amount={item.line_total} />
                                </span>
                            </p>
                        </li>
                    );
                })}
            </ul>
            <dl className="ms-auto flex w-full max-w-86 flex-col">
                <TotalRow label={t('orders.totals.subtotal')} amount={tot.subtotal} />
                <TotalRow label={tot.coupon_code ? t('orders.totals.discountCoupon', { code: tot.coupon_code }) : t('orders.totals.discount')} amount={tot.discount_total} negative />
                <TotalRow label={t('orders.totals.delivery')} amount={tot.shipping_charge} />
                {!isZeroAmount(tot.shipping_discount) && <TotalRow label={t('orders.totals.deliveryDiscount')} amount={tot.shipping_discount} negative />}
                <TotalRow label={t('orders.totals.grand')} amount={tot.grand_total} strong />
                <TotalRow label={t('orders.totals.paid')} amount={tot.paid_total} />
                {!isZeroAmount(tot.refunded_total) && <TotalRow label={t('orders.totals.refunded')} amount={tot.refunded_total} />}
                <TotalRow label={t('orders.totals.due')} amount={tot.due_total} strong warn={order.anomaly === 'delivered_unpaid'} />
            </dl>
        </section>
    );
}

function ItemChip({ icon, label }: { icon: ReactNode; label: string }) {
    return (
        <span className="inline-flex h-6 items-center gap-1 rounded-xs border border-line-default bg-subtle px-2 t-admin-caption-strong whitespace-nowrap text-fg-primary">
            {icon}
            {label}
        </span>
    );
}

function TotalRow({ label, amount, strong, negative, warn }: { label: string; amount: string; strong?: boolean; negative?: boolean; warn?: boolean }) {
    return (
        <div className={`flex items-center justify-between gap-2 py-1.5 ${strong ? 'border-t border-line-default' : ''}`}>
            <dt className={strong ? 't-admin-body-strong text-fg-primary' : 't-admin-body text-fg-secondary'}>{label}</dt>
            <dd className="flex items-center gap-2 t-admin-num tabular text-fg-primary">
                {warn && <WarningIcon size={14} aria-hidden className="text-status-low-stock" />}
                <span>
                    {negative && !isZeroAmount(amount) ? '−' : ''}
                    <Taka amount={amount} />
                </span>
            </dd>
        </div>
    );
}

/** Online payment ids for reconciliation (P4-PAY-03): bank / validation ids, why it failed, review flag. */
function GatewayLine({ payment }: { payment: Order['payments'][number] }) {
    const { t } = useI18n();
    const ids = [payment.gateway_reference, payment.val_id].filter((v): v is string => Boolean(v));
    return (
        <>
            {ids.length > 0 && <p className="t-admin-mono-sm text-fg-tertiary">{ids.join(' · ')}</p>}
            {payment.failure_reason && payment.status !== 'paid' && (
                <p className="t-admin-caption text-fg-secondary">{t('orders.gateway.reason', { reason: payment.failure_reason })}</p>
            )}
            {payment.review_reason && (
                <p className="flex items-center gap-1 t-admin-caption-strong text-feedback-danger">
                    <WarningIcon size={14} aria-hidden />
                    {t('orders.gateway.review', { reason: payment.review_reason })}
                </p>
            )}
        </>
    );
}

function PaymentsCard({ order }: { order: Order }) {
    const { t, locale } = useI18n();
    const verify = useVerifyPayment(order.id);
    const toast = useToast();
    return (
        <section className={card} aria-labelledby="order-payments">
            <CardHeader id="order-payments" title={t('orders.payments.title')} />
            {order.payments.length === 0 ? (
                <p className="rounded-sm bg-subtle px-4 py-3 t-admin-body text-fg-secondary">
                    {order.payment_method === 'cod' ? t('orders.payments.noneCod') : t('orders.payments.none')}
                </p>
            ) : (
                <ul className="flex flex-col gap-2">
                    {order.payments.map((p) => {
                        const at = p.paid_at ? dhakaParts(p.paid_at, locale) : null;
                        return (
                            <li key={p.id} className="flex flex-wrap items-center gap-3 rounded-sm bg-subtle px-3.5 py-3">
                                <PaymentChip method={p.method} />
                                <div className="min-w-0 flex-1">
                                    <p className="t-admin-body text-fg-primary">
                                        {t(`orders.paymentRecord.${p.status}` as MessageKey)}
                                        {at && ` · ${at.day}, ${at.time}`}
                                    </p>
                                    {p.transaction_id && <p className="t-admin-mono-sm text-fg-tertiary">{p.transaction_id}</p>}
                                    {p.gateway && <GatewayLine payment={p} />}
                                </div>
                                {p.gateway && (
                                    <span className="inline-flex h-5.5 items-center gap-1 rounded-xs border border-line-default bg-surface px-2 whitespace-nowrap t-admin-caption-strong text-fg-secondary">
                                        {t(p.gateway.verified ? 'orders.gateway.verified' : 'orders.gateway.unverified')}
                                        {p.gateway.sandbox && ` · ${t('orders.gateway.sandbox')}`}
                                    </span>
                                )}
                                {p.gateway && ['pending', 'initiated', 'authorised'].includes(p.status) && (
                                    <Button variant="ghost" size="sm" disabled={verify.isPending} onClick={() => verify.mutate(p.id, { onError: (e) => toast.notify(problemText(e, t('error.title')), 'danger') })}>
                                        {t('orders.gateway.recheck')}
                                    </Button>
                                )}
                                <p className="t-admin-num tabular text-fg-primary">
                                    <Taka amount={p.amount} />
                                </p>
                            </li>
                        );
                    })}
                </ul>
            )}
        </section>
    );
}

type FeedEntry = { key: string; at: string | null; actor: string | null; sentence: string; detail?: string | null; customerVisible: boolean; note: boolean };

function ActivityCard({ order }: { order: Order }) {
    const { t, locale } = useI18n();
    const toast = useToast();
    const addNote = useAddNote(order.id);
    const [filter, setFilter] = useState<'all' | 'notes' | 'changes'>('all');
    const [body, setBody] = useState('');
    const [visible, setVisible] = useState(false);
    const [error, setError] = useState<string>();
    const canNote = order.available_actions.includes('add_note');

    const entries = useMemo<FeedEntry[]>(() => {
        const label = (field: string | null, value: string | null) => {
            if (!value) return '—';
            if (field === 'status') return t(statusLabel(value as Order['status']));
            if (field === 'payment_status') return t(paymentLabel(value as Order['payment_status']));
            if (field === 'fulfillment_status') return t(`orders.fulfillment.${value}` as MessageKey);
            return value;
        };
        const events: FeedEntry[] = order.timeline.map((e) => {
            const actor = e.user_name;
            let sentence: string;
            if (e.type === 'placed') sentence = t('orders.activity.placed');
            else if (e.type === 'note') sentence = t('orders.activity.noteEvent');
            else
                sentence = t(`orders.activity.${e.type}` as MessageKey, {
                    actor: actor ?? t('orders.activity.system'),
                    from: label(e.field, e.from),
                    to: label(e.field, e.to),
                });
            return { key: `e${e.id}`, at: e.created_at, actor, sentence, detail: e.description, customerVisible: e.is_customer_visible, note: e.type === 'note' };
        });
        const notes: FeedEntry[] = order.notes.map((n) => ({
            key: `n${n.id}`,
            at: n.created_at,
            actor: n.user_name,
            sentence: t('orders.activity.note', { actor: n.user_name ?? t('orders.activity.system') }),
            detail: n.body,
            customerVisible: n.is_customer_visible,
            note: true,
        }));
        return [...events, ...notes]
            .filter((x) => filter === 'all' || (filter === 'notes' ? x.note : !x.note))
            .sort((a, b) => (b.at ?? '').localeCompare(a.at ?? '') || b.key.localeCompare(a.key));
    }, [order, filter, t]);

    const submit = () => {
        const parsed = storeOrderNoteRequestSchema.safeParse({ body: body.trim(), is_customer_visible: visible });
        if (!parsed.success) {
            setError(parsed.error.issues[0]?.message);
            return;
        }
        setError(undefined);
        addNote.mutate(parsed.data, {
            onSuccess: () => {
                setBody('');
                setVisible(false);
                toast.notify(t('orders.activity.noteAdded'));
            },
            onError: (e) => toast.notify(problemText(e, t('error.title')), 'danger'),
        });
    };

    let lastDay = '';
    return (
        <section className="flex flex-col overflow-hidden rounded-md border border-line-default bg-surface shadow-e1" aria-labelledby="order-activity">
            <div className="flex items-center justify-between gap-3 px-4 py-3">
                <h2 id="order-activity" className="t-admin-section text-fg-primary">
                    {t('orders.activity.title')}
                </h2>
                <label className="flex">
                    <span className="sr-only">{t('orders.activity.filter')}</span>
                    <Select size="sm" className="w-30" value={filter} onChange={(e) => setFilter(e.target.value as typeof filter)}>
                        <option value="all">{t('common.all')}</option>
                        <option value="notes">{t('orders.activity.filterNotes')}</option>
                        <option value="changes">{t('orders.activity.filterChanges')}</option>
                    </Select>
                </label>
            </div>
            {entries.length === 0 ? (
                <p className="border-t border-line-default px-4 py-6 text-center t-admin-body text-fg-secondary">{t('orders.activity.empty')}</p>
            ) : (
                <ol>
                    {entries.map((entry) => {
                        const parts = entry.at ? dhakaParts(entry.at, locale) : null;
                        const day = parts?.dayKey ?? '';
                        const separator = day !== lastDay;
                        lastDay = day;
                        return (
                            <li key={entry.key}>
                                {separator && parts && <p className="bg-subtle px-4 py-2 t-admin-overline text-fg-tertiary">{parts.dayYear}</p>}
                                <div className="flex gap-3 border-b border-line-default px-4 py-3">
                                    {entry.actor ? (
                                        <Avatar name={entry.actor} />
                                    ) : (
                                        <span aria-hidden className="grid size-8 shrink-0 place-items-center rounded-md bg-subtle text-fg-secondary">
                                            {entry.note ? <NoteIcon size={16} /> : <GearIcon size={16} />}
                                        </span>
                                    )}
                                    <div className="flex min-w-0 flex-1 flex-col gap-1">
                                        <p className="t-admin-body text-fg-primary">{entry.sentence}</p>
                                        {entry.detail && <p className="rounded-sm bg-subtle px-2 py-1.5 t-admin-body whitespace-pre-line text-fg-primary">{entry.detail}</p>}
                                        <p className="flex flex-wrap items-center gap-x-3 t-admin-caption text-fg-tertiary">
                                            {parts && <span className="tabular">{parts.time}</span>}
                                            {entry.customerVisible && (
                                                <span className="inline-flex items-center gap-1 text-fg-primary">
                                                    <EyeIcon size={12} aria-hidden className="text-feedback-info" />
                                                    {t('orders.activity.customerVisible')}
                                                </span>
                                            )}
                                        </p>
                                    </div>
                                </div>
                            </li>
                        );
                    })}
                </ol>
            )}
            {canNote && (
                <div className="flex flex-col gap-3 px-4 py-4">
                    <label className="flex flex-col gap-1.5">
                        <span className="sr-only">{t('orders.activity.noteLabel')}</span>
                        <textarea
                            rows={3}
                            maxLength={2000}
                            placeholder={t('orders.activity.notePlaceholder')}
                            aria-invalid={error ? true : undefined}
                            className={`py-2 ${inputClass} ${visible ? 'border-feedback-info' : ''}`}
                            value={body}
                            onChange={(e) => setBody(e.target.value)}
                        />
                        {error && <span className="t-admin-caption text-fg-primary">{error}</span>}
                    </label>
                    <div className="flex items-center justify-between gap-3">
                        <label className="inline-flex items-center gap-2 t-admin-body text-fg-secondary">
                            <button
                                type="button"
                                role="switch"
                                aria-checked={visible}
                                onClick={() => setVisible(!visible)}
                                className={`relative inline-flex h-5 w-9 shrink-0 items-center rounded-full p-0.5 transition-colors duration-150 ease-standard ${visible ? 'bg-brand' : 'bg-line-strong'}`}
                            >
                                <span aria-hidden className={`size-4 rounded-full bg-surface shadow-e1 transition-transform duration-150 ease-standard ${visible ? 'translate-x-4' : 'translate-x-0'}`} />
                            </button>
                            {t('orders.activity.visibleToCustomer')}
                        </label>
                        <Button size="sm" variant="secondary" onClick={submit} disabled={addNote.isPending}>
                            {t('orders.activity.addNote')}
                        </Button>
                    </div>
                </div>
            )}
        </section>
    );
}

function CustomerCard({ order }: { order: Order }) {
    const { t } = useI18n();
    const toast = useToast();
    const c = order.customer;
    const copy = (text: string) => {
        void navigator.clipboard?.writeText(text).then(() => toast.notify(t('orders.customer.copied')));
    };

    return (
        <section className={card} aria-labelledby="order-customer">
            <CardHeader id="order-customer" title={t('orders.customer.title')} />
            <div className="flex items-center gap-3">
                <span aria-hidden className="grid size-14 shrink-0 place-items-center rounded-full border border-line-default font-display-latin text-base/none font-bold text-fg-primary">
                    {initials(c.name)}
                </span>
                <div className="min-w-0 flex-1">
                    <p className="truncate t-admin-body-strong text-fg-primary">{c.name}</p>
                    <p className="t-admin-num tabular text-fg-secondary">{c.phone}</p>
                </div>
                {!order.pii_masked && (
                    <div className="flex gap-1">
                        <IconButton size="sm" aria-label={t('orders.customer.copyPhone')} onClick={() => copy(c.phone)}>
                            <CopyIcon size={16} aria-hidden />
                        </IconButton>
                        <a href={`tel:${c.phone}`} aria-label={t('orders.customer.call')} className="inline-grid size-8 place-items-center rounded-sm text-fg-primary hover:bg-subtle">
                            <PhoneIcon size={16} aria-hidden />
                        </a>
                    </div>
                )}
            </div>
            <p className="t-admin-caption tabular text-fg-secondary">
                {t('orders.customer.history', { orders: c.orders_count, delivered: c.delivered_orders_count, value: takaText(c.total_spent) })}
            </p>
            {order.pii_masked && <p className="t-admin-caption text-fg-tertiary">{t('orders.customer.masked')}</p>}
        </section>
    );
}

function AddressCard({ order }: { order: Order }) {
    const { t } = useI18n();
    const toast = useToast();
    const s = order.shipping;
    const chain = [s.division, s.district, s.upazila, s.area].filter(Boolean).join(' → ');
    const oneLine = [s.name, s.phone, s.address, s.landmark, s.area, s.upazila, s.district, s.postcode].filter(Boolean).join(', ');

    return (
        <section className={card} aria-labelledby="order-address">
            <CardHeader
                id="order-address"
                title={t('orders.address.title')}
                action={
                    !order.pii_masked && (
                        <Button variant="ghost" size="sm" onClick={() => void navigator.clipboard?.writeText(oneLine).then(() => toast.notify(t('orders.address.copied')))}>
                            <CopyIcon size={16} aria-hidden />
                            {t('orders.address.copy')}
                        </Button>
                    )
                }
            />
            <p className="t-admin-body tabular text-fg-primary">{[s.name, s.phone].join(' · ')}</p>
            {s.address && <p className="t-admin-body text-fg-primary">{[s.address, s.postcode].filter(Boolean).join(', ')}</p>}
            {s.landmark && (
                <p className="flex items-start gap-1.5 t-admin-body-strong text-fg-primary">
                    <MapPinIcon size={14} aria-hidden className="mt-1 shrink-0 text-feedback-danger" />
                    {s.landmark}
                </p>
            )}
            {order.pii_masked && <p className="t-admin-caption text-fg-tertiary">{t('orders.address.masked')}</p>}
            {chain && <p className="rounded-sm bg-subtle px-2.5 py-1.5 t-admin-caption text-fg-secondary">{chain}</p>}
        </section>
    );
}

type Signal = { code: string; weight: number };

function readFlags(flags: Order['risk']['flags']): { signals: Signal[]; holds: string[]; computedAt: string | null } {
    const signals = Array.isArray(flags?.signals)
        ? (flags.signals as unknown[]).flatMap((s) => (s && typeof s === 'object' && 'code' in s && 'weight' in s && typeof s.code === 'string' && typeof s.weight === 'number' ? [{ code: s.code, weight: s.weight }] : []))
        : [];
    const holds = Array.isArray(flags?.hold_reasons) ? (flags.hold_reasons as unknown[]).filter((h): h is string => typeof h === 'string') : [];
    const computedAt = typeof flags?.computed_at === 'string' ? flags.computed_at : null;
    return { signals, holds, computedAt };
}

const knownSignals = ['first_order'];
const knownHolds = ['high_risk_score', 'first_order_above_threshold', 'cod_advance_required'];

/** The 220° arc gauge: arc colour is reinforcement only — the number and the word carry it. */
function Gauge({ score, level }: { score: number; level: RiskLevel }) {
    const { t } = useI18n();
    const r = 44;
    const sweep = 220;
    const length = (Math.PI * 2 * r * sweep) / 360;
    const stroke = { low: 'stroke-feedback-success', medium: 'stroke-status-low-stock', high: 'stroke-feedback-danger' }[level];
    return (
        <div className="relative size-24 shrink-0">
            <svg viewBox="0 0 96 96" className="size-24 rotate-[160deg]" aria-hidden>
                <circle cx="48" cy="48" r={r} fill="none" strokeWidth="8" strokeLinecap="round" className="stroke-subtle" strokeDasharray={`${length} 999`} />
                <circle cx="48" cy="48" r={r} fill="none" strokeWidth="8" strokeLinecap="round" className={stroke} strokeDasharray={`${(length * Math.min(100, Math.max(0, score))) / 100} 999`} />
            </svg>
            <p className="absolute inset-0 flex flex-col items-center justify-center">
                <span className="t-admin-num-lg tabular text-fg-primary">{score}</span>
                <span className="t-admin-caption text-fg-tertiary">{t('orders.risk.of100')}</span>
            </p>
        </div>
    );
}

/** Figma RiskScoreCard / Compact 197:995, panel A: OUR assessment — our score and its breakdown. */
function RiskCard({ order }: { order: Order }) {
    const { t, locale } = useI18n();
    const { score, level, verification_status: verification, flags } = order.risk;
    const { signals, holds, computedAt } = readFlags(flags);
    const lvl = (level === 'low' || level === 'medium' || level === 'high' ? level : null) as RiskLevel | null;
    const when = computedAt ? dhakaParts(computedAt, locale) : null;

    return (
        <section className={`${card} border-t-3 border-t-brand`} aria-labelledby="order-risk">
            <div className="flex flex-col gap-1">
                <p className="t-admin-overline text-fg-secondary">{t('orders.risk.eyebrow')}</p>
                <h2 id="order-risk" className="t-admin-section text-fg-primary">
                    {t('orders.risk.title')}
                </h2>
                <p className="flex items-center gap-1.5 t-admin-caption text-fg-tertiary">
                    <CpuIcon size={14} aria-hidden />
                    {when ? t('orders.risk.provenance', { day: when.dayYear, time: when.time }) : t('orders.risk.provenanceNone')}
                </p>
            </div>
            {score === null || lvl === null ? (
                <p className="t-admin-body text-fg-secondary">{t('orders.risk.notScored')}</p>
            ) : (
                <>
                    <div className="flex items-center gap-4">
                        <Gauge score={score} level={lvl} />
                        <div className="flex min-w-0 flex-col items-start gap-2">
                            <RiskLevelChip level={lvl} score={score} />
                            <p className="t-admin-body text-fg-primary">{holds.length > 0 ? t('orders.risk.verdictHold') : signals.length > 0 ? t('orders.risk.verdictSignals') : t('orders.risk.verdictClean')}</p>
                        </div>
                    </div>
                    <ul className="flex flex-col">
                        {signals.map((s) => (
                            <li key={s.code} className="flex items-center justify-between gap-3 border-t border-line-default py-2">
                                <span className="t-admin-body text-fg-primary">{knownSignals.includes(s.code) ? t(`orders.risk.signal.${s.code}` as MessageKey) : s.code}</span>
                                <span className="t-admin-num tabular text-fg-primary">{s.weight}</span>
                            </li>
                        ))}
                        <li className="flex items-center justify-between gap-3 border-t border-line-strong py-2">
                            <span className="t-admin-body-strong text-fg-primary">{t('orders.risk.total')}</span>
                            <span className="t-admin-num tabular text-fg-primary">{t('orders.risk.outOf', { score })}</span>
                        </li>
                    </ul>
                </>
            )}
            {holds.length > 0 && (
                <ul className="flex flex-col gap-1">
                    {holds.map((h) => (
                        <li key={h} className="flex items-start gap-1.5 t-admin-caption text-fg-primary">
                            <WarningIcon size={14} aria-hidden className="mt-px shrink-0 text-status-low-stock" />
                            {knownHolds.includes(h) ? t(`orders.risk.hold.${h}` as MessageKey) : h}
                        </li>
                    ))}
                </ul>
            )}
            <p className="t-admin-caption text-fg-secondary">
                {t(`orders.risk.verification.${verification}` as MessageKey)} {t('orders.risk.policy')}
            </p>
        </section>
    );
}

function RiskLevelChip({ level, score }: { level: RiskLevel; score: number }) {
    const { t } = useI18n();
    const { icon: Icon, edge, tint } = riskTint(level);
    return (
        <span className={`inline-flex h-7 items-center gap-2 rounded-sm border-l-2 bg-subtle px-3 t-admin-body-strong whitespace-nowrap text-fg-primary ${edge}`}>
            <Icon size={16} aria-hidden className={tint} />
            {t(`orders.risk.chip.${level}` as MessageKey, { score })}
        </span>
    );
}

/**
 * Panel B: EXTERNAL evidence. No courier account exists yet (rule 33b), so this is the honest
 * not-connected state — never a zero, never merged with our score (rule 24).
 */
function CourierEvidenceCard() {
    const { t } = useI18n();
    return (
        <section className="flex flex-col gap-4 rounded-md border border-dashed border-line-strong border-t-3 border-t-fg-secondary bg-surface p-5" aria-labelledby="order-courier-evidence">
            <div className="flex flex-col gap-1">
                <p className="t-admin-overline text-fg-secondary">{t('orders.evidence.eyebrow')}</p>
                <h2 id="order-courier-evidence" className="t-admin-section text-fg-primary">
                    {t('orders.evidence.title')}
                </h2>
                <p className="flex items-center gap-1.5 t-admin-caption text-fg-tertiary">
                    <ShieldIcon size={14} aria-hidden />
                    {t('orders.evidence.provenance')}
                </p>
            </div>
            <div className="flex flex-col items-center gap-3 rounded-sm bg-subtle px-5 py-6 text-center">
                <PlugsIcon size={32} aria-hidden className="text-fg-tertiary" />
                <p className="t-admin-section text-fg-primary">{t('orders.evidence.notConnected')}</p>
                <p className="t-admin-body text-fg-secondary">{t('orders.evidence.body')}</p>
                <p className="border-t border-line-default pt-3 t-admin-caption text-fg-tertiary">{t('orders.evidence.connect')}</p>
            </div>
        </section>
    );
}
