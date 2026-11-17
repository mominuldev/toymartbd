import { useId, type ReactNode } from 'react';
import { Link, useSearchParams } from 'react-router';
import { CubeIcon, PackageIcon, PlusIcon, StackIcon, WarningIcon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { ContentHeader } from '@/components/shell/ContentHeader';
import { dataTable } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { KpiCard } from '@/components/ui/KpiCard';
import { QueryError } from '@/components/ui/QueryError';
import { SegmentedControl } from '@/components/ui/SegmentedControl';
import { Select } from '@/components/ui/Select';
import { AnalyticsTabs } from '../AnalyticsTabs';
import { PlainWords } from '../PlainWords';
import { Money, dhakaToday, formatCount, formatDay, takaText } from '../sales/format';
import { productSorts, rangePresets, useProductAnalytics, type ProductAnalytics, type ProductAnalyticsRow, type ProductFilters, type ProductSort, type ProductStockRow, type RangePreset } from './api';

type T = (key: MessageKey, params?: Record<string, string | number>) => string;

const DATE = /^\d{4}-\d{2}-\d{2}$/;

/** Filters live in the URL so a report is a shareable link; the server validates every value. */
function useFilters(): [ProductFilters, (entries: Record<string, string>) => void] {
    const [params, setParams] = useSearchParams();
    const date = (key: string) => (DATE.test(params.get(key) ?? '') ? params.get(key)! : '');
    const rangeParam = params.get('range') as RangePreset | null;
    const sortParam = params.get('sort') as ProductSort | null;
    const filters: ProductFilters = {
        range: rangeParam && rangePresets.includes(rangeParam) ? rangeParam : '30d',
        from: date('from'),
        to: date('to'),
        sort: sortParam && productSorts.includes(sortParam) ? sortParam : 'revenue',
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

/** A server percentage ("38.00", "33.33") without trailing zeros: "38%", "33.33%". Display only. */
function pct(value: string | null | undefined): string {
    if (value === null || value === undefined) return '—';
    return `${value.replace(/\.0+$/, '').replace(/(\.\d*?)0+$/, '$1')}%`;
}

/** The server's whole days of stock, said in the unit an owner reads: days, then weeks, then months. */
function duration(days: number, t: T): string {
    if (days < 14) return t('analyticsProducts.stock.days', { count: formatCount(days) });
    if (days < 60) return t('analyticsProducts.stock.weeks', { count: formatCount(Math.floor(days / 7)) });
    return t('analyticsProducts.stock.months', { count: formatCount(Math.floor(days / 30)) });
}

function productName(row: { name: string; name_bn: string | null }, locale: string): string {
    return locale === 'bn' && row.name_bn ? row.name_bn : row.name;
}

function categoryName(row: { category_name: string | null; category_name_bn: string | null }, locale: string, t: T): string {
    return (locale === 'bn' && row.category_name_bn ? row.category_name_bn : row.category_name) ?? t('analyticsProducts.noCategory');
}

/**
 * Analytics → Products (Figma B · 24 Analytics 522:80395 / mobile 522:80899). Revenue, units and
 * returns are over orders delivered in the period; days of stock left are the server's (available
 * stock ÷ the period's order pace). Every figure is the server's (rule 1); the page only words it.
 */
export function ProductAnalyticsPage() {
    const { t, locale } = useI18n();
    const [filters, set] = useFilters();
    const report = useProductAnalytics(filters);
    const data = report.data;
    const ids = useId();
    const today = dhakaToday();

    const range = data ? (data.range.from === data.range.to ? formatDay(data.range.from, locale) : `${formatDay(data.range.from, locale, false)} – ${formatDay(data.range.to, locale)}`) : '';

    return (
        <section className="flex flex-col gap-6">
            <ContentHeader
                breadcrumb={[{ label: t('courierReport.crumb') }]}
                title={t('analyticsProducts.title')}
                subtitle={t('analyticsProducts.subtitle')}
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
                                set(next === 'custom' ? { range: next, from: filters.from || data?.range.from || today, to: filters.to || data?.range.to || today } : { range: next === '30d' ? '' : next, from: '', to: '' });
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
            ) : data.summary.products_on_sale === 0 && data.summary.products_sold === 0 ? (
                <EmptyState title={t('analyticsProducts.empty')} body={t('analyticsProducts.emptyBody', { range })} />
            ) : (
                <>
                    <PlainWords label={t('analyticsProducts.plain.label', { range })} lines={productSentences(data, t, locale)} />
                    <Stats data={data} />
                    <BestSellers data={data} sort={filters.sort} onSort={(s) => set({ sort: s === 'revenue' ? '' : s })} />
                    <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
                        <RunningOut data={data} />
                        <NotSelling data={data} />
                    </div>
                    <p className="t-admin-caption text-fg-secondary">
                        {t('analyticsProducts.note')}
                        {!data.profit_visible && <> {t('analyticsProducts.profitHidden')}</>}
                    </p>
                </>
            )}
        </section>
    );
}

/** The three plain sentences, worded from the server's figures only. */
export function productSentences(data: ProductAnalytics, t: T, locale: string): string[] {
    const lines: string[] = [];
    const best = data.highlights.best_seller;
    if (best) {
        const params = { name: productName(best, locale), units: formatCount(best.units_delivered), revenue: takaText(best.net_revenue) };
        lines.push(data.profit_visible && best.margin_percent !== null ? t('analyticsProducts.plain.bestProfit', { ...params, margin: pct(best.margin_percent).slice(0, -1) }) : t('analyticsProducts.plain.best', params));
    } else {
        lines.push(t('analyticsProducts.plain.noBest'));
    }
    const returned = data.highlights.most_returned;
    lines.push(
        returned && returned.return_rate !== null
            ? t('analyticsProducts.plain.returned', { name: productName(returned, locale), returned: formatCount(returned.units_returned), units: formatCount(returned.units_delivered), percent: pct(returned.return_rate).slice(0, -1) })
            : t('analyticsProducts.plain.noReturns'),
    );
    lines.push(t('analyticsProducts.plain.stock', { urgent: formatCount(data.summary.running_out_urgent_count), days: formatCount(data.thresholds.urgent_days), idle: formatCount(data.summary.not_selling_count) }));
    return lines;
}

function Stats({ data }: { data: ProductAnalytics }) {
    const { t } = useI18n();
    const s = data.summary;
    const days = formatCount(data.comparison.days);
    const change = s.units_delivered_change;
    const unitsHint =
        change > 0
            ? t('analyticsProducts.stat.unitsUp', { count: formatCount(change), days })
            : change < 0
              ? t('analyticsProducts.stat.unitsDown', { count: formatCount(-change), days })
              : t('analyticsProducts.stat.unitsSame', { days });
    const figure = (text: string) => <span className="t-admin-num-lg">{text}</span>;
    return (
        <div className="grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4">
            <KpiCard compact label={t('analyticsProducts.stat.sold')} value={figure(formatCount(s.products_sold))} hint={t('analyticsProducts.stat.soldHint', { count: formatCount(s.products_on_sale) })} icon={CubeIcon} tone="brand" />
            <KpiCard compact label={t('analyticsProducts.stat.units')} value={figure(formatCount(s.units_delivered))} hint={unitsHint} icon={PackageIcon} tone="brand" />
            <KpiCard
                compact
                label={t('analyticsProducts.stat.margin')}
                value={figure(data.profit_visible ? pct(s.margin_percent) : '—')}
                hint={data.profit_visible ? t('analyticsProducts.stat.marginHint') : t('analyticsProducts.stat.marginHidden')}
                icon={StackIcon}
                tone="neutral"
            />
            <KpiCard compact label={t('analyticsProducts.stat.returns')} value={figure(pct(s.return_rate))} hint={t('analyticsProducts.stat.returnsHint', { count: formatCount(s.units_returned) })} icon={WarningIcon} tone="warning" />
        </div>
    );
}

/** A card panel (241:30674): radius/md, hairline, e1, 20 padding; title Admin/card-title + caption. */
function Card({ title, subtitle, actions, children }: { title: string; subtitle?: string; actions?: ReactNode; children: ReactNode }) {
    const id = useId();
    return (
        <section aria-labelledby={id} className="flex min-w-0 flex-col gap-3 rounded-md border border-line-default bg-surface p-4 shadow-e1 sm:p-5">
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

/** "4 · 5 days" with a warning glyph when under the running-out line; "22 · no recent sale" without pace. */
function StockLeft({ row, data }: { row: ProductStockRow; data: ProductAnalytics }) {
    const { t } = useI18n();
    const count = formatCount(row.stock_left);
    if (row.days_of_stock === null) return <span className="t-admin-caption tabular text-fg-secondary">{t('analyticsProducts.stock.noPace', { count })}</span>;
    const low = row.days_of_stock < data.thresholds.running_out_days;
    return (
        <span className="inline-flex items-center justify-end gap-1 t-admin-caption-strong tabular whitespace-nowrap text-fg-primary">
            {low && <WarningIcon size={14} aria-label={t('analyticsProducts.stock.lowLabel')} className="shrink-0 text-feedback-warning" />}
            {t('analyticsProducts.stock.left', { count, time: duration(row.days_of_stock, t) })}
        </span>
    );
}

function BestSellers({ data, sort, onSort }: { data: ProductAnalytics; sort: ProductSort; onSort: (s: ProductSort) => void }) {
    const { t, locale } = useI18n();
    const sorts = data.profit_visible ? productSorts : productSorts.filter((s) => s !== 'margin');
    const shown = data.sort;
    const flagged = data.highlights.most_returned?.product_id ?? null;
    const num = `${dataTable.td} t-admin-num text-end`;
    const th = `${dataTable.th} text-end`;
    const returnsTone = (row: ProductAnalyticsRow) => (flagged !== null && row.product_id === flagged ? 'text-feedback-danger' : '');

    return (
        <Card
            title={t('analyticsProducts.best.title')}
            subtitle={t(`analyticsProducts.best.subtitle.${shown}` as MessageKey)}
            actions={<SegmentedControl label={t('analyticsProducts.best.sort')} options={sorts.map((s) => ({ value: s, label: t(`analyticsProducts.sort.${s}` as MessageKey) }))} value={sort} onChange={onSort} />}
        >
            {data.products.length === 0 ? (
                <p className="t-admin-caption text-fg-tertiary">{t('analyticsProducts.noDelivered')}</p>
            ) : (
                <>
                    <div className="hidden overflow-x-auto md:block">
                        <table className={dataTable.table}>
                            <thead>
                                <tr>
                                    <th className={`${dataTable.th} ps-0`}>{t('analyticsProducts.col.product')}</th>
                                    <th className={th}>{t('analyticsProducts.col.sold')}</th>
                                    <th className={th}>{t('analyticsProducts.col.revenue')}</th>
                                    {data.profit_visible && <th className={th}>{t('analyticsProducts.col.margin')}</th>}
                                    <th className={th}>{t('analyticsProducts.col.returns')}</th>
                                    <th className={`${th} pe-0`}>{t('analyticsProducts.col.stock')}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {data.products.map((row, i) => (
                                    <tr key={`${row.product_id ?? row.name}-${i}`} className="border-t border-line-default">
                                        <td className={`${dataTable.td} ps-0`}>
                                            <span className="flex min-w-0 items-center gap-3">
                                                <span aria-hidden className="grid size-9 shrink-0 place-items-center rounded-sm bg-subtle text-fg-secondary">
                                                    <CubeIcon size={18} />
                                                </span>
                                                <span className="flex min-w-0 flex-col">
                                                    <span className="truncate t-admin-body-strong text-fg-primary">{productName(row, locale)}</span>
                                                    <span className="truncate t-admin-caption text-fg-secondary">{categoryName(row, locale, t)}</span>
                                                </span>
                                            </span>
                                        </td>
                                        <td className={num}>{formatCount(row.units_delivered)}</td>
                                        <td className={num}>
                                            <Money amount={row.net_revenue} />
                                        </td>
                                        {data.profit_visible && <td className={num}>{pct(row.margin_percent)}</td>}
                                        <td className={`${num} ${returnsTone(row)}`}>{pct(row.return_rate)}</td>
                                        <td className={`${dataTable.td} pe-0 text-end`}>
                                            <StockLeft row={row} data={data} />
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    {/* 390 (522:80899): the table becomes a short list. */}
                    <ul className="flex flex-col md:hidden">
                        {data.products.map((row, i) => (
                            <li key={`${row.product_id ?? row.name}-${i}`} className="flex flex-col gap-1 border-t border-line-default py-3 first:border-t-0">
                                <span className="flex items-start gap-2">
                                    <span className="min-w-0 flex-1 t-admin-body-strong text-fg-primary">{productName(row, locale)}</span>
                                    <span className="t-admin-num">
                                        <Money amount={row.net_revenue} />
                                    </span>
                                </span>
                                <span className="flex flex-wrap items-center gap-x-2 gap-y-1 t-admin-caption tabular text-fg-secondary">
                                    <span>{t('analyticsProducts.soldShort', { count: formatCount(row.units_delivered) })}</span>
                                    {data.profit_visible && row.margin_percent !== null && <span>· {pct(row.margin_percent)}</span>}
                                    <span className={returnsTone(row)}>· {t('analyticsProducts.returnsShort', { percent: pct(row.return_rate).replace(/%$/, '') })}</span>
                                    <span className="ms-auto">
                                        <StockLeft row={row} data={data} />
                                    </span>
                                </span>
                            </li>
                        ))}
                    </ul>
                </>
            )}
        </Card>
    );
}

function RunningOut({ data }: { data: ProductAnalytics }) {
    const { t, locale } = useI18n();
    return (
        <Card
            title={t('analyticsProducts.out.title')}
            subtitle={t('analyticsProducts.out.subtitle', { days: formatCount(data.thresholds.running_out_days) })}
            actions={
                <Link to="/purchasing" className="inline-flex h-8 items-center gap-1.5 rounded-sm px-3 t-admin-button-sm text-fg-primary hover:bg-subtle">
                    <PlusIcon size={14} aria-hidden />
                    {t('analyticsProducts.out.reorder')}
                </Link>
            }
        >
            {data.running_out.length === 0 ? (
                <p className="t-admin-caption text-fg-tertiary">{t('analyticsProducts.out.none')}</p>
            ) : (
                <ul className="flex flex-col">
                    {data.running_out.map((row, i) => (
                        <li key={`${row.product_id ?? row.name}-${i}`} className="flex items-center gap-3 border-t border-line-default py-3">
                            <span className="min-w-0 flex-1 truncate t-admin-body text-fg-primary">{productName(row, locale)}</span>
                            <span className="inline-flex items-center gap-1 t-admin-caption-strong tabular whitespace-nowrap text-fg-primary">
                                <WarningIcon size={14} aria-hidden className="shrink-0 text-feedback-warning" />
                                {row.stock_left === 0 ? t('analyticsProducts.out.soldOut') : t('analyticsProducts.out.row', { count: formatCount(row.stock_left), time: duration(row.days_of_stock ?? 0, t) })}
                            </span>
                        </li>
                    ))}
                </ul>
            )}
        </Card>
    );
}

function NotSelling({ data }: { data: ProductAnalytics }) {
    const { t, locale } = useI18n();
    const days = formatCount(data.range.days);
    return (
        <Card title={t('analyticsProducts.idle.title')} subtitle={data.profit_visible ? t('analyticsProducts.idle.subtitle', { days }) : t('analyticsProducts.idle.subtitleNoCost', { days })}>
            {data.not_selling.length === 0 ? (
                <p className="t-admin-caption text-fg-tertiary">{t('analyticsProducts.idle.none')}</p>
            ) : (
                <ul className="flex flex-col">
                    {data.not_selling.map((row, i) => (
                        <li key={`${row.product_id ?? row.name}-${i}`} className="flex items-center gap-3 border-t border-line-default py-3">
                            <span className="min-w-0 flex-1 truncate t-admin-body text-fg-primary">{productName(row, locale)}</span>
                            <span className="t-admin-caption tabular whitespace-nowrap text-fg-secondary">
                                {data.profit_visible && row.stock_value !== null
                                    ? t('analyticsProducts.idle.row', { count: formatCount(row.stock_left), value: takaText(row.stock_value) })
                                    : t('analyticsProducts.idle.rowNoCost', { count: formatCount(row.stock_left) })}
                            </span>
                        </li>
                    ))}
                </ul>
            )}
        </Card>
    );
}
