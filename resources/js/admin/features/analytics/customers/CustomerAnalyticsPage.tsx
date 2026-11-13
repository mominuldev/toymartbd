import { useId } from 'react';
import { Link, useSearchParams } from 'react-router';
import { Bar, BarChart, LabelList, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { can, useMe } from '@/features/auth/api';
import { Initials, Panel, SegmentTag, Stat, Taka, count, dhakaDay, monthLabel, percentText, takaText, type Segment } from '@/features/customers';
import { ContentHeader } from '@/components/shell/ContentHeader';
import { dataTable } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { QueryError } from '@/components/ui/QueryError';
import { Select } from '@/components/ui/Select';
import { AnalyticsTabs } from '../AnalyticsTabs';
import { monthChoices, useCustomerAnalytics, type CustomerAnalytics, type Months } from './api';

/**
 * A retention rate's cell tint: four steps of the brand colour by share — a display choice on
 * the server's percentage, never a recomputation. Text stays text/primary on every step.
 */
function cellTone(rate: string | null): string {
    if (rate === null) return 'bg-subtle';
    const value = Number.parseFloat(rate);
    if (value >= 50) return 'bg-brand/40';
    if (value >= 25) return 'bg-brand/25';
    if (value > 0) return 'bg-brand/10';
    return 'bg-subtle';
}

/**
 * Analytics → Customers (P8; no frame of its own, built in the B · 24 Analytics 241:30584
 * language: ContentHeader with the period control, the tab strip, StatCards, ChartCard and
 * DataTables). Lifetime value is delivered revenue from the ledger, and every label says so;
 * cohorts are Dhaka months. Every figure is the server's (rule 1).
 */
export function CustomerAnalyticsPage() {
    const { t, locale } = useI18n();
    const { data: me } = useMe();
    const [params, setParams] = useSearchParams();
    const months: Months = (monthChoices as readonly string[]).includes(params.get('months') ?? '') ? (params.get('months') as Months) : '6';
    const report = useCustomerAnalytics(months);
    const data = report.data;
    const ids = useId();

    return (
        <section className="flex flex-col gap-6">
            <ContentHeader
                breadcrumb={[{ label: t('customerAnalytics.crumb') }, { label: t('customerAnalytics.title') }]}
                title={t('customerAnalytics.title')}
                subtitle={
                    data
                        ? t('customerAnalytics.subtitle', { from: monthLabel(data.range.from.slice(0, 7), locale), to: monthLabel(data.range.to.slice(0, 7), locale) })
                        : t('customerAnalytics.subtitleLoading')
                }
                actions={
                    <>
                        <label htmlFor={`${ids}-months`} className="sr-only">
                            {t('customerAnalytics.months')}
                        </label>
                        <Select id={`${ids}-months`} value={months} onChange={(e) => setParams(e.target.value === '6' ? {} : { months: e.target.value }, { replace: true })} className="w-44">
                            {monthChoices.map((m) => (
                                <option key={m} value={m}>
                                    {t(`customerAnalytics.months.${m}` as MessageKey)}
                                </option>
                            ))}
                        </Select>
                    </>
                }
            />
            <AnalyticsTabs />

            {report.isError ? (
                <QueryError error={report.error} onRetry={() => void report.refetch()} />
            ) : !data ? (
                <p className="t-admin-body text-fg-tertiary" aria-busy="true">
                    {t('common.loading')}
                </p>
            ) : data.segments.every((s) => s.customers === 0) ? (
                <EmptyState title={t('customerAnalytics.empty')} body={t('customerAnalytics.emptyBody')} />
            ) : (
                <>
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
                        <Stat
                            label={t('customerAnalytics.stat.ltv')}
                            value={data.lifetime_value.average === null ? <span className="text-fg-tertiary">—</span> : <Taka amount={data.lifetime_value.average} signClass="t-admin-currency-num-lg" />}
                            hint={t('customerAnalytics.stat.ltvHint', { median: data.lifetime_value.median === null ? '—' : takaText(data.lifetime_value.median), count: count(data.lifetime_value.customers_with_value) })}
                        />
                        <Stat
                            label={t('customerAnalytics.stat.repeat')}
                            value={data.repeat_purchase.rate === null ? <span className="text-fg-tertiary">—</span> : percentText(data.repeat_purchase.rate)}
                            hint={t('customerAnalytics.stat.repeatHint', { repeat: count(data.repeat_purchase.repeat_customers), buyers: count(data.repeat_purchase.customers_with_delivered) })}
                        />
                        <Stat
                            label={t('customerAnalytics.stat.returning')}
                            value={data.period.returning_share === null ? <span className="text-fg-tertiary">—</span> : percentText(data.period.returning_share)}
                            hint={t('customerAnalytics.stat.returningHint', { returning: count(data.period.returning_customers), customers: count(data.period.customers) })}
                        />
                        <Stat label={t('customerAnalytics.stat.new')} value={count(data.period.new_customers)} hint={t('customerAnalytics.stat.newHint')} />
                    </div>

                    <div className="grid grid-cols-1 items-start gap-4 xl:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
                        <LtvDistribution data={data} />
                        <Segments data={data} />
                    </div>

                    <Cohorts data={data} />
                    <TopCustomers data={data} canOpen={can(me, 'customers.view')} />
                    <p className="t-admin-caption text-fg-secondary">{t('customerAnalytics.note')}</p>
                </>
            )}
        </section>
    );
}

function bandLabel(bucket: CustomerAnalytics['lifetime_value']['buckets'][number], t: (k: MessageKey, v?: Record<string, string | number>) => string): string {
    return bucket.to === null ? t('customerAnalytics.ltv.bucketOpen', { from: takaText(bucket.from) }) : t('customerAnalytics.ltv.bucket', { from: takaText(bucket.from), to: takaText(bucket.to) });
}

/** ChartCard Bar (189:361 family): customers per lifetime-value band, one series in bg/brand, labelled; a hidden table for screen readers. */
function LtvDistribution({ data }: { data: CustomerAnalytics }) {
    const { t } = useI18n();
    const id = useId();
    const bars = data.lifetime_value.buckets.map((b) => ({ key: b.from, label: bandLabel(b, t), customers: b.customers, value: b.lifetime_value }));
    return (
        <Panel title={t('customerAnalytics.ltv.title')} subtitle={t('customerAnalytics.ltv.subtitle')} titleId={id}>
            <div className="h-56" aria-hidden>
                <ResponsiveContainer width="100%" height="100%">
                    <BarChart data={bars} margin={{ top: 20, right: 4, bottom: 0, left: 4 }}>
                        <XAxis dataKey="label" axisLine={false} tickLine={false} tick={<AxisTick />} interval={0} />
                        <YAxis hide allowDecimals={false} />
                        <Tooltip
                            cursor={{ fill: 'var(--color-subtle)' }}
                            content={({ active, payload }) => {
                                const bar = active ? (payload?.[0]?.payload as (typeof bars)[number] | undefined) : undefined;
                                return bar ? (
                                    <div className="rounded-sm border border-line-default bg-surface px-3 py-2 shadow-e2">
                                        <p className="t-admin-caption text-fg-secondary">{bar.label}</p>
                                        <p className="t-admin-num text-fg-primary tabular">{count(bar.customers)}</p>
                                        <p className="t-admin-caption text-fg-secondary">
                                            <Taka amount={bar.value} signClass="t-admin-currency-caption" />
                                        </p>
                                    </div>
                                ) : null;
                            }}
                        />
                        <Bar dataKey="customers" fill="var(--color-brand)" maxBarSize={48} radius={[6, 6, 0, 0]} isAnimationActive={false}>
                            <LabelList dataKey="customers" content={<BarValue />} />
                        </Bar>
                    </BarChart>
                </ResponsiveContainer>
            </div>
            <table className="sr-only">
                <caption>{t('customerAnalytics.ltv.table')}</caption>
                <thead>
                    <tr>
                        <th scope="col">{t('customerAnalytics.ltv.col.band')}</th>
                        <th scope="col">{t('customerAnalytics.ltv.col.customers')}</th>
                        <th scope="col">{t('customerAnalytics.ltv.col.value')}</th>
                    </tr>
                </thead>
                <tbody>
                    {bars.map((bar) => (
                        <tr key={bar.key}>
                            <th scope="row">{bar.label}</th>
                            <td>{count(bar.customers)}</td>
                            <td>{takaText(bar.value)}</td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </Panel>
    );
}

function AxisTick({ x, y, payload }: { x?: number; y?: number; payload?: { value: string } }) {
    return (
        <text x={x} y={(y ?? 0) + 12} textAnchor="middle" className="t-admin-caption" fill="var(--color-fg-tertiary)">
            {payload?.value}
        </text>
    );
}

function BarValue({ x, y, width, value }: { x?: number | string; y?: number | string; width?: number | string; value?: unknown }) {
    const cx = Number(x ?? 0) + Number(width ?? 0) / 2;
    return (
        <text x={cx} y={Number(y ?? 0) - 6} textAnchor="middle" className="t-admin-caption tabular" fill="var(--color-fg-primary)">
            {count(Number(value ?? 0))}
        </text>
    );
}

function Segments({ data }: { data: CustomerAnalytics }) {
    const { t } = useI18n();
    const id = useId();
    const rule = (segment: Segment): string => {
        if (segment === 'lapsed') return t('customerAnalytics.segments.rule.lapsed', { days: data.thresholds.lapsed_after_days });
        if (segment === 'at_risk') return t('customerAnalytics.segments.rule.at_risk', { days: data.thresholds.at_risk_after_days });
        if (segment === 'vip') return t('customerAnalytics.segments.rule.vip', { amount: takaText(data.thresholds.vip_min_lifetime_value) });
        return t(`customerAnalytics.segments.rule.${segment}` as MessageKey);
    };
    return (
        <Panel title={t('customerAnalytics.segments.title')} subtitle={t('customerAnalytics.segments.subtitle')} titleId={id}>
            <div className="overflow-x-auto">
                <table className={dataTable.table}>
                    <thead>
                        <tr className="border-b border-line-default">
                            <th scope="col" className={dataTable.th}>
                                {t('customerAnalytics.segments.col.segment')}
                            </th>
                            <th scope="col" className={`${dataTable.th} text-end`}>
                                {t('customerAnalytics.segments.col.customers')}
                            </th>
                            <th scope="col" className={`${dataTable.th} text-end`}>
                                {t('customerAnalytics.segments.col.ltv')}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        {data.segments.map((row) => (
                            <tr key={row.segment} className="border-t border-line-default">
                                <th scope="row" className="px-3 py-2 text-start align-middle">
                                    <span className="flex flex-col items-start gap-1">
                                        <SegmentTag segment={row.segment} />
                                        <span className="t-admin-caption text-fg-secondary">{rule(row.segment)}</span>
                                    </span>
                                </th>
                                <td className="px-3 py-2 text-end t-admin-num tabular">{count(row.customers)}</td>
                                <td className="px-3 py-2 text-end t-admin-num">
                                    <Taka amount={row.lifetime_value} />
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </Panel>
    );
}

function Cohorts({ data }: { data: CustomerAnalytics }) {
    const { t, locale } = useI18n();
    const id = useId();
    const width = Math.max(0, ...data.cohorts.map((c) => c.retention.length));
    return (
        <Panel title={t('customerAnalytics.cohorts.title')} subtitle={t('customerAnalytics.cohorts.subtitle')} titleId={id}>
            <div className="overflow-x-auto">
                <table className="w-full border-collapse">
                    <thead>
                        <tr className="border-b border-line-default">
                            <th scope="col" className={dataTable.th}>
                                {t('customerAnalytics.cohorts.col.cohort')}
                            </th>
                            <th scope="col" className={`${dataTable.th} text-end`}>
                                {t('customerAnalytics.cohorts.col.customers')}
                            </th>
                            <th scope="col" className={`${dataTable.th} text-end`}>
                                {t('customerAnalytics.cohorts.col.avgLtv')}
                            </th>
                            {Array.from({ length: width }, (_, k) => (
                                <th key={k} scope="col" className={`${dataTable.th} text-center`}>
                                    {t('customerAnalytics.cohorts.month', { n: k })}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody>
                        {data.cohorts.map((cohort) => (
                            <tr key={cohort.month} className="border-t border-line-default">
                                <th scope="row" className="px-3 py-2 text-start t-admin-body-strong whitespace-nowrap text-fg-primary">
                                    {monthLabel(cohort.month, locale)}
                                </th>
                                <td className="px-3 py-2 text-end t-admin-num tabular">{count(cohort.customers)}</td>
                                <td className="px-3 py-2 text-end t-admin-num">{cohort.average_lifetime_value === null ? <span className="text-fg-tertiary">—</span> : <Taka amount={cohort.average_lifetime_value} />}</td>
                                {Array.from({ length: width }, (_, k) => {
                                    const cell = cohort.retention[k];
                                    if (!cell) return <td key={k} className="p-0.5" />;
                                    const label = cell.rate === null ? '—' : percentText(cell.rate);
                                    return (
                                        <td key={k} className="p-0.5">
                                            <span
                                                title={t('customerAnalytics.cohorts.cell', { rate: label, customers: count(cell.customers), size: count(cohort.customers), month: monthLabel(cell.month, locale) })}
                                                className={`flex h-9 min-w-14 items-center justify-center rounded-xs t-admin-caption-strong tabular text-fg-primary ${cellTone(cell.rate)}`}
                                            >
                                                {label}
                                            </span>
                                        </td>
                                    );
                                })}
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </Panel>
    );
}

function TopCustomers({ data, canOpen }: { data: CustomerAnalytics; canOpen: boolean }) {
    const { t, locale } = useI18n();
    const id = useId();
    return (
        <section aria-labelledby={id} className="flex flex-col gap-3">
            <h2 id={id} className="t-admin-section text-fg-primary">
                {t('customerAnalytics.top.title')}
            </h2>
            {data.top_customers.length === 0 ? (
                <EmptyState title={t('customerAnalytics.top.empty')} />
            ) : (
                <div className={dataTable.card}>
                    <div className="overflow-x-auto">
                        <table className={dataTable.table}>
                            <thead className={dataTable.head}>
                                <tr className="border-b border-line-strong">
                                    <th scope="col" className={dataTable.th}>
                                        {t('customerAnalytics.top.col.customer')}
                                    </th>
                                    <th scope="col" className={`${dataTable.th} text-end`}>
                                        {t('customerAnalytics.top.col.orders')}
                                    </th>
                                    <th scope="col" className={`${dataTable.th} text-end`}>
                                        {t('customerAnalytics.top.col.delivered')}
                                    </th>
                                    <th scope="col" className={`${dataTable.th} text-end`}>
                                        {t('customerAnalytics.top.col.ltv')}
                                    </th>
                                    <th scope="col" className={dataTable.th}>
                                        {t('customerAnalytics.top.col.lastOrder')}
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {data.top_customers.map((row) => (
                                    <tr key={row.id} className={dataTable.row}>
                                        <td className={dataTable.td}>
                                            <div className="flex items-center gap-2.5">
                                                <Initials name={row.name} />
                                                <div className="flex min-w-0 flex-col">
                                                    {canOpen ? (
                                                        <Link to={`/customers/${row.id}`} className="truncate t-admin-body-strong text-fg-primary hover:text-fg-link hover:underline">
                                                            {row.name}
                                                        </Link>
                                                    ) : (
                                                        <span className="truncate t-admin-body-strong text-fg-primary">{row.name}</span>
                                                    )}
                                                    <span className="t-admin-caption text-fg-secondary tabular">{row.phone_masked}</span>
                                                </div>
                                            </div>
                                        </td>
                                        <td className={`${dataTable.td} t-admin-num text-end tabular`}>{count(row.orders_count)}</td>
                                        <td className={`${dataTable.td} t-admin-num text-end tabular`}>{count(row.delivered_orders_count)}</td>
                                        <td className={`${dataTable.td} t-admin-num text-end`}>
                                            <Taka amount={row.lifetime_value} />
                                        </td>
                                        <td className={`${dataTable.td} t-admin-body tabular`}>{row.last_order_at ? dhakaDay(row.last_order_at, locale) : '—'}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            )}
        </section>
    );
}
