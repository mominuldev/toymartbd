import type { ComponentType, ReactNode } from 'react';
import {
    ArrowClockwiseIcon,
    ArrowUUpLeftIcon,
    BankIcon,
    CheckCircleIcon,
    CheckIcon,
    ChecksIcon,
    ClockIcon,
    CreditCardIcon,
    DeviceMobileIcon,
    InfoIcon,
    MoneyIcon,
    PackageIcon,
    PauseIcon,
    ProhibitIcon,
    ShieldCheckIcon,
    ShieldWarningIcon,
    TruckIcon,
    WarningIcon,
    type IconProps,
} from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import type { Anomaly, FulfillmentStatus, OrderSource, OrderStatus, PaymentStatus, RiskLevel } from './api';
import { takaText } from './format';

type Look = { icon: ComponentType<IconProps>; tint: string };

/** Workflow icon + tint per status (admin spec A12 map). Shape carries meaning; tint reinforces. */
export const workflowLook: Record<OrderStatus, Look> = {
    pending: { icon: ClockIcon, tint: 'text-status-low-stock' },
    on_hold: { icon: PauseIcon, tint: 'text-status-low-stock' },
    confirmed: { icon: CheckIcon, tint: 'text-feedback-info' },
    processing: { icon: ArrowClockwiseIcon, tint: 'text-feedback-info' },
    ready_to_ship: { icon: PackageIcon, tint: 'text-feedback-info' },
    shipped: { icon: TruckIcon, tint: 'text-feedback-info' },
    delivered: { icon: CheckCircleIcon, tint: 'text-feedback-success' },
    completed: { icon: ChecksIcon, tint: 'text-feedback-success' },
    cancelled: { icon: ProhibitIcon, tint: 'text-fg-tertiary' },
    returned: { icon: ArrowUUpLeftIcon, tint: 'text-feedback-danger' },
    partially_returned: { icon: ArrowUUpLeftIcon, tint: 'text-status-low-stock' },
};

export const statusLabel = (status: OrderStatus): MessageKey => `orders.status.${status}` as MessageKey;
export const paymentLabel = (status: PaymentStatus): MessageKey => `orders.payment.${status}` as MessageKey;
export const sourceLabel = (source: OrderSource): MessageKey => `orders.source.${source}` as MessageKey;

type Severity = 'expected' | 'notable' | 'anomaly';
type Slot = { severity: Severity; text: string };

/** The money and goods facts a badge needs — both the list row and the detail carry them. */
export type TriadInput = {
    status: OrderStatus;
    payment_status: PaymentStatus;
    fulfillment_status: FulfillmentStatus;
    anomaly: Anomaly | null;
    paid: string;
    refunded: string;
};

const preShip: OrderStatus[] = ['pending', 'on_hold', 'confirmed', 'processing', 'ready_to_ship'];

/**
 * Admin spec A12: which of the money/goods facts deserve weight. The anomaly itself is the
 * server's verdict (`anomaly`); this only picks wording and emphasis for display.
 */
function moneySlot(o: TriadInput, t: (key: MessageKey, vars?: Record<string, string | number>) => string): Slot {
    if (o.anomaly === 'delivered_unpaid') return { severity: 'anomaly', text: t('orders.anomaly.delivered_unpaid') };
    if (o.anomaly === 'refund_pending') return { severity: 'anomaly', text: t('orders.anomaly.refund_pending', { paid: takaText(o.paid) }) };
    if (o.anomaly === 'payment_failed') return { severity: 'anomaly', text: t('orders.anomaly.payment_failed') };
    switch (o.payment_status) {
        case 'unpaid':
            return { severity: 'expected', text: t('orders.money.unpaid') };
        case 'paid':
            return { severity: 'expected', text: t('orders.money.paid') };
        case 'pending':
            return { severity: 'notable', text: t('orders.money.pending') };
        case 'partially_paid':
            return {
                severity: 'notable',
                text: preShip.includes(o.status) ? t('orders.money.advance', { amount: takaText(o.paid) }) : t('orders.money.partlyPaid', { amount: takaText(o.paid) }),
            };
        case 'partially_refunded':
            return { severity: 'notable', text: t('orders.money.partlyRefunded', { amount: takaText(o.refunded) }) };
        case 'refunded':
            return { severity: o.status === 'cancelled' || o.status === 'returned' ? 'expected' : 'notable', text: t('orders.money.refunded', { amount: takaText(o.refunded) }) };
        case 'failed':
            return { severity: 'anomaly', text: t('orders.anomaly.payment_failed') };
    }
}

function goodsSlot(o: TriadInput, t: (key: MessageKey) => string): Slot {
    if (o.anomaly === 'goods_before_shipping') return { severity: 'anomaly', text: t('orders.anomaly.goods_before_shipping') };
    if (o.anomaly === 'shipped_unfulfilled') return { severity: 'anomaly', text: t('orders.anomaly.shipped_unfulfilled') };
    switch (o.fulfillment_status) {
        case 'unfulfilled':
            return { severity: 'expected', text: t('orders.goods.unfulfilled') };
        case 'partially_fulfilled':
            return { severity: 'expected', text: t('orders.goods.partially_fulfilled') };
        case 'fulfilled':
            return { severity: 'expected', text: o.status === 'delivered' || o.status === 'completed' ? t('orders.goods.delivered') : t('orders.goods.fulfilled') };
        case 'returned':
            return { severity: 'expected', text: t('orders.goods.returned') };
    }
}

