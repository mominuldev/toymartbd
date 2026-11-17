import { useMemo, useState } from 'react';
import { Link, useSearchParams } from 'react-router';
import {
    ArrowCounterClockwiseIcon,
    CaretDownIcon,
    ChatCircleDotsIcon,
    CheckCircleIcon,
    ClockIcon,
    MagnifyingGlassIcon,
    PhoneIcon,
    ProhibitIcon,
    SlidersHorizontalIcon,
    WarningIcon,
    WhatsappLogoIcon,
    WifiSlashIcon,
    XCircleIcon,
    type IconProps,
} from '@phosphor-icons/react';
import type { ComponentType } from 'react';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { can, useMe } from '@/features/auth/api';
import { ContentHeader } from '@/components/shell/ContentHeader';
import { Button, buttonClass } from '@/components/ui/Button';
import { EmptyState } from '@/components/ui/EmptyState';
import { Field } from '@/components/ui/Field';
import { Input } from '@/components/ui/Input';
import { QueryError } from '@/components/ui/QueryError';
import { SegmentedControl } from '@/components/ui/SegmentedControl';
import { Select } from '@/components/ui/Select';
import { useToast } from '@/components/ui/Toast';
import { ApiError } from '@/lib/api';
import { useHeldOrder, useLogCall, useReject, useRiskQueue, useVerify, type CallOutcome, type HeldOrderDetail, type QueueRow } from './api';
import { CourierPanel, ListStatusBanner, OurRecord, RiskPill, RiskTabs, StatusDialog, WhyPaused, asLevel, crumbs, dhakaTime, holdSentence, taka, useWide } from './parts';

const cancelReasons = ['customer_request', 'customer_unreachable', 'fake_order', 'duplicate_order', 'out_of_stock', 'pricing_error', 'address_unserviceable', 'payment_failed', 'other'] as const;
type CancelReason = (typeof cancelReasons)[number];
const levels = ['', 'high', 'medium', 'low'] as const;
type LevelFilter = (typeof levels)[number];

/** The quick outcomes under "What happened on the call?" — one tap logs the call (Figma 507:76930). */
const quickOutcomes: [Exclude<CallOutcome, 'confirmed'>, ComponentType<IconProps>][] = [
    ['no_answer', ClockIcon],
    ['switched_off', WifiSlashIcon],
    ['wrong_number', XCircleIcon],
    ['call_back', ArrowCounterClockwiseIcon],
    ['declined', ProhibitIcon],
];

/**
 * Orders to check (Figma B · 05 premium 504:80340, 390: 504:81866): held orders on the left, each
 * saying in one line why it waits; the chosen order on the right with why it is paused, our record
 * and — separately — the couriers' (rule 24), what to confirm, and one tap per call outcome. A
 * person confirms or cancels; the system only holds (rules 23, 24b). Every figure is the server's.
 */
