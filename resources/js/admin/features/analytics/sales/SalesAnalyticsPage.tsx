import { useId, useState, type ReactNode } from 'react';
import { Area, CartesianGrid, ComposedChart, Line, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import { CaretDownIcon, FunnelSimpleIcon } from '@phosphor-icons/react';
import { Link, useSearchParams } from 'react-router';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import type { Schema } from '@toymart/api-types';
import { can, useMe } from '@/features/auth/api';
import { ContentHeader } from '@/components/shell/ContentHeader';
import { buttonClass } from '@/components/ui/Button';
import { dataTable } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { ExportMenu } from '@/components/ui/ExportMenu';
import { Input } from '@/components/ui/Input';
import { QueryError } from '@/components/ui/QueryError';
import { SegmentedControl } from '@/components/ui/SegmentedControl';
import { Select } from '@/components/ui/Select';
import { AnalyticsTabs } from '../AnalyticsTabs';
import { PlainWords, change } from '../PlainWords';
import { groups, rangePresets, salesExportUrl, useSalesAnalytics, type Group, type PerformanceRow, type RangePreset, type SalesAnalytics, type SalesFilters, type TrendRow } from './api';
import { Delta, Money, Percent, TAKA, dhakaToday, formatAmount, formatCount, formatDay, takaText } from './format';

const DATE = /^\d{4}-\d{2}-\d{2}$/;

/** Filters live in the URL so a report is a shareable link; the server validates every value. */
function useFilters(): [SalesFilters, (entries: Record<string, string>) => void] {
    const [params, setParams] = useSearchParams();
    const date = (key: string) => (DATE.test(params.get(key) ?? '') ? params.get(key)! : '');
    const rangeParam = params.get('range') as RangePreset | null;
    const groupParam = params.get('group') as Group | null;
    const filters: SalesFilters = {
        range: rangeParam && rangePresets.includes(rangeParam) ? rangeParam : '7d',
        from: date('from'),
        to: date('to'),
        group: groupParam && groups.includes(groupParam) ? groupParam : '',
    };
    const set = (entries: Record<string, string>) =>
        setParams(
            (current) => {
                const next = new URLSearchParams(current);
                for (const [key, value] of Object.entries(entries)) {
                    if (value) next.set(key, value);
                    else next.delete(key);
                }
                return next;
            },
            { replace: true },
        );
    return [filters, set];
}

/**
 * Analytics → Sales (Figma B · 24 Analytics 241:30584, Sales tab). Demand ("order value
 * placed") and revenue ("recognised on delivery", from the ledger) are separate figures with
 * separate labels (rule 10); every number is the server's (rule 1); buckets are Dhaka dates
 * with Saturday–Friday weeks (rule 11). The frame's session funnel and traffic cards need a
 * session count the store does not have (consent-gated, no server page views), so those slots
 * carry the sales mixes instead and a note says why conversion is not shown.
 */
export function SalesAnalyticsPage() {
    const { t, locale } = useI18n();
    const { data: me } = useMe();
    const [filters, set] = useFilters();
    const report = useSalesAnalytics(filters);
    const data = report.data;
    const ids = useId();
    const today = dhakaToday();

    const range = data ? (data.range.from === data.range.to ? formatDay(data.range.from, locale) : `${formatDay(data.range.from, locale, false)} – ${formatDay(data.range.to, locale)}`) : '';

    return (
        <section className="flex flex-col gap-6">
            <ContentHeader
                breadcrumb={[{ label: t('courierReport.crumb') }]}
                title={t('sales.title')}
                subtitle={t('sales.subtitle')}
                actions={
                    <>
                        <label htmlFor={`${ids}-range`} className="sr-only">
                            {t('reports.period.label')}
                        </label>
                        <Select
                            id={`${ids}-range`}
                            value={filters.range}
                            onChange={(e) => {
                                const next = e.target.value as RangePreset;
                                set(next === 'custom' ? { range: next, from: filters.from || data?.range.from || today, to: filters.to || data?.range.to || today } : { range: next === '7d' ? '' : next, from: '', to: '' });
                            }}
                            className="w-48"
                        >
                            {rangePresets.map((p) => (
                                <option key={p} value={p}>
                                    {t(`sales.range.${p}` as MessageKey)}
                                </option>
                            ))}
                        </Select>
                        {filters.range === 'custom' && (
                            <>
                                <label htmlFor={`${ids}-from`} className="sr-only">
                                    {t('reports.period.from')}
                                </label>
                                <div className="w-40 shrink-0">
                                    <Input id={`${ids}-from`} type="date" max={filters.to || today} value={filters.from} onChange={(e) => set({ from: e.target.value })} className="tabular" />
                                </div>
                                <label htmlFor={`${ids}-to`} className="sr-only">
                                    {t('reports.period.to')}
                                </label>
                                <div className="w-40 shrink-0">
                                    <Input id={`${ids}-to`} type="date" min={filters.from} max={today} value={filters.to} onChange={(e) => set({ to: e.target.value })} className="tabular" />
                                </div>
                            </>
                        )}
                        {can(me, 'reports.export') && <ExportMenu href={(format, lang) => salesExportUrl(filters, format, lang)} />}
                    </>
                }
            />
            <AnalyticsTabs />

            {report.isError ? (
                <QueryError error={report.error} onRetry={() => void report.refetch()} />
            ) : !data ? (
                <p className="t-admin-body text-fg-tertiary" aria-busy="true">
                    {t('reports.loading')}
                </p>
            ) : data.summary.placed.orders === 0 && data.summary.delivered.orders === 0 ? (
                <EmptyState title={t('sales.empty')} body={t('sales.emptyBody', { range })} />
            ) : (
                <>
                    <PlainWords label={t('sales.plain.label', { range })} lines={salesSentences(data, t)} />
                    <Stats data={data} />
                    <TrendCard data={data} range={range} onGroup={(g) => set({ group: g })} />
                    <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                        <OutcomeSummary data={data} />
                        <BarListCard
                            title={t('sales.channels.title')}
                            subtitle={t('sales.channels.subtitle')}
                            rows={data.channels.map((c) => ({ key: c.source, label: channelLabel(c.source, t), value: c.value, orders: c.orders, percent: c.orders_percent }))}
                        />
                        <BarListCard
                            title={t('sales.payments.title')}
                            subtitle={t('sales.payments.subtitle')}
                            tone="success"
                            rows={data.payment_methods.map((m) => ({ key: m.method, label: t(`orders.method.${m.method}` as MessageKey), value: m.value, orders: m.orders, percent: m.value_percent }))}
                        />
                    </div>
                    <div className="grid grid-cols-1 gap-4 xl:grid-cols-2">
                        <TopCategories data={data} />
                        <DiscountsCard data={data} />
                    </div>
                    <FunnelNote />
                    <details className="group flex flex-col rounded-md border border-line-default bg-surface shadow-e1">
                        <summary className="flex cursor-pointer list-none items-center gap-3 px-5 py-4">
                            <span className="flex-1 t-admin-body-strong text-fg-primary">{t('sales.more.title')}</span>
                            <span className="hidden t-admin-caption text-fg-secondary sm:inline">{t('sales.more.hint')}</span>
                            <CaretDownIcon size={16} aria-hidden className="shrink-0 text-fg-tertiary transition-transform group-open:rotate-180" />
                        </summary>
                        <div className="flex flex-col gap-6 border-t border-line-default p-5">
                            <PerformanceTable title={t('sales.categories.title')} first={t('sales.col.category')} rows={data.categories} fallback={t('sales.noCategory')} profit={data.profit_visible} />
                            <PerformanceTable title={t('sales.brands.title')} first={t('sales.col.brand')} rows={data.brands} fallback={t('sales.noBrand')} profit={data.profit_visible} />
                            <RecommendationsCard data={data} />
                        </div>
                    </details>
                    <p className="t-admin-caption text-fg-secondary">
                        {t('sales.note')}
                        {data.reconciliation.difference !== '0.00' && <> {t('sales.reconciliation', { amount: takaText(data.reconciliation.difference) })}</>}
                        {!data.profit_visible && <> {t('sales.profitHidden')}</>}
                    </p>
                </>
            )}
        </section>
    );
}

/** Figma StatCard (241:30605): overline, 26px figure, delta row, caption. */
function Stat({ label, value, delta, hint }: { label: string; value: ReactNode; delta?: ReactNode; hint: ReactNode }) {
    return (
        <div className="flex min-w-0 flex-col gap-1.5 rounded-md border border-line-default bg-surface p-4 shadow-e1">
            <p className="t-admin-overline text-fg-secondary">{label}</p>
            <p className="t-admin-num-lg text-fg-primary">{value}</p>
            {delta}
            <p className="t-admin-caption text-fg-tertiary tabular">{hint}</p>
        </div>
    );
}

function Stats({ data }: { data: SalesAnalytics }) {
    const { t } = useI18n();
    const s = data.summary;
    const vs = t('sales.vsPrevious');
    return (
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <Stat
                label={t('sales.stat.placed')}
                value={<Money amount={s.placed.net_value} signClass="t-admin-currency-num-lg" />}
                delta={<Delta value={data.deltas.placed_value} caption={vs} />}
                hint={t('sales.stat.placedHint', { orders: formatCount(s.placed.net_orders), cancelled: formatCount(s.placed.cancelled_orders) })}
            />
            <Stat
                label={t('sales.stat.revenue')}
                value={<Money amount={s.recognised.net_revenue} signClass="t-admin-currency-num-lg" />}
                delta={<Delta value={data.deltas.recognised_revenue} caption={vs} />}
                hint={t('sales.stat.revenueHint', { delivered: formatCount(s.delivered.orders) })}
            />
            <Stat
                label={t('sales.stat.aov')}
                value={<Money amount={s.placed.aov} signClass="t-admin-currency-num-lg" />}
                delta={<Delta value={data.deltas.aov} caption={vs} />}
                hint={t('sales.stat.aovHint')}
            />
            <Stat
                label={t('sales.stat.repeat')}
                value={<Percent value={s.customers.repeat_rate} />}
                delta={<Delta value={data.deltas.repeat_rate_points} suffix={t('sales.points')} caption={vs} />}
                hint={t('sales.stat.repeatHint', { returning: formatCount(s.customers.returning), customers: formatCount(s.customers.customers) })}
            />
        </div>
    );
}

/** A card panel (241:30674): radius/md, hairline, e1, 20 padding; title Admin/card-title + caption. */
function Card({ title, subtitle, actions, children, className = '' }: { title: string; subtitle?: string; actions?: ReactNode; children: ReactNode; className?: string }) {
    const id = useId();
    return (
        <section aria-labelledby={id} className={`flex min-w-0 flex-col gap-2.5 rounded-md border border-line-default bg-surface p-5 shadow-e1 ${className}`}>
            <header className="flex flex-wrap items-start gap-2">
                <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                    <h2 id={id} className="t-admin-card-title text-fg-primary">
                        {title}
                    </h2>
                    {subtitle && <p className="t-admin-caption text-fg-tertiary">{subtitle}</p>}
                </div>
                {actions}
            </header>
            {children}
        </section>
    );
}

type Metric = 'value' | 'orders' | 'units';

/** Axis label for a bucket: "07 Nov" by day or week, "Nov 2026" by month (Western digits, rule 36). */
function axisLabel(from: string, group: string, locale: string): string {
    const options: Intl.DateTimeFormatOptions = group === 'month' ? { month: 'short', year: 'numeric' } : { day: '2-digit', month: 'short' };
    return new Intl.DateTimeFormat(locale === 'bn' ? 'bn-BD' : 'en-GB', { timeZone: 'UTC', numberingSystem: 'latn', ...options }).format(new Date(`${from}T00:00:00Z`));
}

/** Figma ChartCard (241:30673): "Order value placed per day", Value/Orders/Units, this period vs previous (dashed). */
function TrendCard({ data, range, onGroup }: { data: SalesAnalytics; range: string; onGroup: (g: Group) => void }) {
    const { t, locale } = useI18n();
    const [metric, setMetric] = useState<Metric>('value');
    const group = data.granularity;
    const pick = (row: TrendRow, previous: boolean): { text: string; plot: number | null } => {
        if (metric === 'value') {
            const v = previous ? row.previous_placed_value : row.placed_value;
            return { text: v === null ? '—' : takaText(v), plot: v === null ? null : Number(v) };
        }
        const v = previous ? (metric === 'orders' ? row.previous_placed_orders : row.previous_units) : metric === 'orders' ? row.placed_orders : row.units;
        return { text: v === null ? '—' : formatCount(v), plot: v };
    };
    // Plot coordinates only — every displayed figure is the server's string.
    const points = data.trend.map((row) => ({ key: row.key, short: axisLabel(row.from, group, locale), row, now: pick(row, false).plot, before: pick(row, true).plot }));
    const total = metric === 'value' ? takaText(data.summary.placed.net_value) : formatCount(metric === 'orders' ? data.summary.placed.net_orders : data.summary.placed.units);
    const previousTotal = metric === 'value' ? takaText(data.previous.placed.net_value) : formatCount(metric === 'orders' ? data.previous.placed.net_orders : data.previous.placed.units);

    return (
        <Card
            title={t(`sales.trend.title.${metric}` as MessageKey, { group: t(`sales.group.${group}` as MessageKey) })}
            subtitle={t('sales.trend.subtitle', { range })}
            actions={
                <div className="flex flex-wrap items-center gap-2">
                    <SegmentedControl label={t('sales.group.label')} options={groups.map((g) => ({ value: g, label: t(`sales.group.${g}.label` as MessageKey) }))} value={group} onChange={onGroup} />
                    <SegmentedControl
                        label={t('sales.trend.metric')}
                        options={(['value', 'orders', 'units'] as const).map((m) => ({ value: m, label: t(`sales.trend.${m}` as MessageKey) }))}
                        value={metric}
                        onChange={setMetric}
                    />
                </div>
            }
        >
            <div className="flex flex-wrap items-center gap-4">
                <span className="flex items-center gap-1.5 t-admin-caption text-fg-secondary">
                    <span aria-hidden className="size-2.5 rounded-3xs bg-brand" />
                    {t('sales.trend.thisPeriod', { total })}
                </span>
                <span className="flex items-center gap-1.5 t-admin-caption text-fg-secondary">
                    <span aria-hidden className="h-0.5 w-3.5 bg-fg-tertiary" />
                    {t('sales.trend.previous', { total: previousTotal })}
                </span>
            </div>
            <div className="h-64" aria-hidden>
                <ResponsiveContainer width="100%" height="100%">
                    <ComposedChart accessibilityLayer={false} data={points} margin={{ top: 8, right: 8, bottom: 0, left: 0 }}>
                        <CartesianGrid vertical={false} stroke="var(--color-line-default)" />
                        <XAxis dataKey="short" axisLine={false} tickLine={false} tick={<Tick anchor="middle" />} minTickGap={12} />
                        <YAxis axisLine={false} tickLine={false} width={56} tick={<Tick anchor="end" money={metric === 'value'} />} />
                        <Tooltip
                            cursor={{ stroke: 'var(--color-line-strong)' }}
                            content={({ active, payload }) => {
                                const point = active ? (payload?.[0]?.payload as (typeof points)[number] | undefined) : undefined;
                                if (!point) return null;
                                return (
                                    <div className="rounded-sm border border-line-default bg-surface px-3 py-2 shadow-e2">
                                        <p className="t-admin-caption text-fg-secondary">{point.row.from === point.row.to ? formatDay(point.row.from, locale) : `${formatDay(point.row.from, locale, false)} – ${formatDay(point.row.to, locale)}`}</p>
                                        <p className="t-admin-num text-fg-primary">{pick(point.row, false).text}</p>
                                        <p className="t-admin-caption text-fg-tertiary">{t('sales.trend.previousShort', { value: pick(point.row, true).text })}</p>
                                    </div>
                                );
                            }}
                        />
                        <Area dataKey="now" type="linear" stroke="var(--color-brand)" strokeWidth={2} fill="var(--color-brand-subtle)" dot={{ r: 3, fill: 'var(--color-surface)', stroke: 'var(--color-brand)' }} isAnimationActive={false} />
                        <Line dataKey="before" type="linear" stroke="var(--color-fg-tertiary)" strokeWidth={1.5} strokeDasharray="5 4" dot={false} isAnimationActive={false} connectNulls />
                    </ComposedChart>
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
        text = `${TAKA}${abs >= 10000 ? `${formatAmount(String(Math.round(abs / 1000)))}K` : formatAmount(String(abs))}`;
    } else if (typeof raw === 'number') {
        text = formatCount(raw);
    }
    return (
        <text x={x} y={(y ?? 0) + (anchor === 'middle' ? 12 : 4)} dx={anchor === 'end' ? -4 : 0} textAnchor={anchor} className="t-admin-caption tabular" fill="var(--color-fg-secondary)">
            {text}
        </text>
    );
}

const outcomeSwatch = {
    delivered: 'bg-feedback-success',
    shipped: 'bg-feedback-info',
    open: 'bg-brand',
    cancelled: 'bg-fg-tertiary',
    returned: 'bg-feedback-danger',
} as const;
const outcomes = ['delivered', 'shipped', 'open', 'cancelled', 'returned'] as const;

/**
 * "What happened to these orders" (Figma 518:78373): one bar of the period's orders by where each
 * is now, and the counts. The counts are the server's per-bucket outcomes, added up for display.
 */
function OutcomeSummary({ data }: { data: SalesAnalytics }) {
    const { t } = useI18n();
    const totals = outcomes.map((o) => [o, data.trend.reduce((sum, row) => sum + row.outcomes[o], 0)] as const);
    const all = totals.reduce((sum, [, n]) => sum + n, 0);
    return (
        <Card title={t('sales.outcome.title')} subtitle={t('sales.outcome.summary', { count: formatCount(all) })}>
            {all > 0 && (
                <span aria-hidden className="flex h-2.5 w-full gap-0.5 overflow-hidden rounded-full">
                    {totals
                        .filter(([, n]) => n > 0)
                        .map(([o, n]) => (
                            <span key={o} className={`h-full ${outcomeSwatch[o]}`} style={{ flexGrow: n }} />
                        ))}
                </span>
            )}
            <dl className="flex flex-col">
                {totals.map(([o, n]) => (
                    <div key={o} className="flex items-center gap-2 py-1.5">
                        <dt className="flex min-w-0 flex-1 items-center gap-2 t-admin-body text-fg-primary">
                            <span aria-hidden className={`size-2.5 shrink-0 rounded-full ${outcomeSwatch[o]}`} />
                            {t(`sales.outcome.${o}` as MessageKey)}
                        </dt>
                        <dd className="t-admin-body-strong tabular text-fg-primary">{formatCount(n)}</dd>
                    </div>
                ))}
            </dl>
        </Card>
    );
}

/** The five best categories by revenue on delivery, with their margin; the full table is under "More detail". */
function TopCategories({ data }: { data: SalesAnalytics }) {
    const { t, locale } = useI18n();
    const rows = data.categories.slice(0, 5);
    return (
        <Card title={t('sales.top.title')} subtitle={t('sales.top.subtitle')}>
            {rows.length === 0 ? (
                <p className="t-admin-caption text-fg-tertiary">{t('sales.noDelivered')}</p>
            ) : (
                <ul className="flex flex-col">
                    {rows.map((row, i) => (
                        <li key={`${row.category_id ?? 'none'}-${i}`} className="flex items-center gap-3 border-t border-line-default py-2.5 first:border-t-0">
                            <span className="flex min-w-0 flex-1 flex-col">
                                <span className="truncate t-admin-body-strong text-fg-primary">{(locale === 'bn' && row.name_bn ? row.name_bn : row.name) || t('sales.noCategory')}</span>
                                <span className="t-admin-caption tabular text-fg-secondary">{t('sales.top.sold', { count: formatCount(row.units_sold) })}</span>
                            </span>
                            <span className="t-admin-num">
                                <Money amount={row.net_revenue} />
                            </span>
                            {data.profit_visible && row.margin_percent !== null && (
                                <span className="rounded-xs bg-success-subtle px-2 py-0.5 t-admin-caption-strong tabular text-fg-primary">{t('sales.top.margin', { percent: row.margin_percent })}</span>
                            )}
                        </li>
                    ))}
                </ul>
            )}
        </Card>
    );
}

/** The visitor funnel lives on its own tab (Analytics › Visitors & funnel); Sales points there rather than repeating it. */
function FunnelNote() {
    const { t } = useI18n();
    return (
        <section className="flex flex-wrap items-center gap-4 rounded-md border border-dashed border-line-strong bg-surface p-5">
            <FunnelSimpleIcon size={22} aria-hidden className="shrink-0 text-fg-tertiary" />
            <div className="flex min-w-0 flex-1 flex-col gap-1">
                <h2 className="t-admin-body-strong text-fg-primary">{t('sales.funnel.title')}</h2>
                <p className="t-admin-body text-fg-secondary">{t('sales.funnel.body')}</p>
            </div>
            <Link to="/analytics/visitors" className={buttonClass('outline', 'sm')}>
                {t('sales.funnel.open')}
            </Link>
        </section>
    );
}

/** Figma "Traffic sources" (241:30674): label, figure, and a 6px bar on bg/subtle. */
function BarListCard({ title, subtitle, rows, tone = 'brand' }: { title: string; subtitle: string; rows: { key: string; label: string; value: string; orders: number; percent: string | null }[]; tone?: 'brand' | 'success' }) {
    const { t } = useI18n();
    return (
        <Card title={title} subtitle={subtitle}>
            {rows.length === 0 ? (
                <p className="t-admin-caption text-fg-tertiary">{t('sales.none')}</p>
            ) : (
                <ul className="flex flex-col gap-2.5">
                    {rows.map((row) => (
                        <li key={row.key} className="flex flex-col gap-1">
                            <div className="flex items-start gap-2">
                                <span className="min-w-0 flex-1 t-admin-body text-fg-primary">{row.label}</span>
                                <span className="t-admin-caption tabular whitespace-nowrap text-fg-secondary">
                                    <Money amount={row.value} signClass="t-admin-currency-caption" /> · {t('sales.orders', { count: formatCount(row.orders) })}
                                </span>
                            </div>
                            <span aria-hidden className="h-1.5 w-full overflow-hidden rounded-3xs bg-subtle">
                                <span className={`block h-full rounded-3xs ${tone === 'success' ? 'bg-feedback-success' : 'bg-brand'}`} style={{ width: `${row.percent ?? '0'}%` }} />
                            </span>
                        </li>
                    ))}
                </ul>
            )}
        </Card>
    );
}

function PerformanceTable({ title, first, rows, fallback, profit }: { title: string; first: string; rows: PerformanceRow[]; fallback: string; profit: boolean }) {
    const { t, locale } = useI18n();
    const id = useId();
    const num = `${dataTable.td} t-admin-num text-end`;
    const th = `${dataTable.th} text-end`;
    return (
        <section aria-labelledby={id} className="flex flex-col gap-3">
            <h2 id={id} className="t-admin-section text-fg-primary">
                {title}
            </h2>
            {rows.length === 0 ? (
                <p className="t-admin-caption text-fg-tertiary">{t('sales.noDelivered')}</p>
            ) : (
                <div className={dataTable.card}>
                    <div className="overflow-x-auto">
                        <table className={dataTable.table}>
                            <thead className={dataTable.head}>
                                <tr className="border-b border-line-strong">
                                    <th scope="col" className={dataTable.th}>
                                        {first}
                                    </th>
                                    <th scope="col" className={th}>{t('sales.col.orders')}</th>
                                    <th scope="col" className={th}>{t('sales.col.units')}</th>
                                    <th scope="col" className={th}>{t('sales.col.returnRate')}</th>
                                    <th scope="col" className={th}>{t('sales.col.revenue')}</th>
                                    {profit && <th scope="col" className={th}>{t('sales.col.grossProfit')}</th>}
                                    {profit && <th scope="col" className={th}>{t('sales.col.margin')}</th>}
                                </tr>
                            </thead>
                            <tbody>
                                {rows.map((row, index) => {
                                    const name = (locale === 'bn' && row.name_bn ? row.name_bn : row.name) || fallback;
                                    return (
                                        <tr key={`${name}-${index}`} className={dataTable.row}>
                                            <th scope="row" className={`${dataTable.td} t-admin-body-strong text-start`}>
                                                {name}
                                            </th>
                                            <td className={num}>{formatCount(row.orders)}</td>
                                            <td className={num}>{formatCount(row.units)}</td>
                                            <td className={num}>
                                                <Percent value={row.return_rate} />
                                            </td>
                                            <td className={num}>
                                                <Money amount={row.net_revenue} />
                                            </td>
                                            {profit && (
                                                <td className={num}>
                                                    <Money amount={row.gross_profit} />
                                                </td>
                                            )}
                                            {profit && (
                                                <td className={num}>
                                                    <Percent value={row.margin_percent} />
                                                </td>
                                            )}
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                </div>
            )}
        </section>
    );
}

function DiscountsCard({ data }: { data: SalesAnalytics }) {
    const { t } = useI18n();
    const d = data.discounts;
    const rows: { key: string; label: string; value: ReactNode }[] = [
        { key: 'share', label: t('sales.discounts.share'), value: <><Percent value={d.orders_with_discount_percent} /> <span className="t-admin-caption text-fg-tertiary">· {t('sales.orders', { count: formatCount(d.orders_with_discount) })}</span></> },
        { key: 'coupon', label: t('sales.discounts.coupon'), value: <Money amount={d.coupon_discount} /> },
        { key: 'promotion', label: t('sales.discounts.promotion'), value: <Money amount={d.promotion_discount} /> },
        { key: 'shipping', label: t('sales.discounts.shipping'), value: <Money amount={d.shipping_discount} /> },
        { key: 'aovWith', label: t('sales.discounts.aovWith'), value: <Money amount={d.aov_with_discount} /> },
        { key: 'aovWithout', label: t('sales.discounts.aovWithout'), value: <Money amount={d.aov_without_discount} /> },
        { key: 'recognised', label: t('sales.discounts.recognised'), value: <Money amount={d.recognised_discounts} /> },
    ];
    return (
        <Card title={t('sales.discounts.title')} subtitle={t('sales.discounts.subtitle')}>
            <dl className="flex flex-col">
                {rows.map((row) => (
                    <div key={row.key} className="flex items-center gap-2 py-1.5">
                        <dt className="min-w-0 flex-1 t-admin-body text-fg-secondary">{row.label}</dt>
                        <dd className="t-admin-body-strong whitespace-nowrap text-fg-primary">{row.value}</dd>
                    </div>
                ))}
            </dl>
            {d.coupons.length > 0 && (
                <>
                    <h3 className="t-admin-overline text-fg-secondary">{t('sales.discounts.topCoupons')}</h3>
                    <ul className="flex flex-col">
                        {d.coupons.map((c) => (
                            <li key={c.code} className="flex items-center gap-2 border-t border-line-default py-1.5">
                                <span className="min-w-0 flex-1 t-admin-mono-sm text-fg-primary">{c.code}</span>
                                <span className="t-admin-caption tabular text-fg-secondary">{t('sales.orders', { count: formatCount(c.orders) })}</span>
                                <span className="t-admin-body-strong">
                                    <Money amount={c.discount} />
                                </span>
                            </li>
                        ))}
                    </ul>
                </>
            )}
        </Card>
    );
}

/** RecommendationPlacement values plus `none` (bought without a recommendation). */
const placements = ['pdp_upsell', 'pdp_cross_sell', 'pdp_fbt', 'pdp_age_progression', 'cart_cross_sell', 'confirmation_cross_sell', 'none'];

/** Recommendation placements (rule 25d: attribution is a measurement — order_items.recommendation_source). */
function RecommendationsCard({ data }: { data: SalesAnalytics }) {
    const { t } = useI18n();
    const label = (source: string) => (placements.includes(source) ? t(`sales.recs.placement.${source}` as MessageKey) : source);
    return (
        <Card title={t('sales.recs.title')} subtitle={t('sales.recs.subtitle')}>
            {data.recommendations.length === 0 ? (
                <p className="t-admin-caption text-fg-tertiary">{t('sales.none')}</p>
            ) : (
                <div className="overflow-x-auto">
                    <table className={dataTable.table}>
                        <thead>
                            <tr className="border-b border-line-default">
                                <th scope="col" className={`${dataTable.th} px-0`}>{t('sales.recs.source')}</th>
                                <th scope="col" className={`${dataTable.th} text-end`}>{t('sales.col.units')}</th>
                                <th scope="col" className={`${dataTable.th} text-end`}>{t('sales.recs.placed')}</th>
                                <th scope="col" className={`${dataTable.th} pe-0 text-end`}>{t('sales.col.revenue')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {data.recommendations.map((r) => (
                                <tr key={r.source} className="border-t border-line-default">
                                    <th scope="row" className="py-2 text-start t-admin-body text-fg-primary">
                                        {label(r.source)}
                                    </th>
                                    <td className="px-3 py-2 text-end t-admin-num">{formatCount(r.placed_units)}</td>
                                    <td className="px-3 py-2 text-end t-admin-num">
                                        <Money amount={r.placed_value} /> <span className="t-admin-caption text-fg-tertiary">(<Percent value={r.placed_value_percent} />)</span>
                                    </td>
                                    <td className="py-2 text-end t-admin-num">
                                        <Money amount={r.net_revenue} />
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </Card>
    );
}

/**
 * Every order source has a label, checked by the compiler: a new source (as `pos` was) fails the
 * build here instead of rendering a nameless row. An unknown value from a newer API shows as-is.
 */
const channelLabels: Record<Schema<'OrderListItem'>['source'], MessageKey> = {
    web: 'sales.channel.web',
    admin: 'sales.channel.admin',
    phone: 'sales.channel.phone',
    facebook: 'sales.channel.facebook',
    whatsapp: 'sales.channel.whatsapp',
    pos: 'sales.channel.pos',
};

export function channelLabel(source: string, t: (key: MessageKey) => string): string {
    const key = (channelLabels as Record<string, MessageKey | undefined>)[source];
    return key ? t(key) : source;
}

/**
 * The Sales page's three sentences (Figma 518:78373 "In plain words"): what was placed and how it
 * moved, what was delivered (and kept, if the role may see profit), and where orders came from
 * with the cancellations. Every figure is a server string; this only words them.
 */
function salesSentences(data: SalesAnalytics, t: (k: MessageKey, p?: Record<string, string | number>) => string): string[] {
    const s = data.summary;
    const lines: string[] = [];
    const placed = change(data.deltas.placed_value);
    lines.push(
        placed
            ? t(placed.up ? 'sales.plain.placedUp' : 'sales.plain.placedDown', { value: takaText(s.placed.net_value), orders: formatCount(s.placed.net_orders), percent: placed.amount })
            : t('sales.plain.placed', { value: takaText(s.placed.net_value), orders: formatCount(s.placed.net_orders) }),
    );
    if (data.profit_visible && s.recognised.gross_profit !== null && s.recognised.gross_margin_percent !== null) {
        lines.push(t('sales.plain.deliveredProfit', { revenue: takaText(s.recognised.net_revenue), profit: takaText(s.recognised.gross_profit), margin: s.recognised.gross_margin_percent }));
    } else {
        lines.push(t('sales.plain.delivered', { revenue: takaText(s.recognised.net_revenue), orders: formatCount(s.delivered.orders) }));
    }
    const top = [...data.channels].sort((a, b) => b.orders - a.orders)[0];
    const channel = top && top.orders_percent !== null ? t('sales.plain.channel', { channel: channelLabel(top.source, t), percent: top.orders_percent }) : '';
    const cancelled = s.placed.cancelled_orders > 0 && s.placed.cancel_rate !== null ? t('sales.plain.cancelled', { count: formatCount(s.placed.cancelled_orders), orders: formatCount(s.placed.orders), percent: s.placed.cancel_rate }) : '';
    const last = [channel, cancelled].filter(Boolean).join(' ');
    if (last) lines.push(last);
    return lines;
}