const chipBase = 'inline-flex items-center gap-1 rounded-xs bg-subtle px-2 whitespace-nowrap text-fg-primary';

/** Slot 1: the workflow chip — always a full chip (Figma OrderStatusBadge 190:384). */
export function WorkflowChip({ status, size = 'sm' }: { status: OrderStatus; size?: 'sm' | 'lg' }) {
    const { t } = useI18n();
    const { icon: Icon, tint } = workflowLook[status];
    return (
        <span className={`${chipBase} border border-line-default t-admin-caption-strong ${size === 'lg' ? 'h-7' : 'h-5.5'}`}>
            <Icon size={14} aria-hidden className={`shrink-0 ${tint}`} />
            {t(statusLabel(status))}
        </span>
    );
}

function SlotView({ slot, icon: Icon, size }: { slot: Slot; icon: ComponentType<IconProps>; size: 'sm' | 'lg' }) {
    if (slot.severity === 'expected') {
        return (
            <span className={`inline-flex items-center gap-1 t-admin-caption whitespace-nowrap text-fg-tertiary ${size === 'lg' ? 'h-7' : ''}`}>
                <Icon size={12} aria-hidden className="shrink-0" />
                {slot.text}
            </span>
        );
    }
    const anomaly = slot.severity === 'anomaly';
    return (
        <span className={`${chipBase} t-admin-caption-strong ${size === 'lg' ? 'h-7' : 'h-5.5'} ${anomaly ? 'border-l-2 border-feedback-warning' : 'border border-line-default'}`}>
            {anomaly ? <WarningIcon size={14} aria-hidden className="shrink-0 text-feedback-warning" /> : <InfoIcon size={14} aria-hidden className="shrink-0 text-feedback-info" />}
            {slot.text}
        </span>
    );
}

/**
 * Figma OrderStatusBadge 190:384 — fixed triad: workflow · money · goods. `stacked` for table
 * rows, `inline` for the detail header; `compact` (the 768 card) drops expected slots.
 */
export function OrderStatusBadge({ order, form = 'stacked', extra }: { order: TriadInput; form?: 'stacked' | 'inline' | 'compact'; extra?: ReactNode }) {
    const { t } = useI18n();
    const money = moneySlot(order, t);
    const goods = goodsSlot(order, t);
    const size = form === 'inline' ? 'lg' : 'sm';
    const show = (slot: Slot) => form !== 'compact' || slot.severity !== 'expected';

    return (
        <span className={`flex ${form === 'inline' ? 'flex-wrap items-center gap-2' : form === 'compact' ? 'flex-wrap items-center gap-1.5' : 'flex-col items-start gap-1'}`}>
            <WorkflowChip status={order.status} size={size} />
            {/* The 768 OrderCard 228:1891 puts the RiskPill straight after the workflow chip. */}
            {extra}
            {form === 'inline' && <span aria-hidden className="h-4 w-px bg-line-default" />}
            {show(money) && <SlotView slot={money} icon={MoneyIcon} size={size} />}
            {form === 'inline' && <span aria-hidden className="h-4 w-px bg-line-default" />}
            {show(goods) && <SlotView slot={goods} icon={PackageIcon} size={size} />}
        </span>
    );
}

const riskLook: Record<RiskLevel, { icon: ComponentType<IconProps>; edge: string; tint: string }> = {
    low: { icon: ShieldCheckIcon, edge: 'border-feedback-success', tint: 'text-feedback-success' },
    medium: { icon: ShieldWarningIcon, edge: 'border-status-low-stock', tint: 'text-status-low-stock' },
    high: { icon: ShieldWarningIcon, edge: 'border-feedback-danger', tint: 'text-feedback-danger' },
};

/** Figma RiskPill 190:405 — level word + our score, a 2px level edge; never colour alone. */
export function RiskPill({ level, score }: { level: RiskLevel | null; score: number | null }) {
    const { t } = useI18n();
    if (level === null) return <span className="t-admin-caption text-fg-tertiary">{t('orders.risk.none')}</span>;
    const { icon: Icon, edge, tint } = riskLook[level];
    return (
        <span className={`inline-flex h-5.5 items-center gap-1 rounded-xs border-l-2 bg-subtle px-2 t-admin-caption-strong whitespace-nowrap text-fg-primary ${edge}`}>
            <Icon size={14} aria-hidden className={`shrink-0 ${tint}`} />
            {t(`orders.risk.pill.${level}` as MessageKey, { score: score ?? 0 })}
        </span>
    );
}

export const riskTint = (level: RiskLevel) => riskLook[level];

const methodIcons: Record<string, ComponentType<IconProps>> = {
    cod: MoneyIcon,
    cash: MoneyIcon,
    bkash: DeviceMobileIcon,
    nagad: DeviceMobileIcon,
    sslcommerz: CreditCardIcon,
    card: CreditCardIcon,
    bank_transfer: BankIcon,
};
const methods = Object.keys(methodIcons);

export function methodLabel(method: string | null): MessageKey {
    return method && methods.includes(method) ? (`orders.method.${method}` as MessageKey) : 'orders.method.unknown';
}

/** Figma PaymentChip 190:441 — the method, as a neutral chip. */
export function PaymentChip({ method }: { method: string | null }) {
    const { t } = useI18n();
    const Icon = (method && methodIcons[method]) || MoneyIcon;
    return (
        <span className={`${chipBase} h-5.5 border border-line-default t-admin-caption-strong`}>
            <Icon size={14} aria-hidden className="shrink-0 text-fg-secondary" />
            {t(methodLabel(method))}
        </span>
    );
}
