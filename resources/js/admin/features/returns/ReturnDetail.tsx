import { useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router';
import { CheckIcon, PackageIcon, WarningIcon, XIcon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { ApiError } from '@/lib/api';
import { formatDhakaDate } from '@/lib/format';
import { Button } from '@/components/ui/Button';
import { Field, inputClass } from '@/components/ui/Field';
import { Input } from '@/components/ui/Input';
import { QueryError } from '@/components/ui/QueryError';
import { Select } from '@/components/ui/Select';
import { StatusChip } from '@/components/ui/StatusChip';
import { useToast } from '@/components/ui/Toast';
import { payoutMethods, usePayRefund, useRefundDecision, useReturn, useReturnStep, type OrderReturn, type PayoutMethod, type Refund } from './api';
import { Overline, ResolutionRow, Taka, formatMoney, problemText, refundStatusLabel, refundTone, returnStatusLabel, returnTone } from './parts';

const panel = 'flex flex-col gap-3.5 rounded-md border border-line-default bg-surface p-5 shadow-e1';

/**
 * The queue's detail panel (Figma 241:35871): heading + caption (items · delivered · return
 * window), the customer's words, INSPECTION (resellable / damaged per line — the server
 * restocks and writes off), RESOLUTION (the server's refund figures) and the actions this
 * staff member may take now. Every amount, permission and threshold comes from the API.
 */
export function ReturnDetail({ id }: { id: number }) {
    const { t, locale } = useI18n();
    const detail = useReturn(id);

    if (detail.isError) return <QueryError error={detail.error} onRetry={() => void detail.refetch()} />;
    if (detail.isPending)
        return (
            <p className="t-admin-body text-fg-tertiary" aria-busy="true">
                {t('common.loading')}
            </p>
        );

    const r = detail.data;
    const items = r.items.map((i) => t('returns.detail.items', { name: locale === 'bn' && i.product_name_bn ? i.product_name_bn : i.product_name, quantity: i.quantity })).join(', ');
    const caption = [
        items,
        r.order.delivered_at ? t('returns.detail.delivered', { date: formatDhakaDate(r.order.delivered_at, locale) }) : null,
        r.within_window === null ? null : t(r.within_window ? 'returns.window.inside' : 'returns.window.outside', { days: r.window_days }),
    ]
        .filter(Boolean)
        .join(' · ');

    return (
        <section className={panel} aria-labelledby="return-heading">
            <div className="flex flex-wrap items-start gap-2">
                <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                    <h2 id="return-heading" className="t-admin-section text-fg-primary">
                        {t('returns.detail.heading', { number: r.number })}
                    </h2>
                    <p className="t-admin-caption text-fg-tertiary">{caption}</p>
                </div>
                <StatusChip label={t(returnStatusLabel(r.status))} tone={returnTone[r.status]} />
            </div>
            <Link to={`/orders/${r.order.id}`} className="t-admin-body text-fg-link hover:underline">
                {r.order.number} · {r.order.customer_name}
            </Link>
            {r.reason_note && <p className="t-admin-body text-fg-secondary">“{r.reason_note}”</p>}
            {r.within_window === false && (
                <p className="flex items-center gap-2 rounded-sm border-l-2 border-feedback-warning bg-warning-subtle px-3 py-2 t-admin-body text-fg-primary">
                    <WarningIcon size={16} aria-hidden className="shrink-0 text-feedback-warning" />
                    {t('returns.window.outside', { days: r.window_days })}
                </p>
            )}

            <Inspection r={r} />
            <Resolution r={r} />
        </section>
    );
}

function Inspection({ r }: { r: OrderReturn }) {
    const { t, locale } = useI18n();
    const toast = useToast();
    const step = useReturnStep();
    const open = r.status === 'requested' || r.status === 'received';
    const initial = useMemo(() => Object.fromEntries(r.items.map((i) => [i.order_item_id, { resellable: i.quantity, damaged: 0 }])), [r.items]);
    const [counts, setCounts] = useState<Record<number, { resellable: number; damaged: number }>>(initial);
    const [note, setNote] = useState('');
    const [rejecting, setRejecting] = useState(false);
    const [rejectNote, setRejectNote] = useState('');
    useEffect(() => setCounts(initial), [initial]);

    const done = (key: MessageKey) => () => toast.notify(t(key, { number: r.number }));
    const fail = (e: unknown) => toast.notify(problemText(e, t('error.title')), 'danger');
    const name = (i: OrderReturn['items'][number]) => (locale === 'bn' && i.product_name_bn ? i.product_name_bn : i.product_name);

    return (
        <div className="flex flex-col gap-3">
            <Overline>{t('returns.inspection')}</Overline>
            <ul className="flex flex-col gap-3">
                {r.items.map((i) => {
                    const c = counts[i.order_item_id] ?? { resellable: i.quantity, damaged: 0 };
                    return (
                        <li key={i.id} className="flex flex-col gap-2 rounded-sm bg-subtle px-3.5 py-3">
                            <div className="flex items-center gap-3">
                                <span aria-hidden className="grid size-10 shrink-0 place-items-center rounded-sm bg-surface text-fg-tertiary">
                                    <PackageIcon size={20} />
                                </span>
                                <div className="min-w-0 flex-1">
                                    <p className="truncate t-admin-body-strong text-fg-primary">{name(i)}</p>
                                    <p className="t-admin-mono-sm text-fg-tertiary">
                                        {i.sku} · × {i.quantity}
                                    </p>
                                </div>
                            </div>
                            {open ? (
                                <>
                                    <div className="grid grid-cols-2 gap-3">
                                        <Field label={t('returns.inspection.resellable')}>
                                            {({ id, describedBy }) => (
                                                <Input
                                                    id={id}
                                                    aria-describedby={describedBy}
                                                    aria-label={t('returns.inspection.resellableFor', { name: name(i) })}
                                                    type="number"
                                                    min={0}
                                                    max={i.quantity}
                                                    inputMode="numeric"
                                                    value={c.resellable}
                                                    onChange={(e) => {
                                                        const v = Math.max(0, Math.min(i.quantity, Number(e.target.value) || 0));
                                                        setCounts((s) => ({ ...s, [i.order_item_id]: { resellable: v, damaged: i.quantity - v } }));
                                                    }}
                                                />
                                            )}
                                        </Field>
                                        <Field label={t('returns.inspection.damaged')}>
                                            {({ id, describedBy }) => (
                                                <Input
                                                    id={id}
                                                    aria-describedby={describedBy}
                                                    aria-label={t('returns.inspection.damagedFor', { name: name(i) })}
                                                    type="number"
                                                    min={0}
                                                    max={i.quantity}
                                                    inputMode="numeric"
                                                    value={c.damaged}
                                                    onChange={(e) => {
                                                        const v = Math.max(0, Math.min(i.quantity, Number(e.target.value) || 0));
                                                        setCounts((s) => ({ ...s, [i.order_item_id]: { resellable: i.quantity - v, damaged: v } }));
                                                    }}
                                                />
                                            )}
                                        </Field>
                                    </div>
                                    <p className="t-admin-caption text-fg-secondary">{t('returns.inspection.hint', { quantity: i.quantity })}</p>
                                </>
                            ) : (
                                i.quantity_resellable !== null && (
                                    <p className="flex items-center gap-1.5 t-admin-body text-fg-primary">
                                        <CheckIcon size={16} aria-hidden className="text-feedback-success" />
                                        {t('returns.inspection.result', { resellable: i.quantity_resellable, damaged: i.quantity_damaged ?? 0 })}
                                    </p>
                                )
                            )}
                        </li>
                    );
                })}
            </ul>
            {open && r.can.manage && (
                <>
                    <Field label={t('returns.inspection.note')} optional>
                        {({ id }) => <Input id={id} value={note} maxLength={500} onChange={(e) => setNote(e.target.value)} />}
                    </Field>
                    {rejecting ? (
                        <div className="flex flex-col gap-2 rounded-sm border-l-2 border-feedback-danger bg-subtle px-3.5 py-3">
                            <p className="t-admin-body-strong text-fg-primary">{t('returns.action.rejectTitle', { number: r.number })}</p>
                            <p className="t-admin-caption text-fg-secondary">{t('returns.action.rejectBody')}</p>
                            <Field label={t('returns.action.reason')} required>
                                {({ id }) => <textarea id={id} rows={2} maxLength={500} value={rejectNote} onChange={(e) => setRejectNote(e.target.value)} className={`${inputClass} py-2`} />}
                            </Field>
                            <div className="flex flex-wrap gap-2">
                                <Button
                                    variant="danger"
                                    size="sm"
                                    disabled={rejectNote.trim().length < 3 || step.isPending}
                                    onClick={() => step.mutate({ id: r.id, step: 'reject', body: { note: rejectNote.trim() } }, { onSuccess: done('returns.toast.rejected'), onError: fail })}
                                >
                                    {t('returns.action.reject')}
                                </Button>
                                <Button variant="ghost" size="sm" onClick={() => setRejecting(false)}>
                                    {t('common.cancel')}
                                </Button>
                            </div>
                        </div>
                    ) : (
                        <div className="flex flex-wrap gap-2">
                            <Button
                                disabled={step.isPending}
                                onClick={() =>
                                    step.mutate(
                                        {
                                            id: r.id,
                                            step: 'inspect',
                                            body: { items: r.items.map((i) => ({ order_item_id: i.order_item_id, ...(counts[i.order_item_id] ?? { resellable: i.quantity, damaged: 0 }) })), note: note.trim() || null },
                                        },
                                        { onSuccess: done('returns.toast.inspected'), onError: fail },
                                    )
                                }
                            >
                                <CheckIcon size={16} aria-hidden />
                                {t('returns.action.inspect')}
                            </Button>
                            {r.status === 'requested' && (
                                <Button variant="outline" disabled={step.isPending} onClick={() => step.mutate({ id: r.id, step: 'receive' }, { onSuccess: done('returns.toast.received'), onError: fail })}>
                                    {t('returns.action.receive')}
                                </Button>
                            )}
                            <Button variant="ghost" onClick={() => setRejecting(true)}>
                                <XIcon size={16} aria-hidden />
                                {t('returns.action.reject')}
                            </Button>
                        </div>
                    )}
                </>
            )}
        </div>
    );
}

function Resolution({ r }: { r: OrderReturn }) {
    const { t } = useI18n();
    const refund = r.refund;

    return (
        <div className="flex flex-col gap-1">
            <Overline>{t('returns.resolution')}</Overline>
            {r.status === 'requested' || r.status === 'received' ? (
                <p className="py-1.5 t-admin-body text-fg-secondary">{t('returns.resolution.pending')}</p>
            ) : r.status === 'rejected' ? (
                r.note && <p className="py-1.5 t-admin-body text-fg-secondary">{r.note}</p>
            ) : refund ? (
                <RefundBlock refund={refund} />
            ) : (
                <p className="py-1.5 t-admin-body text-fg-secondary">{t(r.has_refund ? 'returns.resolution.hidden' : 'returns.resolution.none')}</p>
            )}
        </div>
    );
}

/** The refund's server figures and the approve / reject / pay actions this user may take. */
export function RefundBlock({ refund }: { refund: Refund }) {
    const { t } = useI18n();
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
    const methodKey = (payoutMethods as readonly string[]).includes(refund.method) ? `refunds.payout.${refund.method}` : `refunds.method.${refund.method}`;

    return (
        <div className="flex flex-col">
            <ResolutionRow label={t('returns.resolution.refund')}>
                <Taka amount={refund.amount} />
            </ResolutionRow>
            {refund.shipping_amount !== '0.00' && (
                <ResolutionRow label={t('returns.resolution.delivery')}>
                    <Taka amount={refund.shipping_amount} />
                </ResolutionRow>
            )}
            <div className="flex items-center gap-2 py-1.5">
                <span className="min-w-0 flex-1 t-admin-body text-fg-secondary">{t('returns.resolution.status')}</span>
                <StatusChip label={t(refundStatusLabel(refund.status))} tone={refundTone[refund.status]} />
            </div>
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
            {refund.note && <p className="py-1.5 t-admin-caption text-fg-secondary">{refund.note}</p>}
            {refund.status === 'requested' && refund.needs_manager && (
                <p className="mt-1 flex items-start gap-2 rounded-sm border-l-2 border-feedback-warning bg-warning-subtle px-3 py-2 t-admin-body text-fg-primary">
                    <WarningIcon size={16} aria-hidden className="mt-0.5 shrink-0 text-feedback-warning" />
                    {t('refunds.needsManager', { threshold: formatMoney(refund.approval_threshold) })}
                </p>
            )}

            {refund.can.approve &&
                (rejecting ? (
                    <div className="mt-2 flex flex-col gap-2 rounded-sm border-l-2 border-feedback-danger bg-subtle px-3.5 py-3">
                        <p className="t-admin-body-strong text-fg-primary">{t('refunds.action.rejectTitle')}</p>
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
                ) : (
                    <div className="mt-2 flex flex-wrap gap-2">
                        <Button disabled={decide.isPending} onClick={() => decide.mutate({ id: refund.id, decision: 'approve' }, { onSuccess: () => toast.notify(t('refunds.toast.approved')), onError: fail })}>
                            <CheckIcon size={16} aria-hidden />
                            {t('refunds.action.approve')}
                        </Button>
                        <Button variant="outline" onClick={() => setRejecting(true)}>
                            {t('refunds.action.reject')}
                        </Button>
                    </div>
                ))}

            {refund.can.pay && (
                <div className="mt-2 flex flex-col gap-3">
                    {refund.payout === 'manual' && (
                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <Field label={t('refunds.payout.method')} required>
                                {({ id }) => (
                                    <Select id={id} value={method} onChange={(e) => setMethod(e.target.value as PayoutMethod)}>
                                        {payoutMethods.map((m) => (
                                            <option key={m} value={m}>
                                                {t(`refunds.payout.${m}` as MessageKey)}
                                            </option>
                                        ))}
                                    </Select>
                                )}
                            </Field>
                            <Field label={t('refunds.payout.reference')} hint={t('refunds.payout.referenceHint')} required={method !== 'cash'}>
                                {({ id, describedBy }) => <Input id={id} aria-describedby={describedBy} value={reference} maxLength={191} onChange={(e) => setReference(e.target.value)} className="t-admin-mono" />}
                            </Field>
                        </div>
                    )}
                    <div className="flex flex-wrap gap-2">
                        <Button
                            disabled={pay.isPending || (refund.payout === 'manual' && method !== 'cash' && reference.trim() === '')}
                            onClick={() =>
                                pay.mutate(
                                    { id: refund.id, input: refund.payout === 'manual' ? { method, reference: reference.trim() || null } : {}, idempotencyKey: key },
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
                            {t(refund.payout === 'manual' ? 'refunds.action.pay' : 'refunds.action.payGateway')}
                        </Button>
                    </div>
                </div>
            )}
        </div>
    );
}
