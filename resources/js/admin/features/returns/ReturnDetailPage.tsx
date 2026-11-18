import { useEffect, useMemo, useState, type ComponentType, type ReactNode } from 'react';
import { Link, useParams } from 'react-router';
import {
    CheckCircleIcon,
    CheckIcon,
    HandCoinsIcon,
    HourglassIcon,
    ImageSquareIcon,
    InfoIcon,
    LightningIcon,
    MinusIcon,
    PlusIcon,
    WarningCircleIcon,
    WarningIcon,
    XCircleIcon,
    XIcon,
    type IconProps,
} from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { ApiError } from '@/lib/api';
import { formatDhakaDate } from '@/lib/format';
import { ContentHeader } from '@/components/shell/ContentHeader';
import { Button, buttonClass } from '@/components/ui/Button';
import { Field, inputClass } from '@/components/ui/Field';
import { IconButton } from '@/components/ui/IconButton';
import { Input } from '@/components/ui/Input';
import { QueryError } from '@/components/ui/QueryError';
import { StatusChip } from '@/components/ui/StatusChip';
import { useToast } from '@/components/ui/Toast';
import { PaymentChip } from '@/features/orders';
import { payoutMethods, usePayRefund, useRefundDecision, useReturn, useReturnStep, type OrderReturn, type PayoutMethod, type Refund } from './api';
import { ResolutionRow, Taka, formatMoney, problemText, reasonLabel, refundStatusLabel, refundTone, returnStatusLabel, returnSteps, returnTone, type ReturnStep } from './parts';

const card = 'flex flex-col gap-4 rounded-md border border-line-default bg-surface p-5 shadow-e1';
const bar = 'flex flex-wrap items-center gap-3 rounded-md border border-line-strong bg-surface px-5 py-4 shadow-e1';

type Item = OrderReturn['items'][number];
type Counts = Record<number, { resellable: number; damaged: number }>;

/**
 * One return on its own page (Figma "B · 10 Return detail · Premium" — Inspect 583:50649,
 * Refund to pay 584:50961), one column top to bottom: where the return is (progress + the
 * next thing to do), what it is about, the inspection, the refund, and an action bar with the
 * one main action. Every amount, permission and threshold comes from the API; the page only
 * counts units the staff member typed.
 */
export function ReturnDetailPage() {
    const { t, locale } = useI18n();
    const { returnId } = useParams();
    const id = Number(returnId) || null;
    const detail = useReturn(id);
    const step = useReturnStep();
    const toast = useToast();
    const [rejecting, setRejecting] = useState(false);
    const crumbs = [{ label: t('nav.group.sell') }, { label: t('returns.crumb'), to: '/returns' }];

    if (detail.isError || detail.isPending) {
        return (
            <section className="flex flex-col gap-6">
                <ContentHeader breadcrumb={crumbs} title={t('returns.crumb')} />
                {detail.isError ? (
                    <QueryError error={detail.error} onRetry={() => void detail.refetch()} />
                ) : (
                    <p className="t-admin-body text-fg-tertiary" aria-busy="true">
                        {t('common.loading')}
                    </p>
                )}
            </section>
        );
    }

    const r = detail.data;
    const open = r.status === 'requested' || r.status === 'received';
    const subtitle = [
        r.order.customer_name,
        t('returns.detail.order', { number: r.order.number }),
        r.created_at ? t('returns.detail.opened', { date: formatDhakaDate(r.created_at, locale) }) : null,
    ]
        .filter(Boolean)
        .join(' · ');

    return (
        <section className="flex flex-col gap-6">
            <ContentHeader
                breadcrumb={[...crumbs, { label: r.number }]}
                title={t('returns.detail.title', { number: r.number })}
                subtitle={subtitle}
                actions={
                    <>
                        <Link to={`/returns${r.status === 'requested' ? '' : `?tab=${r.status}`}`} className={buttonClass('outline')}>
                            {t('returns.detail.back')}
                        </Link>
                        {open && r.can.manage && (
                            <Button variant="ghost" onClick={() => setRejecting(true)} disabled={rejecting}>
                                <XIcon size={16} aria-hidden />
                                {t('returns.action.reject')}
                            </Button>
                        )}
                        {r.status === 'requested' && r.can.manage && (
                            <Button
                                variant="outline"
                                disabled={step.isPending}
                                onClick={() =>
                                    step.mutate(
                                        { id: r.id, step: 'receive' },
                                        { onSuccess: () => toast.notify(t('returns.toast.received', { number: r.number })), onError: (e) => toast.notify(problemText(e, t('error.title')), 'danger') },
                                    )
                                }
                            >
                                {t('returns.action.receive')}
                            </Button>
                        )}
                    </>
                }
            />

            <Progress r={r} />
            {rejecting && open && <RejectReturn r={r} onClose={() => setRejecting(false)} />}
            <About r={r} />
            {open ? (
                <Inspect r={r} />
            ) : (
                <>
                    {r.status !== 'rejected' && <InspectionResult r={r} />}
                    {r.refund ? (
                        <RefundSection r={r} refund={r.refund} />
                    ) : (
                        r.status !== 'rejected' && (
                            <section className={card} aria-labelledby="refund-heading">
                                <h2 id="refund-heading" className="t-admin-section text-fg-primary">
                                    {t('returns.refund.title')}
                                </h2>
                                <p className="t-admin-body text-fg-secondary">{t(r.has_refund ? 'returns.resolution.hidden' : 'returns.resolution.none')}</p>
                            </section>
                        )
                    )}
                </>
            )}
        </section>
    );
}

