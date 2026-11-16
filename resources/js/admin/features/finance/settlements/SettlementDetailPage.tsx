import { useMemo, useState } from 'react';
import { Link, useParams } from 'react-router';
import { BankIcon, CheckIcon, CoinsIcon, MopedIcon, WarningCircleIcon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { ContentHeader } from '@/components/shell/ContentHeader';
import { Button } from '@/components/ui/Button';
import { DataTableFooter, dataTable } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { KpiCard } from '@/components/ui/KpiCard';
import { QueryError } from '@/components/ui/QueryError';
import { StatusChip, type StatusTone } from '@/components/ui/StatusChip';
import { TabButton, TabList } from '@/components/ui/Tabs';
import { useToast } from '@/components/ui/Toast';
import { FinanceTabs, KpiMoney, Notice, TAKA, TakaFixed, formatCount, formatFixed, shortDate } from '../parts';
import { useConfirmSettlement, useReviewLine, useSettlement, type Settlement, type SettlementLine } from './api';
import { SettlementStatus, periodText, problemText, providerName } from './SettlementsPage';

const views = ['all', 'decide', 'matches', 'decided'] as const;
type View = (typeof views)[number];

const inView = (line: SettlementLine, view: View): boolean => {
    if (view === 'decide') return line.needs_review;
    if (view === 'matches') return line.variance_type === 'none';
    if (view === 'decided') return line.variance_type !== 'none' && line.review_status !== 'pending';
    return true;
};

/**
 * One COD settlement (Figma B · 32 COD settlement detail 426:73739): the statement reference with
 * its state, who uploaded it and when the money came; a tinted notice that says what still blocks
 * "Reconcile"; four KPI cards (COD collected, what the courier kept, net to bank, lines to decide —
 * the server's `line_count` and `pending_by_type`); then every line beside what our shipment
 * expected, with Accept (a short payment only) or Exclude (with a reason). Nothing is adjusted
 * automatically and nothing is booked until "Reconcile" (with an Idempotency-Key).
 */
export function SettlementDetailPage() {
    const { t, locale } = useI18n();
    const { notify } = useToast();
    const id = Number(useParams().id) || null;
    const detail = useSettlement(id);
    const confirm = useConfirmSettlement(id ?? 0);
    const [key] = useState(() => crypto.randomUUID());
    const [view, setView] = useState<View>('all');
    const lines = useMemo(() => detail.data?.items ?? [], [detail.data]);
    const shown = useMemo(() => lines.filter((line) => inView(line, view)), [lines, view]);

    const crumbs = [{ label: t('settlements.crumb.money') }, { label: t('settlements.crumb.finance') }, { label: t('finUi.crumb.settlements'), to: '/finance/settlements' }];
    if (detail.isError)
        return (
            <section className="flex flex-col gap-6">
                <ContentHeader title={t('settlements.title')} breadcrumb={crumbs} />
                <QueryError error={detail.error} onRetry={() => void detail.refetch()} />
            </section>
        );
    const s = detail.data;
    if (!s)
        return (
            <p className="t-admin-body text-fg-tertiary" aria-busy="true">
                {t('common.loading')}
            </p>
        );

    const period = periodText(s, locale);
    const source = t(`settlements.source.${s.source}` as MessageKey);
    const who = s.imported_by ?? '—';
    const open = s.status !== 'reconciled';
    const blocked = open && s.unresolved_count > 0;
    const total = s.line_count ?? lines.length;

    return (
        <section className="flex flex-col gap-6">
            <ContentHeader
                breadcrumb={crumbs}
                title={s.reference}
                titleAddon={<SettlementStatus settlement={s} />}
                subtitle={
                    period
                        ? t('finUi.setd.subtitle', { courier: providerName(s, locale), period, date: shortDate(s.received_at, locale), source, who })
                        : t('finUi.setd.subtitleNoPeriod', { courier: providerName(s, locale), date: shortDate(s.received_at, locale), source, who })
                }
                actions={
                    s.can_reconcile &&
                    open && (
                        <Button
                            variant={blocked ? 'outline' : 'primary'}
                            disabled={blocked || confirm.isPending}
                            onClick={() =>
                                confirm.mutate(key, {
                                    onSuccess: () => notify(t('settlements.detail.reconciled', { reference: s.reference })),
                                    onError: (e) => notify(problemText(e, t('settlements.error')), 'danger'),
                                })
                            }
                        >
                            <CheckIcon size={16} aria-hidden />
                            {t('finUi.setd.reconcile', { reference: s.reference })}
                        </Button>
                    )
                }
            />
            <FinanceTabs />

            {blocked ? (
                <Notice tone="warning" title={t('finUi.setd.decideTitle', { count: formatCount(s.unresolved_count) })}>
                    {t('finUi.setd.decideBody')}
                </Notice>
            ) : open ? (
                <Notice tone="info" title={t('finUi.setd.readyTitle')}>
                    {t('finUi.setd.readyBody')}
                </Notice>
            ) : (
                <Notice tone="success" title={t('finUi.setd.doneTitle', { who: s.confirmed_by ?? '—' })}>
                    {t('finUi.setd.doneBody')}
                </Notice>
            )}

            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <KpiCard icon={CoinsIcon} tone="brand" label={t('finUi.setd.stat.cod')} value={<KpiMoney amount={s.totals.cod_collected} />} hint={t('finUi.setd.stat.codHint', { count: formatCount(total) })} />
                <KpiCard icon={MopedIcon} tone="neutral" label={t('finUi.setd.stat.kept')} value={<KpiMoney amount={s.totals.delivery_charges} />} hint={<KeptHint s={s} />} />
                <KpiCard icon={BankIcon} tone="info" label={t('finUi.setd.stat.net')} value={<KpiMoney amount={s.totals.net_received} />} hint={t('finUi.setd.stat.netHint')} />
                <KpiCard
                    icon={WarningCircleIcon}
                    tone="warning"
                    label={t('finUi.setd.stat.decide')}
                    value={<span className="t-admin-num-lg">{t('finUi.setd.stat.decideValue', { pending: formatCount(s.unresolved_count), total: formatCount(total) })}</span>}
                    hint={<PendingHint s={s} />}
                    onClick={blocked ? () => setView('decide') : undefined}
                    active={view === 'decide'}
                />
            </div>

            <TabList label={t('finUi.setd.lines')}>
                {views.map((v) => (
                    <TabButton key={v} active={view === v} label={t(`finUi.setd.tab.${v}` as MessageKey)} onClick={() => setView(v)} />
                ))}
            </TabList>

            {shown.length === 0 ? (
                <EmptyState title={t('finUi.setd.empty')} body={t('finUi.set.noMatchBody')} />
            ) : (
                <section className={dataTable.card} aria-label={t('finUi.setd.lines')}>
                    <div className="overflow-x-auto">
                        <table className={dataTable.table}>
                            <thead className={dataTable.head}>
                                <tr className="border-b border-line-default">
                                    <th scope="col" className={dataTable.th}>{t('settlements.line.consignment')}</th>
                                    <th scope="col" className={dataTable.th}>{t('settlements.line.order')}</th>
                                    {(['cod', 'delivery', 'codFee', 'return', 'net', 'expected'] as const).map((c) => (
                                        <th key={c} scope="col" className={dataTable.th.replace('[:where(&)]:text-start', 'text-end')}>
                                            {t(`settlements.line.${c}` as MessageKey)}
                                        </th>
                                    ))}
                                    <th scope="col" className={dataTable.th}>{t('finUi.setd.col.match')}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {shown.map((line) => (
                                    <LineRow key={line.id} settlement={s} line={line} />
                                ))}
                            </tbody>
                        </table>
                    </div>
                    <DataTableFooter summary={t('finUi.setd.showing', { count: formatCount(shown.length), total: formatCount(lines.length) })}>
                        <Link to="/finance/settlements" className="t-admin-button-sm text-fg-primary hover:underline">
                            {t('finUi.setd.back')}
                        </Link>
                    </DataTableFooter>
                </section>
            )}
        </section>
    );
}

/** "Plus COD fee ৳389.00 · returns ৳120.00" — the server's totals side by side, never added (rule 1). */
function KeptHint({ s }: { s: Settlement }) {
    const { t } = useI18n();
    return (
        <span className="tabular">
            {[t('finUi.setd.stat.plus'), t('finUi.set.codFee'), `${TAKA}${formatFixed(s.totals.cod_fees)}`, '·', t('finUi.set.returns'), `${TAKA}${formatFixed(s.totals.return_charges)}`].join(' ')}
        </span>
    );
}

/** "Short ৳100.00 · 1 not ours · 1 missing" from the server's `pending_by_type`. */
function PendingHint({ s }: { s: Settlement }) {
    const { t } = useI18n();
    const parts = (s.pending_by_type ?? []).map((p) =>
        p.variance_type === 'short' || p.variance_type === 'over'
            ? `${t(`finUi.setd.pending.${p.variance_type}` as MessageKey)} ${TAKA}${formatFixed(p.amount)}`
            : t(`finUi.setd.pending.${p.variance_type}` as MessageKey, { count: formatCount(p.count) }),
    );
    return <span className="tabular">{parts.length > 0 ? parts.join(' · ') : t('finUi.setd.stat.decideNone')}</span>;
}

const varianceTone = (line: SettlementLine): StatusTone => {
    if (line.variance_type === 'none') return 'success';
    if (line.review_status !== 'pending') return 'neutral';
    return line.variance_type === 'unknown_consignment' || line.variance_type === 'already_settled' || line.variance_type === 'duplicate_line' ? 'danger' : 'anomaly';
};

function LineRow({ settlement, line }: { settlement: Settlement; line: SettlementLine }) {
    const { t } = useI18n();
    const { notify } = useToast();
    const review = useReviewLine(settlement.id);
    const [excluding, setExcluding] = useState(false);
    const [note, setNote] = useState('');
    const variance = line.variance_type !== 'none';
    const decided = variance && line.review_status !== 'pending';
    const missing = line.line_no === null;
    const consignment = line.consignment_id ?? '—';
    const decide = (decision: 'accepted' | 'excluded', reason: string | null) =>
        review.mutate(
            { itemId: line.id, input: { decision, note: reason } },
            {
                onSuccess: () => setExcluding(false),
                onError: (e) => notify(problemText(e, t('settlements.error')), 'danger'),
            },
        );
    const money = (amount: string) => (missing ? <span className="text-fg-tertiary">—</span> : <TakaFixed amount={amount} />);
    const label = decided
        ? t(`settlements.review.${line.review_status}` as MessageKey)
        : t(`settlements.variance.${line.variance_type}` as MessageKey, { amount: formatFixed(line.variance.replace(/^-/, '')) });
    const num = `${dataTable.td} t-admin-num text-end`;

    return (
        <tr className={`${dataTable.row} ${line.needs_review ? 'bg-warning-subtle hover:bg-warning-subtle' : ''}`}>
            <td className={`${dataTable.td} t-admin-mono whitespace-nowrap`}>{consignment}</td>
            <td className={`${dataTable.td} t-admin-mono whitespace-nowrap`}>
                {line.order_id ? (
                    <Link to={`/orders/${line.order_id}`} className="text-fg-link hover:underline">
                        {line.order_number ?? `#${line.order_id}`}
                    </Link>
                ) : (
                    <span className="text-fg-tertiary">—</span>
                )}
            </td>
            <td className={num}>{money(line.cod_amount)}</td>
            <td className={num}>{money(line.charges)}</td>
            <td className={num}>{money(line.cod_fee)}</td>
            <td className={num}>{money(line.return_charge)}</td>
            <td className={num}>
                <span className={line.net.startsWith('-') ? 'text-feedback-danger' : ''}>{money(line.net)}</span>
            </td>
            <td className={num}>{line.expected_net !== null ? <span className="text-fg-secondary"><TakaFixed amount={line.expected_net} /></span> : <span className="text-fg-tertiary">—</span>}</td>
            <td className={dataTable.td}>
                <div className="flex flex-wrap items-center gap-2">
                    <StatusChip tone={varianceTone(line)} label={label} />
                    {variance && settlement.can_reconcile && settlement.status !== 'reconciled' && !decided && !excluding && (
                        <>
                            {line.acceptable && (
                                <Button size="sm" variant="outline" aria-label={t('finUi.setd.acceptLine', { consignment })} disabled={review.isPending} onClick={() => decide('accepted', null)}>
                                    {t('finUi.setd.accept')}
                                </Button>
                            )}
                            <Button size="sm" variant="ghost" aria-label={t('finUi.setd.excludeLine', { consignment })} onClick={() => setExcluding(true)}>
                                {t('settlements.review.exclude')}
                            </Button>
                        </>
                    )}
                </div>
                {decided && line.review_note && <p className="mt-1 t-admin-caption text-fg-secondary">{line.review_note}</p>}
                {excluding && (
                    <form
                        className="mt-2 flex gap-1.5"
                        onSubmit={(e) => {
                            e.preventDefault();
                            if (note.trim()) decide('excluded', note.trim());
                        }}
                    >
                        <Input size="sm" aria-label={t('settlements.review.why')} placeholder={t('settlements.review.why')} value={note} onChange={(e) => setNote(e.target.value)} maxLength={500} />
                        <Button size="sm" type="submit" disabled={!note.trim() || review.isPending}>
                            {t('settlements.review.exclude')}
                        </Button>
                    </form>
                )}
            </td>
        </tr>
    );
}
