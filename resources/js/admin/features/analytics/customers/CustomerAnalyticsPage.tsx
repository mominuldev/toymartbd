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
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { AnalyticsTabs } from '../AnalyticsTabs';
import { PlainWords } from '../PlainWords';
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
    const date = (key: string) => (/^\d{4}-\d{2}-\d{2}$/.test(params.get(key) ?? '') ? params.get(key)! : '');
    const custom = { from: date('from'), to: date('to') };
    const report = useCustomerAnalytics(months, custom);
    const today = new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Dhaka', year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date());
    const setParam = (entries: Record<string, string>) =>
        setParams(
            (current) => {
                const next = new URLSearchParams(current);
                for (const [k, v] of Object.entries(entries)) {
                    if (v) next.set(k, v);
                    else next.delete(k);
                }
                return next;
            },
            { replace: true },
        );
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
                        <Select
                            id={`${ids}-months`}
                            value={months}
                            onChange={(e) =>
                                e.target.value === 'custom'
                                    ? setParam({ months: 'custom', from: custom.from || data?.range.from || today, to: custom.to || data?.range.to || today })
                                    : setParams(e.target.value === '6' ? {} : { months: e.target.value }, { replace: true })
                            }
                            className="w-44"
                        >
                            {monthChoices.map((m) => (
                                <option key={m} value={m}>
                                    {t(`customerAnalytics.months.${m}` as MessageKey)}
                                </option>
                            ))}
                        </Select>
                        {months === 'custom' && (
                            <>
                                <label htmlFor={`${ids}-from`} className="sr-only">
                                    {t('reports.period.from')}
                                </label>
                                <div className="w-40 shrink-0">
                                    <Input id={`${ids}-from`} type="date" max={custom.to || today} value={custom.from} onChange={(e) => setParam({ from: e.target.value })} className="tabular" />
                                </div>
                                <label htmlFor={`${ids}-to`} className="sr-only">
                                    {t('reports.period.to')}
                                </label>
                                <div className="w-40 shrink-0">
                                    <Input id={`${ids}-to`} type="date" min={custom.from} max={today} value={custom.to} onChange={(e) => setParam({ to: e.target.value })} className="tabular" />
                                </div>
                            </>
                        )}
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
                    <PlainWords label={t('customerAnalytics.plain.label')} lines={customerSentences(data, t)} />
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

                    <Segments data={data} />

                    <div className="grid grid-cols-1 items-start gap-4 xl:grid-cols-2">
                        <LtvDistribution data={data} />
                        <Cohorts data={data} />
                    </div>
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
                    <BarChart accessibilityLayer={false} data={bars} margin={{ top: 20, right: 4, bottom: 0, left: 4 }}>
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

/** The groups as cards (Figma 518:79062): who they are in words, how many, and what they are worth together. */
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
        <section aria-labelledby={id} className="flex flex-col gap-3">
            <h2 id={id} className="t-admin-section text-fg-primary">
                {t('customerAnalytics.segments.title')}
            </h2>
            <ul className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
                {data.segments.map((row) => (
                    <li key={row.segment} className="flex flex-col gap-2 rounded-md border border-line-default bg-surface p-4 shadow-e1">
                        <SegmentTag segment={row.segment} />
                        <span className="t-admin-num-lg tabular text-fg-primary">{count(row.customers)}</span>
                        <span className="t-admin-caption text-fg-secondary">{rule(row.segment)}</span>
                        <span className="mt-auto t-admin-caption-strong text-fg-primary">
                            {t('customerAnalytics.segments.worth')} <Taka amount={row.lifetime_value} signClass="t-admin-currency-caption" />
                        </span>
                    </li>
                ))}
            </ul>
        </section>
    );
}

function Cohorts({ data }: { data: CustomerAnalytics }) {
    const { t, locale } = useI18n();
    const id = useId();
    const width = Math.max(0, ...data.cohorts.map((c) => c.retention.length));
    return (
        <Panel title={t('customerAnalytics.cohorts.title')} subtitle={t('customerAnalytics.cohorts.subtitle')} titleId={id}>
            {/* Focusable so a keyboard can scroll it when the half-width column is narrower than the table (WCAG scrollable-region-focusable). */}
            <div role="region" aria-labelledby={id} tabIndex={0} className="overflow-x-auto">
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

/**
 * The Customers page's sentences (Figma 518:79062): who bought and who came back, what a customer
 * is worth, and the at-risk group worth a message. Server figures only, put into words.
 */
function customerSentences(data: CustomerAnalytics, t: (k: MessageKey, p?: Record<string, string | number>) => string): string[] {
    const lines: string[] = [];
    const p = data.period;
    if (p.customers > 0) {
        lines.push(
            p.returning_share === null
                ? t('customerAnalytics.plain.period', { customers: count(p.customers), newCount: count(p.new_customers) })
                : t('customerAnalytics.plain.periodReturning', { customers: count(p.customers), returning: count(p.returning_customers), share: percentText(p.returning_share) }),
        );
    }
    const ltv = data.lifetime_value;
    if (ltv.average !== null && ltv.median !== null) lines.push(t('customerAnalytics.plain.worth', { average: takaText(ltv.average), median: takaText(ltv.median) }));
    const atRisk = data.segments.find((s) => s.segment === 'at_risk');
    if (atRisk && atRisk.customers > 0) {
        lines.push(t('customerAnalytics.plain.atRisk', { count: count(atRisk.customers), days: data.thresholds.at_risk_after_days, worth: takaText(atRisk.lifetime_value) }));
    } else if (data.repeat_purchase.rate !== null) {
        lines.push(t('customerAnalytics.plain.repeat', { rate: percentText(data.repeat_purchase.rate), repeat: count(data.repeat_purchase.repeat_customers), buyers: count(data.repeat_purchase.customers_with_delivered) }));
    }
    return lines;
}