export function VerificationQueuePage() {
    const { t } = useI18n();
    const wide = useWide();
    const [params, setParams] = useSearchParams();
    const levelParam = params.get('level') ?? '';
    const level: LevelFilter = (levels as readonly string[]).includes(levelParam) ? (levelParam as LevelFilter) : '';
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
        <div className={`flex flex-col gap-6 ${wide ? '' : 'pb-20'}`}>
            <ContentHeader
                title={t('risk.queue.title')}
                breadcrumb={crumbs(t, 'risk.tab.queue')}
                subtitle={t('risk.queue.subtitle')}
                actions={
                    <>
                        <Link to="/risk/lookup" className={buttonClass('outline', 'md')}>
                            <MagnifyingGlassIcon size={16} aria-hidden />
                            {t('risk.queue.lookupAction')}
                        </Link>
                        <Link to="/risk/rules" className={buttonClass('outline', 'md')}>
                            <SlidersHorizontalIcon size={16} aria-hidden />
                            {t('risk.tab.rules')}
                        </Link>
                    </>
                }
            />
            <RiskTabs waiting={queue.isSuccess ? count : undefined} />
            <div className="flex flex-wrap items-center justify-between gap-3">
                <SegmentedControl
                    label={t('risk.queue.levelFilter')}
                    value={level}
                    onChange={(v) => set('level', v)}
                    options={levels.map((l) => ({ value: l, label: l === '' ? t('risk.queue.allLevels') : t(`risk.levelName.${l}` as MessageKey) }))}
                />
                <p className="t-admin-caption tabular text-fg-tertiary" aria-live="polite">
                    {t('risk.queue.count', { count })} · {t('risk.queue.oldestFirst')}
                </p>
            </div>
            {queue.isError ? (
                <QueryError error={queue.error} onRetry={() => void queue.refetch()} />
            ) : queue.isSuccess && rows.length === 0 ? (
                <EmptyState reason="allClear" title={t('risk.queue.empty.title')} body={t('risk.queue.empty.body')} />
            ) : (
                <div className="grid items-start gap-5 xl:grid-cols-[--spacing(95)_minmax(0,1fr)]">
                    <ul className="flex flex-col gap-3" aria-label={t('risk.queue.list')}>
                        {rows.map((row) => {
                            const active = selected?.id === row.id;
                            return (
                                <li key={row.id} className="flex flex-col gap-3">
                                    <QueueCard row={row} active={active} onSelect={() => set('order', String(row.id))} />
                                    {/* Narrow screens open the chosen order in place (Figma 390). */}
                                    {!wide && active && <HeldPanel id={row.id} />}
                                </li>
                            );
                        })}
                        {queue.hasNextPage && (
                            <li>
                                <Button variant="outline" size="sm" onClick={() => void queue.fetchNextPage()} disabled={queue.isFetchingNextPage}>
                                    {t('risk.queue.loadMore')}
                                </Button>
                            </li>
                        )}
                    </ul>
                    {wide && (selected ? <HeldPanel id={selected.id} /> : <p className="t-admin-body text-fg-secondary">{t('risk.queue.pick')}</p>)}
                </div>
            )}
        </div>
    );
}

function QueueCard({ row, active, onSelect }: { row: QueueRow; active: boolean; onSelect: () => void }) {
    const { t, locale } = useI18n();
    const why = holdSentence(row.order_risk.hold_reasons, row.order_risk.signals, t);
    const level = asLevel(row.order_risk.level);
    return (
        <button
            type="button"
            onClick={onSelect}
            aria-pressed={active}
            className={`flex w-full flex-col gap-2 rounded-md bg-surface p-4 text-start shadow-e1 hover:bg-subtle ${active ? 'border-2 border-line-brand bg-brand-subtle' : 'border border-line-default'}`}
        >
            <span className="flex items-center justify-between gap-2">
                <span className="t-admin-mono text-fg-secondary">{row.number}</span>
                {row.order_risk.score !== null && <RiskPill level={level} score={row.order_risk.score} />}
            </span>
            <span className="flex items-center justify-between gap-3">
                <span className="flex min-w-0 flex-col">
                    <span className="truncate t-admin-body-strong text-fg-primary">{row.customer_name}</span>
                    <span className="truncate t-admin-caption tabular text-fg-secondary">{[row.phone, row.district].filter(Boolean).join(' · ')}</span>
                </span>
                <span className="t-admin-body-strong tabular text-fg-primary">{taka(row.grand_total)}</span>
            </span>
            {why && (
                <span className="flex items-start gap-2 t-admin-body text-fg-primary">
                    <WarningIcon size={16} aria-hidden className={`mt-0.5 shrink-0 ${level === 'high' ? 'text-feedback-danger' : 'text-feedback-warning'}`} />
                    {why}
                </span>
            )}
            <span className="t-admin-caption tabular text-fg-tertiary">
                {[
                    row.placed_at ? t('risk.queue.placed', { when: dhakaTime(row.placed_at, locale) }) : null,
                    row.calls.count === 0 ? t('risk.queue.notCalled') : t('risk.queue.calls', { count: row.calls.count }),
                    row.calls.last_outcome ? t('risk.queue.lastCall', { outcome: t(`risk.outcome.${row.calls.last_outcome}` as MessageKey) }) : null,
                ]
                    .filter(Boolean)
                    .join(' · ')}
            </span>
        </button>
    );
}

