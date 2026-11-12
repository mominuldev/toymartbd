import { Bar, BarChart, CartesianGrid, ReferenceLine, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { ContentHeader } from '@/components/shell/ContentHeader';
import { dataTable } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { QueryError } from '@/components/ui/QueryError';
import { SegmentedControl } from '@/components/ui/SegmentedControl';
import { granularities, seriesExportUrl, usePnlSeries, type Granularity, type PnlSeries } from './api';
import { Card, ExportLink, Money, PeriodPicker, Percent, ReportTabs, TAKA, formatAmount, formatDay, isNegative, useRangeParams } from './parts';

const columns = ['net_revenue', 'gross_profit', 'profit_after_delivery', 'profit_after_losses', 'net_profit'] as const;

/**
 * Daily reports — the P&L layout by day, week (Saturday–Friday), month or year
 * (06-admin-feature-checklist §2.13 "[—] Daily reports (P&L layout by day)": not drawn, built
 * from the P&L's tokens and the DataTable 192:532 / ChartCard bar 189:361 patterns). One
 * series — net profit — in bg/brand, so no legend; the table carries every subtotal.
 * Buckets are Dhaka dates; every figure is the server's (rules 1, 11).
 */
export function DailyReportsPage() {
    const { t, locale } = useI18n();
    const { range, preset, params, set, choosePreset } = useRangeParams();
    const groupParam = params.get('group') as Granularity | null;
    const group: Granularity | '' = groupParam && granularities.includes(groupParam) ? groupParam : '';
    const series = usePnlSeries(range, group);
    const data = series.data;
    const active = group || data?.granularity || 'day';

    const label = (bucket: { from: string; to: string }) =>
        bucket.from === bucket.to ? formatDay(bucket.from, locale) : t('reports.period.range', { from: formatDay(bucket.from, locale), to: formatDay(bucket.to, locale) });

    return (
        <section className="flex flex-col gap-6">
            <ContentHeader
                breadcrumb={[{ label: t('reports.crumb.money') }, { label: t('reports.crumb.finance') }, { label: t('reports.daily.title') }]}
                title={t('reports.daily.title')}
                subtitle={data && t('reports.daily.subtitle', { range: label(data) })}
                actions={
                    <>
                        <PeriodPicker range={range} preset={preset} onPreset={choosePreset} onRange={(r) => set({ preset: 'custom', ...r })} />
                        <SegmentedControl
                            label={t('reports.daily.group')}
                            options={granularities.map((g) => ({ value: g, label: t(`reports.daily.group.${g}` as MessageKey) }))}
                            value={active}
                            onChange={(g) => set({ group: g })}
                        />
                        <ExportLink href={seriesExportUrl(range, group)} />
                    </>
                }
            />
            <ReportTabs />

            {series.isError ? (
                <QueryError error={series.error} onRetry={() => void series.refetch()} />
            ) : !data ? (
                <p className="t-admin-body text-fg-tertiary" aria-busy="true">
                    {t('reports.loading')}
                </p>
            ) : data.buckets.every((b) => columns.every((c) => b.totals[c] === '0.00')) ? (
                <EmptyState title={t('reports.daily.empty')} body={t('reports.daily.subtitle', { range: label(data) })} />
            ) : (
                <>
                    <Chart data={data} label={label} />
                    <Table data={data} label={label} />
                    {data.source !== 'ledger' && <p className="t-admin-caption text-fg-tertiary">{t(`reports.daily.source.${data.source}` as MessageKey)}</p>}
                </>
            )}
        </section>
    );
}

type Label = (bucket: { from: string; to: string }) => string;

function Chart({ data, label }: { data: PnlSeries; label: Label }) {
    const { t } = useI18n();
    const points = data.buckets.map((b) => ({ key: b.key, label: label(b), short: b.from.slice(5), amount: b.totals.net_profit, value: Number(b.totals.net_profit) }));
    return (
        <Card className="flex flex-col gap-3 p-5">
            <h2 className="t-admin-section text-fg-primary">{t('reports.daily.chart', { group: t(`reports.daily.group.${data.granularity}` as MessageKey).toLowerCase() })}</h2>
            <div className="h-64" aria-hidden>
                <ResponsiveContainer width="100%" height="100%">
                    <BarChart data={points} margin={{ top: 8, right: 8, bottom: 0, left: 8 }}>
                        <CartesianGrid vertical={false} stroke="var(--color-line-default)" />
                        <XAxis dataKey="short" axisLine={false} tickLine={false} tick={<Tick anchor="middle" />} minTickGap={8} />
                        <YAxis axisLine={false} tickLine={false} width={64} tick={<Tick anchor="end" money />} />
                        <ReferenceLine y={0} stroke="var(--color-line-strong)" />
                        <Tooltip
                            cursor={{ fill: 'var(--color-subtle)' }}
                            content={({ active, payload }) => {
                                const point = active ? (payload?.[0]?.payload as (typeof points)[number] | undefined) : undefined;
                                return point ? (
                                    <div className="rounded-sm border border-line-default bg-surface px-3 py-2 shadow-e2">
                                        <p className="t-admin-caption text-fg-secondary">{point.label}</p>
                                        <p className="t-admin-num text-fg-primary">
                                            <Money amount={point.amount} />
                                        </p>
                                    </div>
                                ) : null;
                            }}
                        />
                        <Bar dataKey="value" fill="var(--color-brand)" maxBarSize={24} radius={[4, 4, 0, 0]} isAnimationActive={false} />
                    </BarChart>
                </ResponsiveContainer>
            </div>
        </Card>
    );
}

/** Axis text in Admin/caption, text/secondary; money ticks compact past 10,000 ("20K"). */
function Tick({ x, y, payload, anchor, money = false }: { x?: number; y?: number; payload?: { value: string | number }; anchor: 'middle' | 'end'; money?: boolean }) {
    const raw = payload?.value ?? '';
    let text = String(raw);
    if (money) {
        const n = Number(raw);
        const abs = Math.abs(n);
        const body = abs >= 10000 ? `${formatAmount(String(Math.round(abs / 1000)))}K` : formatAmount(String(abs));
        text = `${n < 0 ? '−' : ''}${TAKA}${body}`;
    }
    return (
        <text x={x} y={(y ?? 0) + (anchor === 'middle' ? 12 : 4)} dx={anchor === 'end' ? -4 : 0} textAnchor={anchor} className="t-admin-caption tabular" fill="var(--color-fg-secondary)">
            {text}
        </text>
    );
}

function Table({ data, label }: { data: PnlSeries; label: Label }) {
    const { t } = useI18n();
    const num = `${dataTable.td} t-admin-num text-end`;
    return (
        <div className={`${dataTable.card} overflow-x-auto`}>
            <table className={dataTable.table}>
                <thead className={dataTable.head}>
                    <tr className="border-b border-line-strong">
                        <th scope="col" className={dataTable.th}>
                            {t('reports.daily.col.period')}
                        </th>
                        {columns.map((c) => (
                            <th key={c} scope="col" className={`${dataTable.th} text-end`}>
                                {t(`reports.pnl.line.${c}` as MessageKey)}
                            </th>
                        ))}
                        <th scope="col" className={`${dataTable.th} text-end`}>
                            {t('reports.daily.col.margin')}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    {data.buckets.map((b) => (
                        <tr key={b.key} className={dataTable.row}>
                            <th scope="row" className={`${dataTable.td} t-admin-body text-start font-normal whitespace-nowrap`}>
                                {label(b)}
                            </th>
                            {columns.map((c) => (
                                <td key={c} className={`${num} ${c === 'net_profit' && isNegative(b.totals[c]) ? 'font-semibold' : ''}`}>
                                    <Money amount={b.totals[c]} />
                                </td>
                            ))}
                            <td className={num}>
                                <Percent value={b.net_margin_percent} />
                            </td>
                        </tr>
                    ))}
                    <tr className="border-t border-line-strong bg-subtle">
                        <th scope="row" className={`${dataTable.td} t-admin-body-strong text-start`}>
                            {t('reports.daily.total')}
                        </th>
                        {columns.map((c) => (
                            <td key={c} className={`${num} t-admin-body-strong`}>
                                <Money amount={data.totals[c]} />
                            </td>
                        ))}
                        <td className={num} />
                    </tr>
                </tbody>
            </table>
        </div>
    );
}