/* ------------------------------------------------------------------ progress */

type Next = { tone: 'brand' | 'success' | 'warning' | 'neutral'; icon: ComponentType<IconProps>; title: string; body: string };

/** What the page asks of this staff member now — read from the return and its refund, never inferred about money. */
function nextStep(r: OrderReturn, t: ReturnType<typeof useI18n>['t']): Next {
    const refund = r.refund;
    if (r.status === 'rejected') return { tone: 'neutral', icon: XCircleIcon, title: t('returns.next.rejected.title'), body: r.note ?? t('returns.next.rejected.body') };
    if (r.status === 'closed') return { tone: 'success', icon: CheckCircleIcon, title: t('returns.next.closed.title'), body: t(r.has_refund ? 'returns.next.closed.body' : 'returns.next.closed.noRefund') };
    if (r.status === 'requested') return { tone: 'brand', icon: LightningIcon, title: t('returns.next.requested.title'), body: t('returns.next.requested.body') };
    if (r.status === 'received') return { tone: 'brand', icon: LightningIcon, title: t('returns.next.received.title'), body: t('returns.next.received.body') };
    if (!refund) return { tone: 'neutral', icon: HourglassIcon, title: t('returns.next.hidden.title'), body: t('returns.resolution.hidden') };
    const amount = formatMoney(refund.amount);
    switch (refund.status) {
        case 'requested':
            return refund.can.approve
                ? { tone: 'brand', icon: LightningIcon, title: t('returns.next.approve.title'), body: t('returns.next.approve.body') }
                : { tone: 'warning', icon: HourglassIcon, title: t('returns.next.manager.title'), body: refund.needs_manager ? t('refunds.needsManager', { threshold: formatMoney(refund.approval_threshold) }) : t('returns.next.manager.body') };
        case 'approved':
            return refund.can.pay
                ? { tone: 'brand', icon: LightningIcon, title: t('returns.next.pay.title', { amount }), body: t(refund.payout === 'manual' ? 'returns.next.pay.manual' : 'returns.next.pay.gateway') }
                : { tone: 'neutral', icon: HourglassIcon, title: t('returns.next.payWait.title'), body: t('returns.next.payWait.body') };
        case 'processing':
            return { tone: 'neutral', icon: HourglassIcon, title: t('returns.next.processing.title'), body: t('returns.next.processing.body') };
        case 'failed':
            return { tone: 'warning', icon: WarningIcon, title: t('returns.next.failed.title'), body: t('returns.next.failed.body') };
        default:
            return { tone: 'neutral', icon: CheckCircleIcon, title: t(refundStatusLabel(refund.status)), body: refund.note ?? '' };
    }
}

const calloutLook: Record<Next['tone'], { box: string; icon: string }> = {
    brand: { box: 'border-line-brand-subtle bg-brand-subtle', icon: 'text-fg-brand' },
    success: { box: 'border-line-default bg-success-subtle', icon: 'text-feedback-success' },
    warning: { box: 'border-line-default bg-warning-subtle', icon: 'text-feedback-warning' },
    neutral: { box: 'border-line-default bg-subtle', icon: 'text-fg-secondary' },
};