function HeldPanel({ id }: { id: number }) {
    const { t } = useI18n();
    const held = useHeldOrder(id);
    if (held.isError) return <QueryError error={held.error} onRetry={() => void held.refetch()} />;
    if (!held.data) return <p className="t-admin-body text-fg-secondary" aria-busy="true" />;
    const { order, phone } = held.data;
    return (
        <section className="flex flex-col gap-5 rounded-md border border-line-default bg-surface p-5 shadow-e1 sm:p-6" aria-label={t('risk.call.region')}>
            <CallHeader detail={held.data} />
            <WhyPaused score={order.order_risk.score} level={asLevel(order.order_risk.level)} signals={order.order_risk.signals} holds={order.order_risk.hold_reasons} />
            {phone.status.value !== 'normal' && <ListStatusBanner status={phone.status} phone={phone.pii_masked ? null : order.phone} />}
            <div className="grid items-start gap-4 lg:grid-cols-2">
                <OurRecord ours={phone.ours} />
                <CourierPanel checkedAt={phone.courier.checked_at} providers={phone.courier.providers} compact />
            </div>
            <ConfirmList amount={taka(order.grand_total)} />
            <Outcomes detail={held.data} />
            <CallLog detail={held.data} />
        </section>
    );
}

/** "Call Shahriar Kabir" with the number large, and the two ways to reach them. */
function CallHeader({ detail }: { detail: HeldOrderDetail }) {
    const { t } = useI18n();
    const wide = useWide();
    const { order } = detail;
    const dial = !detail.phone.pii_masked;
    const whatsapp = `https://wa.me/${order.phone.replace(/\D/g, '')}`;
    return (
        <>
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="flex min-w-0 flex-col gap-1">
                    <p className="t-admin-caption-strong tabular text-fg-secondary">{t('risk.call.meta', { number: order.number, amount: taka(order.grand_total) })}</p>
                    <h2 className="t-admin-page-title text-fg-primary">{t('risk.call.title', { name: order.customer_name })}</h2>
                    <p className="t-admin-num tabular text-fg-primary">{order.phone}</p>
                </div>
                {dial && wide && (
                    <div className="flex gap-2">
                        <a href={whatsapp} target="_blank" rel="noreferrer" className={buttonClass('outline', 'md')}>
                            <WhatsappLogoIcon size={16} aria-hidden />
                            {t('risk.call.whatsapp')}
                        </a>
                        <a href={`tel:${order.phone}`} className={buttonClass('primary', 'md')}>
                            <PhoneIcon size={16} aria-hidden />
                            {t('risk.call.callNow')}
                        </a>
                    </div>
                )}
            </div>
            {/* Narrow screens: the two calls stay in reach at the bottom (Figma 390 sticky bar). */}
            {dial && !wide && (
                <div className="fixed inset-x-0 bottom-0 z-30 flex gap-2 border-t border-line-default bg-surface p-4">
                    <a href={whatsapp} target="_blank" rel="noreferrer" className={`${buttonClass('outline', 'lg')} flex-1`}>
                        <WhatsappLogoIcon size={20} aria-hidden />
                        {t('risk.call.whatsapp')}
                    </a>
                    <a href={`tel:${order.phone}`} className={`${buttonClass('primary', 'lg')} flex-1`}>
                        <PhoneIcon size={20} aria-hidden />
                        {t('risk.call.callNow')}
                    </a>
                </div>
            )}
        </>
    );
}

/** What to confirm on the call — a short checklist instead of a script paragraph. */
function ConfirmList({ amount }: { amount: string }) {
    const { t } = useI18n();
    return (
        <section aria-labelledby="risk-confirm" className="flex flex-col gap-2 rounded-md bg-subtle p-5">
            <p id="risk-confirm" className="flex items-center gap-2 t-admin-caption-strong text-fg-secondary">
                <ChatCircleDotsIcon size={16} aria-hidden />
                {t('risk.confirm.title')}
            </p>
            <ol className="flex list-decimal flex-col gap-1.5 ps-5 t-admin-body text-fg-primary">
                <li>{t('risk.confirm.who')}</li>
                <li>{t('risk.confirm.items')}</li>
                <li>{t('risk.confirm.cash', { amount })}</li>
            </ol>
        </section>
    );
}

/**
 * "What happened on the call?": confirm (verifies and confirms the order), or one tap for each
 * other outcome (logs the call). Cancelling stays a separate, deliberate step with a reason.
 */
