import { Fragment } from 'react';
import { CheckIcon, GitBranchIcon, MinusIcon, PauseIcon, XIcon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import type { Order, OrderStatus, TimelineEntry } from './api';
import { dhakaParts, takaText } from './format';

export type NodeState = 'done' | 'current' | 'upcoming' | 'skipped' | 'failed';
export type StepNode = { key: string; label: MessageKey; state: NodeState; at?: string | null; sub?: string; branch?: boolean; held?: boolean };
type Track = { id: 'workflow' | 'money' | 'goods'; source: string; nodes: StepNode[] };

const happy: OrderStatus[] = ['pending', 'confirmed', 'processing', 'ready_to_ship', 'shipped', 'delivered', 'completed'];

/** When `field` last moved to `value`, from the order's own event log. */
function reached(timeline: TimelineEntry[], field: string, value: string): string | null {
    for (let i = timeline.length - 1; i >= 0; i--) {
        const e = timeline[i]!;
        if (e.field === field && e.to === value) return e.created_at;
    }
    return null;
}

/** Lay a fixed sequence out against a position: before it done, at it current, after it upcoming. */
function line(keys: { key: string; label: MessageKey; at?: string | null; sub?: string }[], position: number, finished = false): StepNode[] {
    return keys.map((k, i) => ({ ...k, state: i < position || (finished && i === position) ? 'done' : i === position ? 'current' : 'upcoming' }));
}

/**
 * Figma OrderStatusStepper 194:683 — three tracks, never one sequence. Node states are read
 * straight off the order's three status columns and its event log; nothing is inferred
 * about money beyond what the server says (settlement is Phase 6, so it is never "done").
 */
export function buildTracks(order: Order): Track[] {
    const tl = order.timeline;
    const status = order.status;
    const placed = order.placed_at;
    const at = (s: OrderStatus) => (s === 'pending' ? placed : reached(tl, 'status', s));

    // Workflow — on hold sits on the step it was held from; branches bend off the happy path.
    let position: number;
    let branch: StepNode | null = null;
    let held = false;
    if (status === 'on_hold') {
        let from: string | null = null;
        for (let i = tl.length - 1; i >= 0; i--) {
            if (tl[i]!.field === 'status' && tl[i]!.to === 'on_hold') {
                from = tl[i]!.from;
                break;
            }
        }
        position = Math.max(0, happy.indexOf((from ?? 'pending') as OrderStatus));
        held = true;
    } else if (status === 'cancelled' || status === 'returned' || status === 'partially_returned') {
        let from: string | null = null;
        for (let i = tl.length - 1; i >= 0; i--) {
            if (tl[i]!.field === 'status' && tl[i]!.to === status) {
                from = tl[i]!.from;
                break;
            }
        }
        const fallback = status === 'cancelled' ? 'pending' : 'shipped';
        position = happy.indexOf((from && happy.includes(from as OrderStatus) ? from : fallback) as OrderStatus);
        branch = { key: status, label: `orders.status.${status}` as MessageKey, state: status === 'cancelled' ? 'failed' : 'current', at: at(status), branch: true };
    } else {
        position = happy.indexOf(status);
    }
    const workflow: StepNode[] = happy.map((s, i) => {
        const node: StepNode = { key: s, label: `orders.status.${s}` as MessageKey, state: 'upcoming', at: at(s) };
        if (branch) node.state = i <= position ? 'done' : 'skipped';
        else node.state = i < position ? 'done' : i === position ? (status === 'completed' ? 'done' : 'current') : 'upcoming';
        if (node.state === 'upcoming' || node.state === 'skipped') node.at = null;
        if (held && i === position) node.held = true;
        return node;
    });
    if (branch) {
        // Returns leave Completed still ahead; a cancellation ends the track.
        const cut = status === 'cancelled' ? workflow.length : workflow.length - 1;
        workflow.splice(cut, 0, branch);
        if (status !== 'cancelled') workflow[workflow.length - 1]!.state = 'upcoming';
    }

    // Money — COD: unpaid → collected by courier → settled to us. Prepaid: pending → paid.
    const cod = order.payment_method === 'cod';
    const pay = order.payment_status;
    const payAt = (s: string) => reached(tl, 'payment_status', s);
    let money: StepNode[];
    if (cod) {
        const seq = [
            { key: 'unpaid', label: 'orders.step.unpaid' as MessageKey, at: placed },
            { key: 'collected', label: 'orders.step.collected' as MessageKey, at: payAt('paid') ?? payAt('partially_paid'), sub: pay === 'partially_paid' ? takaText(order.totals.paid_total) : undefined },
            { key: 'settled', label: 'orders.step.settled' as MessageKey },
        ];
        const pos = pay === 'paid' || pay === 'partially_refunded' || pay === 'refunded' ? 2 : pay === 'partially_paid' ? 1 : 0;
        money = line(seq, pos);
        // Settlement is not tracked until couriers land (Phase 6): never claim it.
        if (pos === 2) money[2]!.state = 'upcoming';
        if (pos === 2) money[1]!.state = 'done';
    } else {
        const seq = [
            { key: 'pending', label: 'orders.step.paymentPending' as MessageKey, at: placed },
            { key: 'paid', label: 'orders.step.paid' as MessageKey, at: payAt('paid') },
        ];
        const pos = pay === 'paid' || pay === 'partially_refunded' || pay === 'refunded' || pay === 'partially_paid' ? 1 : 0;
        money = line(seq, pos, pay === 'paid' || pay === 'partially_refunded' || pay === 'refunded');
    }
    if (pay === 'failed') money = [{ ...money[0]!, state: 'done' }, { key: 'failed', label: 'orders.step.failed', state: 'failed', at: payAt('failed'), branch: true }];
    if (pay === 'refunded' || pay === 'partially_refunded') {
        money.push({ key: pay, label: `orders.payment.${pay}` as MessageKey, state: 'current', at: payAt(pay), sub: takaText(order.totals.refunded_total), branch: true });
    }

    // Goods — not sent → packed → handed to courier → all items delivered.
    const ful = order.fulfillment_status;
    const fAt = (s: string) => reached(tl, 'fulfillment_status', s);
    const goodsSeq = [
        { key: 'not_sent', label: 'orders.step.notSent' as MessageKey, at: placed },
        { key: 'packed', label: 'orders.step.packed' as MessageKey, at: reached(tl, 'status', 'ready_to_ship') },
        { key: 'handed', label: 'orders.step.handed' as MessageKey, at: fAt('fulfilled') ?? reached(tl, 'status', 'shipped') },
        { key: 'delivered', label: 'orders.step.allDelivered' as MessageKey, at: reached(tl, 'status', 'delivered') },
    ];
    let goods: StepNode[];
    if (ful === 'partially_fulfilled' || ful === 'returned') {
        goods = goodsSeq.slice(0, 3).map((n) => ({ ...n, state: 'done' as NodeState }));
        goods.push({ key: ful, label: ful === 'returned' ? 'orders.goods.returned' : 'orders.step.partlyDelivered', state: 'current', at: fAt(ful), branch: true });
    } else {
        const shippedish = status === 'shipped';
        const deliveredish = status === 'delivered' || status === 'completed';
        const pos = ful === 'fulfilled' ? (deliveredish ? 3 : shippedish ? 2 : 2) : status === 'ready_to_ship' ? 1 : 0;
        goods = line(goodsSeq, pos, deliveredish && ful === 'fulfilled');
        if (status === 'cancelled') goods = goods.map((n, i) => (i === 0 ? { ...n, state: 'done' } : { ...n, state: 'skipped', at: null }));
    }
    for (const n of [...money, ...goods]) if (n.state === 'upcoming' || n.state === 'skipped') n.at = null;

    return [
        { id: 'workflow', source: 'orders.status', nodes: workflow },
        { id: 'money', source: 'payment_status', nodes: money },
        { id: 'goods', source: 'fulfillment_status', nodes: goods },
    ];
}

function Dot({ state, held }: { state: NodeState; held?: boolean }) {
    switch (state) {
        case 'done':
            return (
                <span className="grid size-5 place-items-center rounded-full bg-feedback-success text-fg-on-brand">
                    <CheckIcon size={12} weight="bold" aria-hidden />
                </span>
            );
        case 'current':
            return (
                <span className="grid size-6 place-items-center rounded-full bg-brand text-fg-on-brand ring-3 ring-brand-subtle">
                    {held ? <PauseIcon size={12} weight="fill" aria-hidden /> : <span className="size-2 rounded-full bg-surface" />}
                </span>
            );
        case 'failed':
            return (
                <span className="grid size-5 place-items-center rounded-full bg-feedback-danger text-fg-on-danger">
                    <XIcon size={12} weight="bold" aria-hidden />
                </span>
            );
        case 'skipped':
            return (
                <span className="grid size-5 place-items-center rounded-full border border-dashed border-line-strong bg-subtle text-fg-tertiary">
                    <MinusIcon size={12} aria-hidden />
                </span>
            );
        default:
            return <span className="size-5 rounded-full border border-line-default bg-subtle" />;
    }
}

function connector(from: NodeState, to: StepNode): string {
    if (to.state === 'failed' || (to.branch && to.state !== 'upcoming')) return 'border-t-2 border-feedback-danger';
    if (to.state === 'upcoming' || to.state === 'skipped') return 'border-t-2 border-dashed border-line-default';
    if (to.state === 'current') return 'border-t-2 border-brand';
    return from === 'done' ? 'border-t-2 border-feedback-success' : 'border-t-2 border-dashed border-line-default';
}

/** The stepper card: horizontal from md up, vertical (dot rail · label · time) below. */
export function OrderStepper({ order }: { order: Order }) {
    const { t, locale } = useI18n();
    const tracks = buildTracks(order);
    const when = (iso: string) => {
        const p = dhakaParts(iso, locale);
        return t('orders.step.at', { day: p.day, time: p.time });
    };

    return (
        <section aria-labelledby="order-stepper" className="flex flex-col gap-5 rounded-md border border-line-default bg-surface p-5 shadow-e1">
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <h2 id="order-stepper" className="t-admin-section text-fg-primary">
                    {t('orders.step.title')}
                </h2>
                <p className="t-admin-caption text-fg-tertiary">{t('orders.step.hint')}</p>
            </div>
            {tracks.map((track) => (
                <div key={track.id} className="flex flex-col gap-3">
                    <p className="flex items-center gap-2">
                        <span className="t-admin-overline text-fg-secondary">{t(`orders.step.track.${track.id}` as MessageKey)}</span>
                        <span className="t-admin-mono-sm text-fg-tertiary">{track.source}</span>
                    </p>
                    <ol className="flex flex-col gap-0 lg:flex-row lg:items-start">
                        {track.nodes.map((node, i) => {
                            const prev = track.nodes[i - 1];
                            const label = node.held ? `${t(node.label)} · ${t('orders.status.on_hold')}` : t(node.label);
                            const muted = node.state === 'upcoming' || node.state === 'skipped';
                            return (
                                <Fragment key={node.key}>
                                    <li
                                        className="relative flex min-w-0 flex-1 gap-3 pb-3 lg:flex-col lg:items-start lg:gap-1.5 lg:pb-0"
                                        aria-label={`${label} — ${t(`orders.step.state.${node.state}` as MessageKey)}`}
                                    >
                                        <div className="relative flex h-6 w-6 shrink-0 items-center justify-center lg:w-full lg:justify-start">
                                            {prev && <span aria-hidden className={`absolute end-full top-1/2 hidden w-[calc(100%-1.5rem)] -translate-y-px lg:block ${connector(prev.state, node)}`} />}
                                            <Dot state={node.state} held={node.held} />
                                        </div>
                                        <div className="min-w-0 pe-2">
                                            <p className={`flex items-center gap-1 t-admin-caption-strong ${muted ? 'text-fg-tertiary' : 'text-fg-primary'}`}>
                                                {node.branch && <GitBranchIcon size={14} aria-hidden className="shrink-0 text-feedback-danger" />}
                                                <span className="truncate">{label}</span>
                                            </p>
                                            {(node.at || node.sub) && (
                                                <p className="t-admin-caption tabular text-fg-tertiary">{[node.sub, node.at ? when(node.at) : null].filter(Boolean).join(' · ')}</p>
                                            )}
                                        </div>
                                    </li>
                                </Fragment>
                            );
                        })}
                    </ol>
                </div>
            ))}
        </section>
    );
}
