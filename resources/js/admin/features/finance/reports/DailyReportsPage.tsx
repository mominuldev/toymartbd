import { Bar, BarChart, CartesianGrid, Cell, ReferenceLine, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { ContentHeader } from '@/components/shell/ContentHeader';
import { dataTable } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { ExportMenu } from '@/components/ui/ExportMenu';
import { QueryError } from '@/components/ui/QueryError';
import { SegmentedControl } from '@/components/ui/SegmentedControl';
import { Notice, TakaFixed, formatCount } from '../parts';
import { granularities, seriesExportUrl, usePnlSeries, type Granularity, type PnlSeries } from './api';
import { Card, PeriodPicker, Percent, ReportTabs, TAKA, dhakaToday, formatAmount, formatDay, isNegative, useRangeParams } from './parts';

/** The table's money columns (Figma B · 32 Daily reports 427:73672), left to right. */
const columns = [
    ['gross_revenue', 'finUi.daily.col.gross'],
    ['net_revenue', 'finUi.daily.col.net'],
    ['gross_profit', 'finUi.daily.col.grossProfit'],
    ['profit_after_delivery', 'finUi.daily.col.afterDelivery'],
    ['net_profit', 'finUi.daily.col.netProfit'],
] as const;
type Column = (typeof columns)[number][0];
/** Without catalog.view_cost the server withholds the subtotals cost of goods could be backed out of (owner decision 2026-11-16). */
const visibleColumns = (data: PnlSeries) => (data.item_costs_visible ? columns : columns.filter(([c]) => data.totals[c] !== null));

type Bucket = PnlSeries['buckets'][number];

/**
 * Daily reports (Figma B · 32 Daily reports 427:73672) — the P&L by day, week (Saturday–Friday),
 * month or year: Day / Week / Month / Year, the period and Export in the header; the "counted on
 * delivery, not when the cash arrives" notice; net profit per period as bars (profit in
 * bg/brand, a loss in feedback/danger, with a legend); and the table newest first with a total
 * row. Buckets are Dhaka dates and every figure is the server's (rules 1, 11).
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
                breadcrumb={[{ label: t('reports.crumb.money') }, { label: t('reports.crumb.finance') }]}
                title={t('reports.daily.title')}
                subtitle={data && t('finUi.daily.subtitle', { range: label(data) })}
                actions={
                    <>
                        <SegmentedControl
                            label={t('reports.daily.group')}
                            options={granularities.map((g) => ({ value: g, label: t(`reports.daily.group.${g}` as MessageKey) }))}
                            value={active}
                            onChange={(g) => set({ group: g })}
                            className="h-9"
                        />
                        <PeriodPicker range={range} preset={preset} onPreset={choosePreset} onRange={(r) => set({ preset: 'custom', ...r })} />
                        <ExportMenu href={(format, locale) => seriesExportUrl(range, group, { format, locale })} />
                    </>
                }
            />
            <ReportTabs />

            <Notice title={t('finUi.daily.noticeTitle')}>{t('finUi.daily.noticeBody')}</Notice>

            {series.isError ? (
                <QueryError error={series.error} onRetry={() => void series.refetch()} />
            ) : !data ? (
                <p className="t-admin-body text-fg-tertiary" aria-busy="true">
                    {t('reports.loading')}
                </p>
            ) : data.buckets.every((b) => columns.every(([c]) => (b.totals[c] ?? '0.00') === '0.00')) ? (
                <EmptyState title={t('reports.daily.empty')} body={t('finUi.daily.subtitle', { range: label(data) })} />
            ) : (
                <>
                    <Chart data={data} label={label} />
                    <Table data={data} label={label} />
                </>
            )}
        </section>
    );
}

type Label = (bucket: { from: string; to: string }) => string;

/** The axis tick for a bucket: "7" by day, "07 Nov" by week, "Nov 2026" by month, "2026" by year (Western digits, rule 36). */
function axisLabel(from: string, granularity: PnlSeries['granularity'], locale: string): string {
    const options: Intl.DateTimeFormatOptions =
        granularity === 'year' ? { year: 'numeric' } : granularity === 'month' ? { month: 'short', year: 'numeric' } : granularity === 'week' ? { day: '2-digit', month: 'short' } : { day: 'numeric' };
    return new Intl.DateTimeFormat(locale === 'bn' ? 'bn-BD' : 'en-GB', { timeZone: 'UTC', numberingSystem: 'latn', ...options }).format(new Date(`${from}T00:00:00Z`));
}

function Chart({ data, label }: { data: PnlSeries; label: Label }) {
    const { t, locale } = useI18n();
    const points = data.buckets.map((b) => ({ key: b.key, label: label(b), short: axisLabel(b.from, data.granularity, locale), amount: b.totals.net_profit, loss: isNegative(b.totals.net_profit), value: Number(b.totals.net_profit) }));
    return (
        <Card className="flex flex-col gap-3 p-5">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <h2 className="t-admin-section text-fg-primary">{t('reports.daily.chart', { group: t(`reports.daily.group.${data.granularity}` as MessageKey).toLowerCase() })}</h2>
                <ul className="flex items-center gap-4 t-admin-caption text-fg-secondary" aria-hidden>
                    <li className="flex items-center gap-1.5">
                        <span className="size-2.5 rounded-xs bg-brand" />
                        {t('finUi.daily.legend.profit')}
                    </li>
                    <li className="flex items-center gap-1.5">
                        <span className="size-2.5 rounded-xs bg-danger" />
                        {t('finUi.daily.legend.loss')}
                    </li>
                </ul>
            </div>
            <div className="h-64" aria-hidden>
                <ResponsiveContainer width="100%" height="100%">
                    <BarChart accessibilityLayer={false} data={points} margin={{ top: 8, right: 8, bottom: 0, left: 8 }}>
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
                                            <TakaFixed amount={point.amount} />
                                        </p>
                                    </div>
                                ) : null;
                            }}
                        />
                        <Bar dataKey="value" maxBarSize={36} radius={[4, 4, 0, 0]} isAnimationActive={false}>
                            {points.map((p) => (
                                <Cell key={p.key} fill={p.loss ? 'var(--color-danger)' : 'var(--color-brand)'} />
                            ))}
                        </Bar>
                    </BarChart>
                </ResponsiveContainer>
            </div>
        </Card>
    );
}