function Outcomes({ detail }: { detail: HeldOrderDetail }) {
    const { t } = useI18n();
    const wide = useWide();
    const { notify } = useToast();
    const { data: me } = useMe();
    const { order } = detail;
    const verify = useVerify();
    const log = useLogCall();
    const reject = useReject();
    const [note, setNote] = useState('');
    const [more, setMore] = useState(false);
    const [cancelOpen, setCancelOpen] = useState(false);
    const [blacklistOpen, setBlacklistOpen] = useState(false);
    const [reason, setReason] = useState<CancelReason | ''>('');
    const [cancelNote, setCancelNote] = useState('');
    const fail = (e: unknown) => notify(e instanceof ApiError ? (e.problem.detail ?? e.problem.title) : t('risk.error'), 'danger');
    // Verifying confirms the order (owner decision 2026-11-13): risk.verify + orders.confirm.
    const mayVerify = can(me, 'risk.verify') && can(me, 'orders.confirm');
    const mayCancel = mayVerify && can(me, 'orders.cancel');
    const busy = verify.isPending || log.isPending;
    const logOutcome = (outcome: CallOutcome) =>
        log.mutate(
            { id: order.id, input: { outcome, note: note.trim() || null } },
            { onSuccess: () => (notify(t('risk.call.logged', { outcome: t(`risk.outcome.${outcome}` as MessageKey) })), setNote('')), onError: fail },
        );
    // On a phone the first two outcomes show; the rest open under "More outcomes".
    const shown = wide || more ? quickOutcomes : quickOutcomes.slice(0, 2);

    if (!mayVerify) return null;
    return (
        <section aria-labelledby="risk-outcome" className="flex flex-col gap-3">
            <h3 id="risk-outcome" className="t-admin-body-strong text-fg-primary">
                {t('risk.outcome.title')}
            </h3>
            <Button
                size={wide ? 'md' : 'lg'}
                className="w-full"
                disabled={busy}
                onClick={() =>
                    verify.mutate(
                        { id: order.id, note: note.trim() || null },
                        { onSuccess: () => notify(t('risk.call.verified', { number: order.number })), onError: fail },
                    )
                }
            >
                <CheckCircleIcon size={wide ? 16 : 20} aria-hidden />
                {t('risk.call.verify')}
            </Button>
            <div className="flex flex-wrap gap-2">
                {shown.map(([outcome, Icon]) => (
                    <Button key={outcome} variant="outline" size={wide ? 'sm' : 'lg'} className={wide ? '' : 'flex-1'} disabled={busy} onClick={() => logOutcome(outcome)}>
                        <Icon size={wide ? 16 : 20} aria-hidden />
                        {t(`risk.outcome.${outcome}` as MessageKey)}
                    </Button>
                ))}
            </div>
            {!wide && !more && (
                <Button variant="ghost" size="lg" className="w-full" onClick={() => setMore(true)}>
                    {t('risk.outcome.more')}
                    <CaretDownIcon size={20} aria-hidden />
                </Button>
            )}
            <Field label={t('risk.call.note')} optional>
                {({ id, describedBy, invalid }) => (
                    <Input id={id} aria-describedby={describedBy} aria-invalid={invalid || undefined} value={note} onChange={(e) => setNote(e.target.value)} maxLength={1000} />
                )}
            </Field>
            <div className="flex flex-wrap items-center gap-3">
                <p className="min-w-0 flex-1 t-admin-caption text-fg-tertiary">{t('risk.call.footnote')}</p>
                {mayCancel && (
                    <Button variant="ghost" size="sm" onClick={() => setCancelOpen(true)}>
                        <XCircleIcon size={16} aria-hidden />
                        {t('risk.call.cancel')}
                    </Button>
                )}
                {can(me, 'risk.manage_lists') && !detail.phone.pii_masked && (
                    <Button variant="ghost" size="sm" onClick={() => setBlacklistOpen(true)}>
                        <ProhibitIcon size={16} aria-hidden />
                        {t('risk.call.blacklist')}
                    </Button>
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
                                { id: order.id, input: { reason, note: cancelNote.trim() || null } },
                                { onSuccess: () => (notify(t('risk.cancel.done', { number: order.number })), setCancelOpen(false)), onError: fail },
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

function CallLog({ detail }: { detail: HeldOrderDetail }) {
    const { t, locale } = useI18n();
    const calls = detail.call_log;
    return (
        <div className="flex flex-col gap-1.5 border-t border-line-default pt-4">
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
                                {t('risk.call.by', { who: c.user_name ?? '—', when: c.created_at ? dhakaTime(c.created_at, locale) : '—' })}
                            </span>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