function Progress({ r }: { r: OrderReturn }) {
    const { t, locale } = useI18n();
    const steps = returnSteps(r, t, locale);
    const next = nextStep(r, t);
    const look = calloutLook[next.tone];
    const status = r.status === 'inspected' && r.refund ? { label: t(refundStatusLabel(r.refund.status)), tone: refundTone[r.refund.status] } : { label: t(returnStatusLabel(r.status)), tone: returnTone[r.status] };

    return (
        <section className={card} aria-labelledby="progress-heading">
            <div className="flex flex-wrap items-center gap-3">
                <h2 id="progress-heading" className="min-w-0 flex-1 t-admin-section text-fg-primary">
                    {t('returns.progress')}
                </h2>
                <StatusChip label={status.label} tone={status.tone} />
            </div>
            <Stepper steps={steps} />
            {/* aria-live: after an action the page refetches and this line tells the user what changed. */}
            <div className={`flex items-start gap-3 rounded-sm border p-4 ${look.box}`} aria-live="polite">
                <next.icon size={20} aria-hidden className={`mt-0.5 shrink-0 ${look.icon}`} />
                <div className="flex min-w-0 flex-col gap-1">
                    <p className="t-admin-body-strong text-fg-primary">{next.title}</p>
                    {next.body && <p className="t-admin-body text-fg-secondary">{next.body}</p>}
                </div>
            </div>
        </section>
    );
}

function Stepper({ steps }: { steps: ReturnStep[] }) {
    const { t } = useI18n();
    return (
        <ol className="grid grid-cols-2 gap-4 sm:flex sm:gap-2">
            {steps.map((step, index) => (
                <li key={step.key} className="flex min-w-0 flex-1 flex-col gap-2" aria-current={step.state === 'current' ? 'step' : undefined}>
                    <span className="flex items-center gap-2">
                        <span
                            aria-hidden
                            className={`grid size-6 shrink-0 place-items-center rounded-full t-admin-caption-strong ${
                                step.state === 'done'
                                    ? 'bg-brand text-fg-on-brand'
                                    : step.state === 'current'
                                      ? 'border-2 border-line-brand text-fg-brand'
                                      : step.state === 'stopped'
                                        ? 'bg-danger-subtle text-feedback-danger'
                                        : 'bg-subtle text-fg-secondary'
                            }`}
                        >
                            {step.state === 'done' ? <CheckIcon size={14} weight="bold" /> : step.state === 'stopped' ? <XIcon size={14} weight="bold" /> : index + 1}
                        </span>
                        {index < steps.length - 1 && <span aria-hidden className={`hidden h-0.5 flex-1 rounded-full sm:block ${step.state === 'done' ? 'bg-brand' : 'bg-line-default'}`} />}
                    </span>
                    <span className={step.state === 'upcoming' ? 't-admin-body text-fg-secondary' : 't-admin-body-strong text-fg-primary'}>
                        {t(step.label)}
                        <span className="sr-only"> — {t(`returns.step.state.${step.state}` as MessageKey)}</span>
                    </span>
                    <span className={`t-admin-caption tabular ${step.state === 'current' ? 'text-fg-brand' : 'text-fg-tertiary'}`}>{step.sub}</span>
                </li>
            ))}
        </ol>
    );
}

function RejectReturn({ r, onClose }: { r: OrderReturn; onClose: () => void }) {
    const { t } = useI18n();
    const toast = useToast();
    const step = useReturnStep();
    const [note, setNote] = useState('');
    return (
        <section className="flex flex-col gap-3 rounded-md border border-line-danger bg-danger-subtle p-5" aria-labelledby="reject-heading">
            <h2 id="reject-heading" className="flex items-start gap-2 t-admin-body-strong text-fg-primary">
                <WarningCircleIcon size={18} weight="fill" aria-hidden className="mt-0.5 shrink-0 text-feedback-danger" />
                {t('returns.action.rejectTitle', { number: r.number })}
            </h2>
            <p className="t-admin-body text-fg-secondary">{t('returns.action.rejectBody')}</p>
            <Field label={t('returns.action.reason')} required>
                {({ id }) => <textarea id={id} rows={2} maxLength={500} value={note} onChange={(e) => setNote(e.target.value)} className={`${inputClass} py-2`} />}
            </Field>
            <div className="flex flex-wrap gap-2">
                <Button
                    variant="danger"
                    disabled={note.trim().length < 3 || step.isPending}
                    onClick={() =>
                        step.mutate(
                            { id: r.id, step: 'reject', body: { note: note.trim() } },
                            {
                                onSuccess: () => {
                                    toast.notify(t('returns.toast.rejected', { number: r.number }));
                                    onClose();
                                },
                                onError: (e) => toast.notify(problemText(e, t('error.title')), 'danger'),
                            },
                        )
                    }
                >
                    {t('returns.action.reject')}
                </Button>
                <Button variant="ghost" onClick={onClose}>
                    {t('common.cancel')}
                </Button>
            </div>
        </section>
    );
}