/** Axis text in Admin/caption, text/secondary; money ticks compact past 10,000 ("20k"). */
function Tick({ x, y, payload, anchor, money = false }: { x?: number; y?: number; payload?: { value: string | number }; anchor: 'middle' | 'end'; money?: boolean }) {
    const raw = payload?.value ?? '';
    let text = String(raw);
    if (money) {
        const n = Number(raw);
        const abs = Math.abs(n);
        const body = abs >= 1000 ? `${formatAmount(String(Math.round(abs / 1000)))}k` : formatAmount(String(abs));
        text = `${n < 0 ? '−' : ''}${TAKA}${body}`;
    }
    return (
        <text x={x} y={(y ?? 0) + (anchor === 'middle' ? 12 : 4)} dx={anchor === 'end' ? -4 : 0} textAnchor={anchor} className="t-admin-caption tabular" fill="var(--color-fg-secondary)">
            {text}
        </text>
    );
}

/** "Mon 16 Nov" for one Dhaka day (Western digits). */
function dayLabel(ymd: string, locale: string): string {
    return new Intl.DateTimeFormat(locale === 'bn' ? 'bn-BD' : 'en-GB', { timeZone: 'UTC', numberingSystem: 'latn', weekday: 'short', day: 'numeric', month: 'short' }).format(new Date(`${ymd}T00:00:00Z`));
}

function Table({ data, label }: { data: PnlSeries; label: Label }) {
    const { t, locale } = useI18n();
    const cols = visibleColumns(data);
    const today = dhakaToday();
    const num = `${dataTable.td} h-11 t-admin-num text-end`;
    const end = dataTable.th.replace('[:where(&)]:text-start', 'text-end');
    // Newest first, as Figma lists it; the server sends the buckets oldest first.
    const rows: Bucket[] = [...data.buckets].reverse();
    const rowLabel = (b: Bucket) => {
        if (data.granularity !== 'day') return label(b);
        const day = dayLabel(b.from, locale);
        return b.from === today ? t('finUi.daily.today', { day }) : day;
    };
    const money = (amount: string | null, strong = false) => (
        <span className={`${isNegative(amount ?? '0.00') ? 'text-feedback-danger' : ''} ${strong ? 't-admin-body-strong' : ''}`}>
            <TakaFixed amount={amount ?? '0.00'} />
        </span>
    );

    return (
        <div className="flex flex-col gap-3">
            {/* Scrolls sideways at 768: keyboard users must be able to reach it (axe scrollable-region-focusable). */}
            <div role="region" aria-label={t('reports.daily.title')} tabIndex={0} className={`${dataTable.card} overflow-x-auto`}>
                <table className={dataTable.table}>
                    <thead className={dataTable.head}>
                        <tr className="border-b border-line-default">
                            <th scope="col" className={dataTable.th}>
                                {t(`finUi.daily.col.${data.granularity}` as MessageKey)}
                            </th>
                            {cols.map(([c, key]) => (
                                <th key={c} scope="col" className={end}>
                                    {t(key)}
                                </th>
                            ))}
                            <th scope="col" className={end}>
                                {t('finUi.daily.col.margin')}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        {rows.map((b) => (
                            <tr key={b.key} className={dataTable.row}>
                                <th scope="row" className={`${dataTable.td} h-11 t-admin-body text-start font-normal whitespace-nowrap`}>
                                    {rowLabel(b)}
                                </th>
                                {cols.map(([c]) => (
                                    <td key={c} className={num}>
                                        {money(b.totals[c as Column] ?? null)}
                                    </td>
                                ))}
                                <td className={num}>
                                    <span className={isNegative(b.net_margin_percent ?? '0') ? 'text-feedback-danger' : ''}>
                                        <Percent value={b.net_margin_percent} />
                                    </span>
                                </td>
                            </tr>
                        ))}
                        <tr className="border-t border-line-strong bg-subtle">
                            <th scope="row" className={`${dataTable.td} t-admin-body-strong text-start`}>
                                {t('finUi.daily.total', { range: label(data) })}
                            </th>
                            {cols.map(([c]) => (
                                <td key={c} className={`${dataTable.td} t-admin-num text-end`}>
                                    {money(data.totals[c as Column] ?? null, true)}
                                </td>
                            ))}
                            <td className={`${dataTable.td} text-end t-admin-body text-fg-tertiary`}>—</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p className="t-admin-caption text-fg-secondary">{t('finUi.daily.showing', { count: formatCount(rows.length) })}</p>
        </div>
    );
}
