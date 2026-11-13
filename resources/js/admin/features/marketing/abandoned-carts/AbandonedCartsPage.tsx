import { useMemo } from 'react';
import { Link, useSearchParams } from 'react-router';
import { ChatTextIcon, ProhibitIcon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { ContentHeader } from '@/components/shell/ContentHeader';
import { Button } from '@/components/ui/Button';
import { DataTableFooter, dataTable } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { QueryError } from '@/components/ui/QueryError';
import { Select } from '@/components/ui/Select';
import { StatusChip, type StatusTone } from '@/components/ui/StatusChip';
import { useRecoveries, useRecoverySummary, type CartRecovery, type RecoverySummary } from '../api';
import { MarketingTabs, card, crumbs, dhakaDate, dhakaDateTime, taka } from '../parts';

const kpiCard = 'flex flex-col gap-1 rounded-md border border-line-default bg-surface p-4 shadow-e1';

/**
 * Abandoned carts (Figma B · 21 241:42726): four KPI cards for a Dhaka-date range, the
 * recovery sequence (one consented SMS per cart), and the list. Recovered revenue counts
 * delivered orders only — every figure is the server's report.
 */
export function AbandonedCartsPage() {
    const { t } = useI18n();
    const [params, setParams] = useSearchParams();
    const from = params.get('from') ?? dhakaDate(-6);
    const to = params.get('to') ?? dhakaDate(0);
    const status = params.get('status') ?? '';
    const summary = useRecoverySummary(from, to);
    const list = useRecoveries(status);
    const rows = useMemo(() => list.data?.pages.flatMap((p) => p.data) ?? [], [list.data]);

    const set = (key: string, value: string) =>
        setParams((current) => {
            const next = new URLSearchParams(current);
            if (value) next.set(key, value);
            else next.delete(key);
            return next;
        });

    return (
        <div className="flex flex-col gap-6">
            <ContentHeader title={t('mkt.ab.title')} breadcrumb={crumbs(t, 'mkt.tab.abandoned')} subtitle={t('mkt.ab.subtitle')} />
            <MarketingTabs />
            <fieldset className="flex flex-wrap items-end gap-3">
                <legend className="sr-only">{t('mkt.ab.range')}</legend>
                <label className="flex items-center gap-2 t-admin-caption text-fg-secondary">
                    {t('mkt.ab.from')}
                    <Input size="sm" type="date" value={from} max={to} onChange={(e) => set('from', e.target.value)} className="tabular" />
                </label>
                <label className="flex items-center gap-2 t-admin-caption text-fg-secondary">
                    {t('mkt.ab.to')}
                    <Input size="sm" type="date" value={to} min={from} onChange={(e) => set('to', e.target.value)} className="tabular" />
                </label>
            </fieldset>
            {summary.isError ? <QueryError error={summary.error} onRetry={() => void summary.refetch()} /> : <Kpis data={summary.data} />}

            <section className={card} aria-labelledby="mkt-sequence">
                <div className="flex flex-col gap-1">
                    <h2 id="mkt-sequence" className="t-admin-section text-fg-primary">
                        {t('mkt.ab.sequence')}
                    </h2>
                    <p className="t-admin-caption text-fg-secondary">{t('mkt.ab.sequenceSub')}</p>
                </div>
                <div className="flex flex-wrap items-center gap-3">
                    <div className="flex w-full flex-col gap-1 rounded-sm bg-subtle px-4 py-3 sm:w-1/3">
                        <span className="flex items-center gap-2 t-admin-body-strong text-fg-primary">
                            <ChatTextIcon size={16} aria-hidden />
                            {t('mkt.ab.step1')}
                        </span>
                        <span className="t-admin-caption text-fg-secondary">{t('mkt.ab.step1Body')}</span>
                    </div>
                </div>
            </section>

            <div className="flex flex-wrap items-center justify-end gap-3">
                <label className="flex items-center gap-2 t-admin-caption text-fg-secondary">
                    {t('mkt.ab.statusFilter')}
                    <Select size="sm" value={status} onChange={(e) => set('status', e.target.value)}>
                        <option value="">{t('mkt.all')}</option>
                        <option value="messaged">{t('mkt.ab.filter.messaged')}</option>
                        <option value="skipped">{t('mkt.ab.filter.skipped')}</option>
                        <option value="recovered">{t('mkt.ab.filter.recovered')}</option>
                    </Select>
                </label>
            </div>
            {list.isError ? (
                <QueryError error={list.error} onRetry={() => void list.refetch()} />
            ) : list.isSuccess && rows.length === 0 ? (
                <EmptyState reason="allClear" title={t('mkt.ab.empty.title')} body={t('mkt.ab.empty.body')} />
            ) : (
                <div className={dataTable.card}>
                    <div className="overflow-x-auto">
                        <table className={dataTable.table}>
                            <thead className={dataTable.head}>
                                <tr>
                                    <th className={dataTable.th}>{t('mkt.ab.col.customer')}</th>
                                    <th className={dataTable.th}>{t('mkt.ab.col.cart')}</th>
                                    <th className={`${dataTable.th} text-end`}>{t('mkt.ab.col.value')}</th>
                                    <th className={dataTable.th}>{t('mkt.ab.col.left')}</th>
                                    <th className={dataTable.th}>{t('mkt.ab.col.reminder')}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {rows.map((r) => (
                                    <Row key={r.id} row={r} />
                                ))}
                            </tbody>
                        </table>
                    </div>
                    <DataTableFooter summary={t('mkt.shown', { count: rows.length })}>
                        {list.hasNextPage && (
                            <Button variant="outline" size="sm" onClick={() => void list.fetchNextPage()} disabled={list.isFetchingNextPage}>
                                {t('mkt.loadMore')}
                            </Button>
                        )}
                    </DataTableFooter>
                </div>
            )}
        </div>
    );
}

function Kpis({ data }: { data: RecoverySummary | undefined }) {
    const { t } = useI18n();
    const cells: { label: string; value: string; sub: string }[] = [
        { label: t('mkt.ab.kpi.abandoned'), value: data ? String(data.abandoned) : '—', sub: data ? t('mkt.ab.kpi.abandonedSub', { amount: taka(data.abandoned_value) }) : '' },
        {
            label: t('mkt.ab.kpi.recovered'),
            value: data ? String(data.recovered) : '—',
            sub: data ? t('mkt.ab.kpi.recoveredSub', { amount: taka(data.revenue_recovered), count: data.recovered_delivered }) : '',
        },
        { label: t('mkt.ab.kpi.messaged'), value: data ? String(data.messaged) : '—', sub: data ? t('mkt.ab.kpi.messagedSub', { sent: data.sent, restored: data.restored }) : '' },
        { label: t('mkt.ab.kpi.cost'), value: data ? taka(data.cost) : '—', sub: data ? t('mkt.ab.kpi.costSub', { count: data.sent }) : '' },
    ];
    return (
        <dl className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4" aria-busy={!data}>
            {cells.map((c) => (
                <div key={c.label} className={kpiCard}>
                    <dt className="t-admin-overline text-fg-secondary">{c.label}</dt>
                    <dd className="t-admin-num-xl tabular text-fg-primary">{c.value}</dd>
                    <dd className="t-admin-caption tabular text-fg-secondary">{c.sub}</dd>
                </div>
            ))}
        </dl>
    );
}

function reminder(row: CartRecovery, t: (k: MessageKey) => string): { label: string; tone: StatusTone } {
    if (row.status === 'skipped') return { label: t(`mkt.ab.skip.${row.skip_reason ?? 'no_consent'}` as MessageKey), tone: 'neutral' };
    const s = row.message_status ?? 'queued';
    return { label: t(`mkt.ab.msg.${s}` as MessageKey), tone: s === 'sent' ? 'success' : s === 'failed' || s === 'dead_lettered' ? 'danger' : 'info' };
}

function Row({ row }: { row: CartRecovery }) {
    const { t, locale } = useI18n();
    const r = reminder(row, t);
    return (
        <tr className={dataTable.row}>
            <td className={dataTable.td}>
                <span className="block t-admin-body-strong text-fg-primary">{row.customer_name ?? t('mkt.ab.guest')}</span>
                <span className="block t-admin-caption tabular text-fg-secondary">{row.phone ?? '—'}</span>
            </td>
            <td className={`${dataTable.td} t-admin-body`}>{t('mkt.ab.items', { count: row.item_count })}</td>
            <td className={`${dataTable.td} text-end t-admin-body-strong tabular`}>{taka(row.cart_value)}</td>
            <td className={`${dataTable.td} t-admin-caption tabular text-fg-secondary`}>{dhakaDateTime(row.abandoned_at, locale)}</td>
            <td className={dataTable.td}>
                <div className="flex flex-wrap items-center gap-2">
                    <StatusChip label={r.label} tone={r.tone} icon={row.status === 'skipped' ? ProhibitIcon : undefined} />
                    {row.restored_at && <StatusChip label={t('mkt.ab.linkOpened')} tone="info" showIcon={false} />}
                    {row.recovered_order && (
                        <Link to={`/orders/${row.recovered_order.id}`} className="t-admin-caption text-fg-link hover:underline">
                            {t('mkt.ab.recoveredOrder', { number: row.recovered_order.number })}
                        </Link>
                    )}
                </div>
            </td>
        </tr>
    );
}