/* ------------------------------------------------------------------ about */

function Fact({ label, children, wide }: { label: string; children: ReactNode; wide?: boolean }) {
    return (
        <div className={`flex min-w-0 flex-col gap-1 ${wide ? 'sm:col-span-2 xl:col-span-1' : ''}`}>
            <dt className="t-admin-overline text-fg-tertiary uppercase">{label}</dt>
            <dd className="flex min-w-0 flex-col items-start gap-1">{children}</dd>
        </div>
    );
}

function About({ r }: { r: OrderReturn }) {
    const { t, locale } = useI18n();
    return (
        <section className={card} aria-labelledby="about-heading">
            <h2 id="about-heading" className="t-admin-section text-fg-primary">
                {t('returns.about')}
            </h2>
            <dl className="grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-2 xl:grid-cols-[minmax(0,2fr)_repeat(3,minmax(0,1fr))]">
                <Fact label={t('returns.about.said')} wide>
                    <p className="t-admin-body-strong text-fg-primary">{t(reasonLabel(r.reason))}</p>
                    {r.reason_note && <p className="t-admin-body text-fg-secondary">“{r.reason_note}”</p>}
                </Fact>
                <Fact label={t('returns.about.order')}>
                    <Link to={`/orders/${r.order.id}`} className="t-admin-body-strong text-fg-link hover:underline">
                        {r.order.number}
                    </Link>
                    <p className="t-admin-caption text-fg-secondary">{r.order.customer_name}</p>
                </Fact>
                <Fact label={t('returns.about.delivered')}>
                    <p className="t-admin-body-strong text-fg-primary">{r.order.delivered_at ? formatDhakaDate(r.order.delivered_at, locale) : '—'}</p>
                    {r.within_window !== null && (
                        <p className="flex items-center gap-1 t-admin-caption text-fg-secondary">
                            {r.within_window ? <CheckIcon size={14} aria-hidden className="shrink-0 text-feedback-success" /> : <WarningIcon size={14} aria-hidden className="shrink-0 text-feedback-warning" />}
                            {t(r.within_window ? 'returns.window.inside' : 'returns.window.outside', { days: r.window_days })}
                        </p>
                    )}
                </Fact>
                <Fact label={t('returns.about.paidBy')}>
                    <PaymentChip method={r.order.payment_method} />
                    {r.refund && <p className="t-admin-caption text-fg-secondary">{t(r.refund.payout === 'manual' ? 'returns.about.byHand' : 'returns.about.byGateway')}</p>}
                </Fact>
            </dl>
        </section>
    );
}

/* ------------------------------------------------------------------ inspection */

function Thumb() {
    return (
        <span aria-hidden className="grid size-12 shrink-0 place-items-center rounded-xs bg-subtle text-fg-tertiary">
            <ImageSquareIcon size={20} />
        </span>
    );
}

function CountStepper({ label, hint, value, max, onChange }: { label: string; hint: string; value: number; max: number; onChange: (value: number) => void }) {
    const { t } = useI18n();
    const clamp = (v: number) => Math.max(0, Math.min(max, v));
    return (
        <div className="flex flex-col gap-1">
            <div className="flex h-10 items-center gap-1 rounded-sm border border-line-strong bg-surface px-1 focus-within:border-line-focus">
                <IconButton size="sm" aria-label={t('returns.inspection.fewer', { label })} disabled={value <= 0} onClick={() => onChange(clamp(value - 1))}>
                    <MinusIcon size={16} aria-hidden />
                </IconButton>
                <input
                    type="number"
                    inputMode="numeric"
                    min={0}
                    max={max}
                    value={value}
                    aria-label={label}
                    onChange={(e) => onChange(clamp(Number(e.target.value) || 0))}
                    className="h-full min-w-0 flex-1 bg-transparent text-center t-admin-num-md tabular text-fg-primary outline-none [appearance:textfield] [&::-webkit-inner-spin-button]:appearance-none [&::-webkit-outer-spin-button]:appearance-none"
                />
                <IconButton size="sm" aria-label={t('returns.inspection.more', { label })} disabled={value >= max} onClick={() => onChange(clamp(value + 1))}>
                    <PlusIcon size={16} aria-hidden />
                </IconButton>
            </div>
            <p className="t-admin-caption text-fg-tertiary">{hint}</p>
        </div>
    );
}

