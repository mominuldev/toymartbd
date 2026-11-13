import { useMemo, useState } from 'react';
import { Link, useSearchParams } from 'react-router';
import { MagnifyingGlassIcon, PhoneIcon, SlidersHorizontalIcon, WarningIcon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { can, useMe } from '@/features/auth/api';
import { ContentHeader } from '@/components/shell/ContentHeader';
import { Button } from '@/components/ui/Button';
import { EmptyState } from '@/components/ui/EmptyState';
import { Field } from '@/components/ui/Field';
import { Input } from '@/components/ui/Input';
import { QueryError } from '@/components/ui/QueryError';
import { Select } from '@/components/ui/Select';
import { useToast } from '@/components/ui/Toast';
import { ApiError } from '@/lib/api';
import { callOutcomes, holdReasons, useHeldOrder, useLogCall, useReject, useRiskQueue, useVerify, type CallOutcome, type HeldOrderDetail, type QueueRow } from './api';
import { Breakdown, CourierPanel, ListStatusBanner, OurCounts, OurScoreCard, RiskPill, RiskTabs, StatusDialog, asLevel, crumbs, dhakaTime, taka } from './parts';

const cancelReasons = ['customer_request', 'customer_unreachable', 'fake_order', 'duplicate_order', 'out_of_stock', 'pricing_error', 'address_unserviceable', 'payment_failed', 'other'] as const;
type CancelReason = (typeof cancelReasons)[number];
const levels = ['', 'high', 'medium', 'low'] as const;

function holdLabel(code: string, t: (k: MessageKey) => string): string {
    return (holdReasons as readonly string[]).includes(code) ? t(`risk.hold.${code}` as MessageKey) : code;
}

/**
 * Verification queue (Figma B · 05 241:36206): held orders on the left, the call card, our
 * score and — separately — the courier record on the right. A person verifies, logs a call or
 * cancels; the system never cancels (rules 23, 24b). Every figure is the server's.
 */
export function VerificationQueuePage() {
    const { t } = useI18n();
    const [params, setParams] = useSearchParams();
    const levelParam = params.get('level') ?? '';
    const level = (levels as readonly string[]).includes(levelParam) ? levelParam : '';
    const queue = useRiskQueue(level);
    const rows = useMemo(() => queue.data?.pages.flatMap((p) => p.data) ?? [], [queue.data]);
    const count = queue.data?.pages[0]?.meta.count ?? 0;
    const selectedParam = Number(params.get('order'));
    const selected = rows.find((r) => r.id === selectedParam) ?? rows[0] ?? null;

    const set = (key: string, value: string) =>
        setParams((current) => {
            const next = new URLSearchParams(current);
            if (value) next.set(key, value);
            else next.delete(key);
            return next;
        });

    return (
        <div className="flex flex-col gap-6">
            <ContentHeader
                title={t('risk.queue.title')}
                breadcrumb={crumbs(t, 'risk.tab.queue')}
                subtitle={t('risk.queue.subtitle')}
                actions={
                    <>
                        <Link to="/risk/rules" className="inline-flex h-10 items-center gap-2 rounded-sm border border-line-default bg-surface px-4 t-admin-button text-fg-primary hover:bg-subtle">
                            <SlidersHorizontalIcon size={18} aria-hidden />
                            {t('risk.tab.rules')}
                        </Link>
                        <Link to="/risk/lookup" className="inline-flex h-10 items-center gap-2 rounded-sm border border-line-default bg-surface px-4 t-admin-button text-fg-primary hover:bg-subtle">
                            <MagnifyingGlassIcon size={18} aria-hidden />
                            {t('risk.tab.lookup')}
                        </Link>
                    </>
                }
            />
            <RiskTabs />
            <div className="flex flex-wrap items-center justify-between gap-3">
                <p className="t-admin-body-strong tabular text-fg-primary" aria-live="polite">
                    {t('risk.queue.count', { count })}
                </p>
                <label className="flex items-center gap-2 t-admin-caption text-fg-secondary">
                    {t('risk.queue.levelFilter')}
                    <Select size="sm" value={level} onChange={(e) => set('level', e.target.value)}>
                        <option value="">{t('risk.queue.allLevels')}</option>
                        {(['high', 'medium', 'low'] as const).map((l) => (
                            <option key={l} value={l}>
                                {t(`risk.levelName.${l}` as MessageKey)}
                            </option>
                        ))}
                    </Select>
                </label>
            </div>
            {queue.isError ? (
                <QueryError error={queue.error} onRetry={() => void queue.refetch()} />
            ) : queue.isSuccess && rows.length === 0 ? (
                <EmptyState reason="allClear" title={t('risk.queue.empty.title')} body={t('risk.queue.empty.body')} />
            ) : (
                <div className="grid gap-5 xl:grid-cols-[--spacing(105)_minmax(0,1fr)]">
                    <ul className="flex flex-col gap-3" aria-label={t('risk.queue.list')}>
                        {rows.map((row) => (
                            <QueueCard key={row.id} row={row} active={selected?.id === row.id} onSelect={() => set('order', String(row.id))} />
                        ))}
                        {queue.hasNextPage && (
                            <li>
                                <Button variant="outline" size="sm" onClick={() => void queue.fetchNextPage()} disabled={queue.isFetchingNextPage}>
                                    {t('risk.queue.loadMore')}
                                </Button>
                            </li>
                        )}
                    </ul>
                    {selected ? <HeldPanel id={selected.id} /> : <p className="t-admin-body text-fg-secondary">{t('risk.queue.pick')}</p>}
                </div>
            )}
        </div>
    );
}

function QueueCard({ row, active, onSelect }: { row: QueueRow; active: boolean; onSelect: () => void }) {
    const { t, locale } = useI18n();
    return (
        <li>
            <button
                type="button"
                onClick={onSelect}
                aria-pressed={active}
                className={`flex w-full flex-col gap-2.5 rounded-md border bg-surface p-4 text-start shadow-e1 hover:bg-subtle ${active ? 'border-2 border-line-brand bg-brand-subtle' : 'border-line-default'}`}
            >
                <span className="flex items-center justify-between gap-2">
                    <span className="t-admin-mono text-fg-primary">{row.number}</span>
                    {row.order_risk.score !== null && <RiskPill level={asLevel(row.order_risk.level)} score={row.order_risk.score} />}
                </span>
                <span className="flex items-center justify-between gap-2">
                    <span className="min-w-0 truncate t-admin-body-strong text-fg-primary">{[row.customer_name, row.phone, row.district].filter(Boolean).join(' · ')}</span>
                    <span className="t-admin-body-strong tabular text-fg-primary">{taka(row.grand_total)}</span>
                </span>
                <span className="flex flex-wrap gap-1.5">
                    {row.order_risk.hold_reasons.map((h) => (
                        <span key={h} className="inline-flex items-center gap-1 rounded-sm border border-line-default px-2 py-0.5 t-admin-caption text-fg-primary">
                            <WarningIcon size={14} aria-hidden className="text-status-low-stock" />
                            {holdLabel(h, t)}
                        </span>
                    ))}
                </span>
                <span className="t-admin-caption tabular text-fg-tertiary">
                    {[
                        row.placed_at
                            ? t('risk.queue.placed', {
                                  when: dhakaTime(row.placed_at, locale),
                              })
                            : null,
                        t('risk.queue.calls', { count: row.calls.count }),
                        row.calls.last_outcome
                            ? t('risk.queue.lastCall', {
                                  outcome: t(`risk.outcome.${row.calls.last_outcome}` as MessageKey),
                              })
                            : null,
                    ]
                        .filter(Boolean)
                        .join(' · ')}
                </span>
            </button>
        </li>
    );
}

function HeldPanel({ id }: { id: number }) {
    const { t, locale } = useI18n();
    const held = useHeldOrder(id);
    if (held.isError) return <QueryError error={held.error} onRetry={() => void held.refetch()} />;
    if (!held.data) return <p className="t-admin-body text-fg-secondary" aria-busy="true" />;
    const { order, phone } = held.data;
    return (
        <div className="flex flex-col gap-5">
            <CallCard detail={held.data} />
            <ListStatusBanner status={phone.status} phone={phone.pii_masked ? null : order.phone} />
            <div className="grid gap-5 lg:grid-cols-2">
                <div className="flex flex-col gap-5">
                    <section className="flex flex-col gap-3 rounded-md border border-line-default bg-surface p-5 shadow-e1" aria-labelledby="risk-order-score">
                        <p className="t-admin-overline text-fg-secondary">{t('risk.ours.eyebrow')}</p>
                        <h2 id="risk-order-score" className="t-admin-section text-fg-primary">
                            {t('risk.ours.orderTitle')}
                        </h2>
                        {order.order_risk.computed_at && (
                            <p className="t-admin-caption text-fg-tertiary">
                                {t('risk.ours.orderProvenance', {
                                    when: dhakaTime(order.order_risk.computed_at, locale),
                                })}
                            </p>
                        )}
                        <Breakdown factors={order.order_risk.signals} score={order.order_risk.score ?? 0} />
                    </section>
                    <OurScoreCard ours={phone.ours} />
                    <OurCounts ours={phone.ours} />
                </div>
                <CourierPanel checkedAt={phone.courier.checked_at} providers={phone.courier.providers} />
            </div>
        </div>
    );
}

function CallCard({ detail }: { detail: HeldOrderDetail }) {
    const { t, locale } = useI18n();
    const { notify } = useToast();
    const { data: me } = useMe();
    const { order, call_log: calls } = detail;
    const verify = useVerify();
    const log = useLogCall();
    const reject = useReject();
    const [outcome, setOutcome] = useState<CallOutcome>('confirmed');
    const [note, setNote] = useState('');
    const [cancelOpen, setCancelOpen] = useState(false);
    const [blacklistOpen, setBlacklistOpen] = useState(false);
    const [reason, setReason] = useState<CancelReason | ''>('');
    const [cancelNote, setCancelNote] = useState('');
    const fail = (e: unknown) => notify(e instanceof ApiError ? (e.problem.detail ?? e.problem.title) : t('risk.error'), 'danger');
    // Verifying confirms the order (owner decision 2026-11-13): risk.verify + orders.confirm.
    const mayVerify = can(me, 'risk.verify') && can(me, 'orders.confirm');
    const mayCancel = mayVerify && can(me, 'orders.cancel');

    return (
        <section className="flex flex-col gap-4 rounded-md border border-line-default bg-surface p-5 shadow-e1" aria-labelledby="risk-call">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="flex min-w-0 flex-col gap-1">
                    <h2 id="risk-call" className="t-admin-section text-fg-primary">
                        {t('risk.call.title', {
                            name: order.customer_name,
                            phone: order.phone,
                        })}
                    </h2>
                    <p className="t-admin-caption text-fg-secondary">
                        {t('risk.call.script', {
                            amount: taka(order.grand_total),
                        })}
                    </p>
                </div>
                {!detail.phone.pii_masked && (
                    <a href={`tel:${order.phone}`} className="inline-flex h-10 items-center gap-2 rounded-sm bg-brand px-4 t-admin-button text-fg-on-brand">
                        <PhoneIcon size={18} aria-hidden />
                        {t('risk.call.callNow')}
                    </a>
                )}
            </div>
            {mayVerify && (
                <form
                    className="flex flex-wrap items-end gap-3"
                    onSubmit={(e) => {
                        e.preventDefault();
                        log.mutate(
                            {
                                id: order.id,
                                input: { outcome, note: note.trim() || null },
                            },
                            {
                                onSuccess: () => (notify(t('risk.call.logged')), setNote('')),
                                onError: fail,
                            },
                        );
                    }}
                >
                    <Field label={t('risk.call.outcome')}>
                        {({ id, describedBy, invalid }) => (
                            <Select id={id} aria-describedby={describedBy} aria-invalid={invalid || undefined} value={outcome} onChange={(e) => setOutcome(e.target.value as CallOutcome)}>
                                {callOutcomes.map((o) => (
                                    <option key={o} value={o}>
                                        {t(`risk.outcome.${o}` as MessageKey)}
                                    </option>
                                ))}
                            </Select>
                        )}
                    </Field>
                    <div className="min-w-48 flex-1">
                        <Field label={t('risk.call.note')} optional>
                            {({ id, describedBy, invalid }) => (
                                <Input id={id} aria-describedby={describedBy} aria-invalid={invalid || undefined} value={note} onChange={(e) => setNote(e.target.value)} maxLength={1000} />
                            )}
                        </Field>
                    </div>
                    <Button type="submit" variant="outline" disabled={log.isPending}>
                        {t('risk.call.log')}
                    </Button>
                </form>
            )}
            <div className="flex flex-wrap gap-2">
                {mayVerify && (
                    <Button
                        onClick={() =>
                            verify.mutate(
                                { id: order.id, note: note.trim() || null },
                                {
                                    onSuccess: () =>
                                        notify(
                                            t('risk.call.verified', {
                                                number: order.number,
                                            }),
                                        ),
                                    onError: fail,
                                },
                            )
                        }
                        disabled={verify.isPending}
                    >
                        {t('risk.call.verify')}
                    </Button>
                )}
                {mayCancel && (
                    <Button variant="outline" onClick={() => setCancelOpen(true)}>
                        {t('risk.call.cancel')}
                    </Button>
                )}
                {can(me, 'risk.manage_lists') && !detail.phone.pii_masked && (
                    <Button variant="ghost" onClick={() => setBlacklistOpen(true)}>
                        {t('risk.call.blacklist')}
                    </Button>
                )}
            </div>
            <p className="t-admin-caption text-fg-secondary">{t('risk.call.footnote')}</p>
            <div className="flex flex-col gap-1.5 border-t border-line-default pt-3">
                <p className="t-admin-overline text-fg-secondary">{t('risk.call.history')}</p>
                {calls.length === 0 ? (
                    <p className="t-admin-caption text-fg-tertiary">{t('risk.call.noCalls')}</p>
                ) : (
                    <ul className="flex flex-col gap-1">
                        {calls.map((c) => (
                            <li key={c.id} className="flex flex-wrap justify-between gap-2 t-admin-caption">
                                <span className="text-fg-primary">
                                    {t(`risk.outcome.${c.outcome}` as MessageKey)}
                                    {c.note ? ` — ${c.note}` : ''}
                                </span>
                                <span className="tabular text-fg-tertiary">
                                    {t('risk.call.by', {
                                        who: c.user_name ?? '—',
                                        when: c.created_at ? dhakaTime(c.created_at, locale) : '—',
                                    })}
                                </span>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
            {cancelOpen && (
                <div role="dialog" aria-modal="true" aria-labelledby="risk-cancel-title" className="fixed inset-0 z-50 grid place-items-center bg-canvas/80 p-4">
                    <form
                        className="flex w-full max-w-130 flex-col gap-4 rounded-lg border border-line-default bg-surface p-5 shadow-e4"
                        onSubmit={(e) => {
                            e.preventDefault();
                            if (!reason) return;
                            reject.mutate(
                                {
                                    id: order.id,
                                    input: {
                                        reason,
                                        note: cancelNote.trim() || null,
                                    },
                                },
                                {
                                    onSuccess: () => (
                                        notify(
                                            t('risk.cancel.done', {
                                                number: order.number,
                                            }),
                                        ),
                                        setCancelOpen(false)
                                    ),
                                    onError: fail,
                                },
                            );
                        }}
                    >
                        <h2 id="risk-cancel-title" className="t-admin-section text-fg-primary">
                            {t('risk.cancel.title', { number: order.number })}
                        </h2>
                        <p className="t-admin-body text-fg-secondary">{t('risk.cancel.body')}</p>
                        <Field label={t('orders.cancel.reason')} required>
                            {({ id, describedBy, invalid }) => (
                                <Select id={id} aria-describedby={describedBy} aria-invalid={invalid || undefined} value={reason} onChange={(e) => setReason(e.target.value as CancelReason)} required>
                                    <option value="">{t('orders.cancel.reasonPick')}</option>
                                    {cancelReasons.map((r) => (
                                        <option key={r} value={r}>
                                            {t(`orders.cancelReason.${r}` as MessageKey)}
                                        </option>
                                    ))}
                                </Select>
                            )}
                        </Field>
                        <Field label={t('orders.cancel.note')} optional={reason !== 'other'} required={reason === 'other'}>
                            {({ id, describedBy, invalid }) => (
                                <Input id={id} aria-describedby={describedBy} aria-invalid={invalid || undefined} value={cancelNote} onChange={(e) => setCancelNote(e.target.value)} maxLength={500} />
                            )}
                        </Field>
                        <div className="flex justify-end gap-2 border-t border-line-default pt-4">
                            <Button type="button" variant="ghost" onClick={() => setCancelOpen(false)}>
                                {t('orders.cancel.keep')}
                            </Button>
                            <Button type="submit" variant="danger" disabled={!reason || (reason === 'other' && !cancelNote.trim()) || reject.isPending}>
                                {t('orders.cancel.submit')}
                            </Button>
                        </div>
                    </form>
                </div>
            )}
            {blacklistOpen && <StatusDialog phone={order.phone} current={detail.phone.status.value} preset="blacklist" onClose={() => setBlacklistOpen(false)} />}
        </section>
    );
}