function Inspect({ r }: { r: OrderReturn }) {
    const { t, locale } = useI18n();
    const toast = useToast();
    const step = useReturnStep();
    const initial = useMemo<Counts>(() => Object.fromEntries(r.items.map((i) => [i.order_item_id, { resellable: i.quantity, damaged: 0 }])), [r.items]);
    const [counts, setCounts] = useState<Counts>(initial);
    const [note, setNote] = useState('');
    useEffect(() => setCounts(initial), [initial]);

    const name = (i: Item) => (locale === 'bn' && i.product_name_bn ? i.product_name_bn : i.product_name);
    const get = (i: Item) => counts[i.order_item_id] ?? { resellable: i.quantity, damaged: 0 };
    const setResellable = (i: Item, v: number) => setCounts((s) => ({ ...s, [i.order_item_id]: { resellable: v, damaged: i.quantity - v } }));
    const all = (resellable: boolean) => setCounts(Object.fromEntries(r.items.map((i) => [i.order_item_id, resellable ? { resellable: i.quantity, damaged: 0 } : { resellable: 0, damaged: i.quantity }])));
    const total = r.items.reduce((sum, i) => sum + i.quantity, 0);
    const resellable = r.items.reduce((sum, i) => sum + get(i).resellable, 0);
    const editable = r.can.manage;

    return (
        <>
            <section className={card} aria-labelledby="inspection-heading">
                <div className="flex flex-wrap items-center gap-3">
                    <div className="flex min-w-0 flex-1 basis-64 flex-col gap-0.5">
                        <h2 id="inspection-heading" className="t-admin-section text-fg-primary">
                            {t('returns.inspection.title')}
                        </h2>
                        <p className="t-admin-caption text-fg-secondary">{t('returns.inspection.intro', { count: total })}</p>
                    </div>
                    {editable && (
                        <div className="flex items-center gap-2" role="group" aria-label={t('returns.inspection.quick')}>
                            <span className="t-admin-caption text-fg-tertiary">{t('returns.inspection.quick')}</span>
                            <Button variant="outline" size="sm" onClick={() => all(true)}>
                                {t('returns.inspection.allFine')}
                            </Button>
                            <Button variant="outline" size="sm" onClick={() => all(false)}>
                                {t('returns.inspection.allDamaged')}
                            </Button>
                        </div>
                    )}
                </div>

                <div className="overflow-hidden rounded-sm border border-line-default">
                    <div className="hidden items-center gap-4 bg-subtle px-4 py-2.5 lg:flex">
                        <span className="min-w-0 flex-1 t-admin-overline text-fg-tertiary uppercase">{t('returns.inspection.col.item')}</span>
                        <span className="w-24 t-admin-overline text-fg-tertiary uppercase">{t('returns.inspection.col.back')}</span>
                        {editable && (
                            <>
                                <span className="w-55 t-admin-overline text-fg-tertiary uppercase">{t('returns.inspection.resellable')}</span>
                                <span className="w-55 t-admin-overline text-fg-tertiary uppercase">{t('returns.inspection.damaged')}</span>
                            </>
                        )}
                    </div>
                    <ul>
                        {r.items.map((i) => {
                            const c = get(i);
                            return (
                                <li key={i.id} className="flex flex-col gap-3 border-t border-line-default px-4 py-4 first:border-t-0 lg:flex-row lg:items-start lg:gap-4 lg:first:border-t">
                                    <div className="flex min-w-0 flex-1 items-center gap-3">
                                        <Thumb />
                                        <div className="min-w-0">
                                            <p className="t-admin-body-strong text-fg-primary">
                                                {name(i)}
                                                {i.variant_name && <span className="text-fg-secondary"> · {i.variant_name}</span>}
                                            </p>
                                            <p className="t-admin-mono-sm text-fg-tertiary">
                                                {i.sku} · <Taka amount={i.unit_price} signClass="t-admin-currency-caption" /> {t('returns.inspection.each')}
                                            </p>
                                        </div>
                                    </div>
                                    <p className="t-admin-num tabular text-fg-primary lg:flex lg:h-10 lg:w-24 lg:items-center">
                                        <span className="lg:hidden">{t('returns.inspection.col.back')}: </span>× {i.quantity}
                                    </p>
                                    {editable && (
                                        <div className="grid grid-cols-2 gap-3 lg:contents">
                                            <div className="lg:w-55">
                                                <CountStepper label={t('returns.inspection.resellableFor', { name: name(i) })} hint={t('returns.inspection.restockHint')} value={c.resellable} max={i.quantity} onChange={(v) => setResellable(i, v)} />
                                            </div>
                                            <div className="lg:w-55">
                                                <CountStepper label={t('returns.inspection.damagedFor', { name: name(i) })} hint={t('returns.inspection.writeOffHint')} value={c.damaged} max={i.quantity} onChange={(v) => setResellable(i, i.quantity - v)} />
                                            </div>
                                        </div>
                                    )}
                                </li>
                            );
                        })}
                    </ul>
                </div>

                {editable && (
                    <Field label={t('returns.inspection.note')} optional>
                        {({ id }) => <Input id={id} value={note} maxLength={500} placeholder={t('returns.inspection.notePlaceholder')} onChange={(e) => setNote(e.target.value)} />}
                    </Field>
                )}
            </section>

            <section className={card} aria-labelledby="refund-heading">
                <div className="flex items-start gap-3">
                    <InfoIcon size={20} aria-hidden className="mt-0.5 shrink-0 text-feedback-info" />
                    <div className="flex min-w-0 flex-col gap-1">
                        <h2 id="refund-heading" className="t-admin-body-strong text-fg-primary">
                            {t('returns.refund.title')}
                        </h2>
                        <p className="t-admin-body text-fg-secondary">{t('returns.refund.pending')}</p>
                    </div>
                </div>
            </section>

            {editable && (
                <div className={bar}>
                    <CheckCircleIcon size={20} aria-hidden className="shrink-0 text-feedback-success" />
                    <div className="flex min-w-0 flex-1 basis-64 flex-col gap-0.5">
                        <p className="t-admin-body-strong text-fg-primary">{t('returns.inspection.summary', { resellable, damaged: total - resellable, total })}</p>
                        <p className="t-admin-caption text-fg-secondary">{t('returns.inspection.summaryHint')}</p>
                    </div>
                    <Button
                        disabled={step.isPending}
                        onClick={() =>
                            step.mutate(
                                { id: r.id, step: 'inspect', body: { items: r.items.map((i) => ({ order_item_id: i.order_item_id, ...get(i) })), note: note.trim() || null } },
                                {
                                    onSuccess: () => toast.notify(t('returns.toast.inspected', { number: r.number })),
                                    onError: (e) => toast.notify(problemText(e, t('error.title')), 'danger'),
                                },
                            )
                        }
                    >
                        <CheckIcon size={16} aria-hidden />
                        {t('returns.action.inspect')}
                    </Button>
                </div>
            )}
        </>
    );
}

function InspectionResult({ r }: { r: OrderReturn }) {
    const { t, locale } = useI18n();
    const name = (i: Item) => (locale === 'bn' && i.product_name_bn ? i.product_name_bn : i.product_name);
    return (
        <section className={card} aria-labelledby="inspection-heading">
            <h2 id="inspection-heading" className="t-admin-section text-fg-primary">
                {t('returns.inspection.resultTitle')}
            </h2>
            <ul className="flex flex-col gap-2">
                {r.items.map((i) => (
                    <li key={i.id} className="flex items-center gap-3 rounded-sm border border-line-default px-3 py-3">
                        <Thumb />
                        <div className="min-w-0 flex-1">
                            <p className="t-admin-body-strong text-fg-primary">
                                {name(i)} × {i.quantity}
                            </p>
                            {i.quantity_resellable !== null && (
                                <p className="flex items-center gap-1 t-admin-caption text-fg-secondary">
                                    <CheckIcon size={14} aria-hidden className="shrink-0 text-feedback-success" />
                                    {t('returns.inspection.result', { resellable: i.quantity_resellable, damaged: i.quantity_damaged ?? 0 })}
                                </p>
                            )}
                        </div>
                    </li>
                ))}
            </ul>
            {r.note && <p className="t-admin-body text-fg-secondary">{r.note}</p>}
        </section>
    );
}

/* ------------------------------------------------------------------ refund */

/** The refund's server figures, then approve / reject or the payout — with the main action in the bar. */
function RefundSection({ r, refund }: { r: OrderReturn; refund: Refund }) {
    const { t, locale } = useI18n();
    const toast = useToast();
    const decide = useRefundDecision();
    const pay = usePayRefund();
    const [rejecting, setRejecting] = useState(false);
    const [note, setNote] = useState('');
    const [method, setMethod] = useState<PayoutMethod>('bkash');
    const [reference, setReference] = useState('');
    // One key per payout attempt: a resubmit after a dropped connection replays instead of
    // paying twice; once the server has answered, the next attempt (a gateway retry) gets a new key.
    const [key, setKey] = useState(() => crypto.randomUUID());
    const answered = () => setKey(crypto.randomUUID());
    const fail = (e: unknown) => toast.notify(problemText(e, t('error.title')), 'danger');
    const amount = formatMoney(refund.amount);
    const manual = refund.payout === 'manual';
    const approving = refund.status === 'requested' && refund.can.approve;
    const paying = refund.can.pay;
    const methodKey = (payoutMethods as readonly string[]).includes(refund.method) ? `refunds.payout.${refund.method}` : `refunds.method.${refund.method}`;
    const name = (orderItemId: number) => {
        const i = r.items.find((x) => x.order_item_id === orderItemId);
        return i ? (locale === 'bn' && i.product_name_bn ? i.product_name_bn : i.product_name) : '';
    };
    const referenceMissing = manual && method !== 'cash' && reference.trim() === '';

    return (
        <>
            <section className={card} aria-labelledby="refund-heading">
                <h2 id="refund-heading" className="t-admin-section text-fg-primary">
                    {t('returns.refund.title')}
                </h2>
                <div className="flex flex-col gap-3 rounded-sm bg-subtle p-4">
                    <div className="flex flex-wrap items-end gap-3">
                        <div className="flex min-w-0 flex-1 flex-col gap-1">
                            <p className="t-admin-overline text-fg-tertiary uppercase">{t('returns.resolution.refund')}</p>
                            <p className="t-admin-num-lg tabular text-fg-primary">
                                <Taka amount={refund.amount} signClass="t-admin-currency-num-lg" />
                            </p>
                        </div>
                        <StatusChip label={t(refundStatusLabel(refund.status))} tone={refundTone[refund.status]} />
                    </div>
                    <div className="flex flex-col border-t border-line-default pt-2">
                        {refund.items.map((line) => (
                            <ResolutionRow key={line.order_item_id} label={`${line.quantity} × ${name(line.order_item_id)}`}>
                                <Taka amount={line.amount} />
                            </ResolutionRow>
                        ))}
                        {refund.shipping_amount !== '0.00' && (
                            <ResolutionRow label={t('returns.resolution.delivery')}>
                                <Taka amount={refund.shipping_amount} />
                            </ResolutionRow>
                        )}
                        {refund.status === 'completed' && (
                            <>
                                <ResolutionRow label={t('returns.resolution.method')}>{t(methodKey as MessageKey)}</ResolutionRow>
                                {refund.gateway_reference && (
                                    <ResolutionRow label={t('returns.resolution.reference')}>
                                        <span className="t-admin-mono">{refund.gateway_reference}</span>
                                    </ResolutionRow>
                                )}
                            </>
                        )}
                    </div>
                    {refund.note && <p className="t-admin-caption text-fg-secondary">{refund.note}</p>}
                </div>

                {refund.status === 'requested' && refund.needs_manager && (
                    <p className="flex items-start gap-2 rounded-sm bg-warning-subtle px-3 py-2 t-admin-body text-fg-primary">
                        <WarningIcon size={16} aria-hidden className="mt-0.5 shrink-0 text-feedback-warning" />
                        {t('refunds.needsManager', { threshold: formatMoney(refund.approval_threshold) })}
                    </p>
                )}

                {approving && rejecting && (
                    <div className="flex flex-col gap-2 rounded-sm bg-danger-subtle px-4 py-3">
                        <p className="flex items-start gap-2 t-admin-body-strong text-fg-primary">
                            <WarningCircleIcon size={16} weight="fill" aria-hidden className="mt-0.5 shrink-0 text-feedback-danger" />
                            {t('refunds.action.rejectTitle')}
                        </p>
                        <p className="t-admin-caption text-fg-secondary">{t('refunds.action.rejectBody')}</p>
                        <Field label={t('returns.action.reason')} required>
                            {({ id }) => <textarea id={id} rows={2} maxLength={500} value={note} onChange={(e) => setNote(e.target.value)} className={`${inputClass} py-2`} />}
                        </Field>
                        <div className="flex flex-wrap gap-2">
                            <Button
                                variant="danger"
                                size="sm"
                                disabled={note.trim().length < 3 || decide.isPending}
                                onClick={() => decide.mutate({ id: refund.id, decision: 'reject', note: note.trim() }, { onSuccess: () => toast.notify(t('refunds.toast.rejected')), onError: fail })}
                            >
                                {t('refunds.action.reject')}
                            </Button>
                            <Button variant="ghost" size="sm" onClick={() => setRejecting(false)}>
                                {t('common.cancel')}
                            </Button>
                        </div>
                    </div>
                )}

                {paying && manual && (
                    <fieldset className="flex flex-col gap-3">
                        <legend className="mb-3 t-admin-overline text-fg-tertiary uppercase">{t('refunds.payout.heading')}</legend>
                        <div className="flex flex-col gap-2">
                            <p className="t-admin-body-strong text-fg-primary" id="payout-method">
                                {t('refunds.payout.method')}
                            </p>
                            <div role="radiogroup" aria-labelledby="payout-method" className="grid grid-cols-2 gap-2 md:grid-cols-4">
                                {payoutMethods.map((m) => (
                                    <label
                                        key={m}
                                        className="flex h-11 cursor-pointer items-center justify-center gap-2 rounded-sm border border-line-strong bg-surface px-3 t-admin-button-sm text-fg-primary has-checked:border-2 has-checked:border-line-brand has-checked:bg-brand-subtle has-checked:text-fg-brand has-focus-visible:ring-2 has-focus-visible:ring-line-focus"
                                    >
                                        <input type="radio" name={`payout-${refund.id}`} value={m} checked={method === m} onChange={() => setMethod(m)} className="sr-only" />
                                        {method === m && <CheckIcon size={14} aria-hidden />}
                                        {t(`refunds.payout.${m}` as MessageKey)}
                                    </label>
                                ))}
                            </div>
                        </div>
                        {method !== 'cash' && (
                            <div className="max-w-105">
                                <Field label={t('refunds.payout.reference', { method: t(`refunds.payout.${method}` as MessageKey) })} hint={t('refunds.payout.referenceHint')} required>
                                    {({ id, describedBy }) => <Input id={id} aria-describedby={describedBy} value={reference} maxLength={191} onChange={(e) => setReference(e.target.value)} className="t-admin-mono" />}
                                </Field>
                            </div>
                        )}
                    </fieldset>
                )}
            </section>

            {(approving || paying) && (
                <div className={bar}>
                    <HandCoinsIcon size={20} aria-hidden className="shrink-0 text-fg-brand" />
                    <div className="flex min-w-0 flex-1 basis-64 flex-col gap-0.5">
                        <p className="t-admin-body-strong text-fg-primary">
                            {approving
                                ? t('refunds.bar.approve', { amount })
                                : manual
                                  ? t('refunds.bar.pay', { amount, method: t(`refunds.payout.${method}` as MessageKey) })
                                  : t('refunds.bar.gateway', { amount })}
                        </p>
                        <p className="t-admin-caption text-fg-secondary">{t(approving ? 'refunds.bar.approveHint' : 'refunds.bar.payHint')}</p>
                    </div>
                    {approving ? (
                        <>
                            <Button variant="outline" onClick={() => setRejecting(true)} disabled={rejecting}>
                                {t('refunds.action.reject')}
                            </Button>
                            <Button disabled={decide.isPending} onClick={() => decide.mutate({ id: refund.id, decision: 'approve' }, { onSuccess: () => toast.notify(t('refunds.toast.approved')), onError: fail })}>
                                <CheckIcon size={16} aria-hidden />
                                {t('refunds.action.approve')}
                            </Button>
                        </>
                    ) : (
                        <Button
                            disabled={pay.isPending || referenceMissing}
                            onClick={() =>
                                pay.mutate(
                                    { id: refund.id, input: manual ? { method, reference: reference.trim() || null } : {}, idempotencyKey: key },
                                    {
                                        onSuccess: (result) => {
                                            answered();
                                            toast.notify(t(result.status === 'completed' ? 'refunds.toast.paid' : 'refunds.toast.failed'), result.status === 'completed' ? undefined : 'danger');
                                        },
                                        onError: (e) => {
                                            if (e instanceof ApiError) answered();
                                            fail(e);
                                        },
                                    },
                                )
                            }
                        >
                            <CheckIcon size={16} aria-hidden />
                            {t(manual ? 'refunds.action.pay' : 'refunds.action.payGateway', { amount })}
                        </Button>
                    )}
                </div>
            )}
        </>
    );
}
