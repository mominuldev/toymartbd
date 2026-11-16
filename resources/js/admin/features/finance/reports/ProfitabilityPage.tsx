import { useEffect, useState } from 'react';
import { CaretDownIcon, CaretUpIcon, CoinsIcon, PercentIcon, StackIcon, TrendUpIcon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { ContentHeader } from '@/components/shell/ContentHeader';
import { dataTable } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { ExportMenu } from '@/components/ui/ExportMenu';
import { KpiCard } from '@/components/ui/KpiCard';
import { QueryError } from '@/components/ui/QueryError';
import { SearchField } from '@/components/ui/SearchField';
import { SegmentedControl } from '@/components/ui/SegmentedControl';
import { Select } from '@/components/ui/Select';
import { KpiMoney, TakaFixed } from '../parts';
import { profitBys, profitSorts, profitabilityExportUrl, useProfitability, type ProfitBy, type ProfitSort, type ProfitabilityReport, type ProfitabilityRow } from './api';
import { PeriodPicker, Percent, ReportTabs, formatCount, formatDay, isNegative, useRangeParams } from './parts';

type ColumnKey = ProfitSort | 'reversals' | 'category' | 'products';
type Column = { key: ColumnKey; label: MessageKey; numeric: boolean; sortable: boolean };

/** Recommendation placements (RecommendationPlacement), plus `none` = not from a recommendation (rule 25d). */
const placements = ['pdp_upsell', 'pdp_cross_sell', 'pdp_fbt', 'pdp_age_progression', 'cart_cross_sell', 'confirmation_cross_sell', 'none'] as const;

/**
 * Profitability (Figma B · 32 Profitability 427:74396): four KPI cards from the server's totals
 * (net sales, cost of goods, gross profit, margin); Products / Categories / Recommendations,
 * search and the placement filter (server allowlists); the table — name with SKU, category,
 * units with what came back, net sales, refunds & returns, cost of goods (frozen on the order
 * line, rule 7), gross profit and margin — and a total row over every row. Delivered orders only;
 * sorting and filtering go to the server and the browser computes nothing (rule 1).
 */
export function ProfitabilityPage() {
    const { t, locale } = useI18n();
    const { range, preset, params, set, choosePreset } = useRangeParams();
    const byParam = params.get('by') as ProfitBy | null;
    const by: ProfitBy = byParam && profitBys.includes(byParam) ? byParam : 'product';
    const sortParam = params.get('sort') ?? '';
    const sort = profitSorts.includes(sortParam.replace(/^-/, '') as ProfitSort) ? sortParam : '-net_revenue';
    const search = params.get('q') ?? '';
    const sourceParam = params.get('source') ?? '';
    const source = placements.includes(sourceParam as (typeof placements)[number]) ? sourceParam : '';
    const [draft, setDraft] = useState(search);
    useEffect(() => {
        const timer = setTimeout(() => {
            if (draft !== search) set({ q: draft });
        }, 300);
        return () => clearTimeout(timer);
    }, [draft, search, set]);

    const filters = { by, sort, search, source };
    const report = useProfitability(range, filters);
    const data = report.data;

    const columns: Column[] = [
        { key: 'name', label: by === 'source' ? 'reports.profit.col.source' : by === 'category' ? 'reports.profit.col.name' : 'finUi.prof.col.product', numeric: false, sortable: true },
        ...(by === 'product' ? [{ key: 'category', label: 'reports.profit.col.category', numeric: false, sortable: false } as Column] : []),
        ...(by !== 'product' ? [{ key: 'products', label: 'reports.profit.col.products', numeric: true, sortable: false } as Column] : []),
        { key: 'units', label: 'reports.profit.col.units', numeric: true, sortable: true },
        { key: 'net_revenue', label: 'finUi.prof.col.netSales', numeric: true, sortable: true },
        { key: 'reversals', label: 'finUi.prof.col.reversals', numeric: true, sortable: false },
        { key: 'cogs', label: 'finUi.prof.col.cogs', numeric: true, sortable: true },
        { key: 'gross_profit', label: 'reports.profit.col.profit', numeric: true, sortable: true },
        { key: 'margin_percent', label: 'reports.profit.col.margin', numeric: true, sortable: true },
    ];

    const toggleSort = (key: string) => set({ sort: sort === `-${key}` ? key : `-${key}` });
    const rangeLabel = data ? t('reports.period.range', { from: formatDay(data.from, locale), to: formatDay(data.to, locale) }) : '';

    const name = (row: ProfitabilityRow) => {
        if (by === 'source') return row.source === 'none' ? t('reports.profit.source.none') : placementLabel(row.source ?? row.name);
        if (by === 'category' && row.category_id === null) return t('reports.profit.noCategory');
        return locale === 'bn' && row.name_bn ? row.name_bn : row.name;
    };
    const placementLabel = (value: string) => (placements.includes(value as (typeof placements)[number]) ? t(`sales.recs.placement.${value}` as MessageKey) : value);
    const end = dataTable.th.replace('[:where(&)]:text-start', 'text-end');

    return (
        <section className="flex flex-col gap-6">
            <ContentHeader
                breadcrumb={[{ label: t('reports.crumb.money') }, { label: t('reports.crumb.finance') }]}
                title={t('reports.profit.title')}
                subtitle={data && t('finUi.prof.subtitle', { range: rangeLabel })}
                actions={
                    <>
                        <PeriodPicker range={range} preset={preset} onPreset={choosePreset} onRange={(r) => set({ preset: 'custom', ...r })} />
                        <ExportMenu href={(format, locale) => profitabilityExportUrl(range, filters, { format, locale })} />
                    </>
                }
            />
            <ReportTabs />

            {data && <Kpis totals={data.totals} />}

            <div className="flex flex-wrap items-center justify-between gap-3">
                <div className="flex flex-wrap items-center gap-2">
                    <SegmentedControl
                        label={t('reports.profit.by')}
                        options={profitBys.map((b) => ({ value: b, label: t(`finUi.prof.by.${b}` as MessageKey) }))}
                        value={by}
                        onChange={(b) => set({ by: b === 'product' ? '' : b })}
                        className="h-9"
                    />
                    <SearchField label={t(`finUi.prof.search.${by}` as MessageKey)} placeholder={t(`finUi.prof.search.${by}` as MessageKey)} value={draft} onChange={(e) => setDraft(e.target.value)} className="w-full sm:w-75" />
                    <label className="flex">
                        <span className="sr-only">{t('finUi.prof.placement')}</span>
                        <Select value={source} onChange={(e) => set({ source: e.target.value })} className="w-48">
                            <option value="">{t('finUi.prof.placementAll')}</option>
                            {placements.map((p) => (
                                <option key={p} value={p}>
                                    {t(`sales.recs.placement.${p}` as MessageKey)}
                                </option>
                            ))}
                        </Select>
                    </label>
                </div>
                <p className="t-admin-caption text-fg-secondary">{t('finUi.prof.caption')}</p>
            </div>

            {report.isError ? (
                <QueryError error={report.error} onRetry={() => void report.refetch()} />
            ) : !data ? (
                <p className="t-admin-body text-fg-tertiary" aria-busy="true">
                    {t('reports.loading')}
                </p>
            ) : data.data.length === 0 ? (
                <EmptyState title={t('reports.profit.empty')} body={rangeLabel} />
            ) : (
                <div className={dataTable.card}>
                    <div className="overflow-x-auto">
                        <table className={dataTable.table}>
                            <thead className={dataTable.head}>
                                <tr className="border-b border-line-default">
                                    {columns.map((c) => {
                                        const current = sort.replace(/^-/, '') === c.key;
                                        const ariaSort = current ? (sort.startsWith('-') ? 'descending' : 'ascending') : undefined;
                                        return (
                                            <th key={c.key} scope="col" aria-sort={ariaSort} className={c.numeric ? end : dataTable.th}>
                                                {c.sortable ? (
                                                    <button
                                                        type="button"
                                                        onClick={() => toggleSort(c.key)}
                                                        aria-label={t('reports.profit.sortBy', { column: t(c.label) })}
                                                        className={`inline-flex items-center gap-1 uppercase hover:text-fg-primary ${current ? 'text-fg-primary' : ''}`}
                                                    >
                                                        {t(c.label)}
                                                        {current && (sort.startsWith('-') ? <CaretDownIcon size={12} aria-hidden /> : <CaretUpIcon size={12} aria-hidden />)}
                                                    </button>
                                                ) : (
                                                    t(c.label)
                                                )}
                                            </th>
                                        );
                                    })}
                                </tr>
                            </thead>
                            <tbody>
                                {data.data.map((row, index) => (
                                    <tr key={`${row.product_id ?? row.category_id ?? row.source ?? ''}-${index}`} className={dataTable.row}>
                                        {columns.map((c) => (
                                            <td key={c.key} className={`${dataTable.td} ${c.numeric ? 't-admin-num text-end' : 't-admin-body'}`}>
                                                <Cell column={c.key} row={row} name={name(row)} />
                                            </td>
                                        ))}
                                    </tr>
                                ))}
                                <tr className="border-t border-line-strong bg-subtle">
                                    {columns.map((c) => (
                                        <td key={c.key} className={`${dataTable.td} t-admin-body-strong ${c.numeric ? 'text-end tabular' : ''}`}>
                                            {c.key === 'name' ? (
                                                t(`finUi.prof.total.${by}` as MessageKey, { rows: formatCount(data.totals.rows), orders: formatCount(data.totals.orders) })
                                            ) : (
                                                <TotalCell column={c.key} totals={data.totals} />
                                            )}
                                        </td>
                                    ))}
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    {data.truncated && (
                        <p className="border-t border-line-default px-4 py-3 t-admin-caption text-fg-secondary" aria-live="polite">
                            {t('reports.profit.truncated')}
                        </p>
                    )}
                </div>
            )}
        </section>
    );
}

function Kpis({ totals }: { totals: ProfitabilityReport['totals'] }) {
    const { t } = useI18n();
    return (
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <KpiCard icon={TrendUpIcon} tone="brand" label={t('finUi.prof.stat.net')} value={<KpiMoney amount={totals.net_revenue} />} hint={t('finUi.prof.stat.netHint', { orders: formatCount(totals.orders), units: formatCount(totals.units) })} />
            <KpiCard icon={StackIcon} tone="neutral" label={t('finUi.prof.stat.cogs')} value={<KpiMoney amount={totals.cogs} />} hint={t('finUi.prof.stat.cogsHint')} />
            <KpiCard icon={CoinsIcon} tone="info" label={t('finUi.prof.stat.gross')} value={<KpiMoney amount={totals.gross_profit} />} hint={t('finUi.prof.stat.grossHint')} />
            <KpiCard
                icon={PercentIcon}
                tone="neutral"
                label={t('finUi.prof.stat.margin')}
                value={<span className="t-admin-num-lg">{totals.margin_percent === null ? '—' : <Percent value={totals.margin_percent} />}</span>}
                hint={t('finUi.prof.stat.marginHint')}
            />
        </div>
    );
}

/** A reversal is money taken back, so it reads with a minus ("−৳9,900.00"); none is a dash. */
function Reversal({ amount }: { amount: string }) {
    if (/^-?0+(\.0+)?$/.test(amount)) return <span className="text-fg-tertiary">—</span>;
    return <TakaFixed amount={isNegative(amount) ? amount : `-${amount}`} />;
}

function Cell({ column, row, name }: { column: ColumnKey; row: ProfitabilityRow; name: string }) {
    const { t, locale } = useI18n();
    switch (column) {
        case 'name':
            return (
                <span className="flex flex-col gap-0.5">
                    <span className="t-admin-body-strong">{name}</span>
                    {row.sku && <span className="t-admin-mono-sm text-fg-secondary">{row.sku}</span>}
                </span>
            );
        case 'category':
            return <span>{(locale === 'bn' && row.category_name_bn) || row.category_name || '—'}</span>;
        case 'products':
            return <>{formatCount(row.products ?? 0)}</>;
        case 'units':
            return (
                <span className="flex flex-col items-end gap-0.5">
                    <span>{formatCount(row.units)}</span>
                    {row.units_returned > 0 ? (
                        <span className="t-admin-caption whitespace-nowrap text-fg-secondary">{t('finUi.prof.returned', { count: formatCount(row.units_returned) })}</span>
                    ) : (
                        <span className="t-admin-caption whitespace-nowrap text-fg-secondary">{t('finUi.prof.noneReturned')}</span>
                    )}
                </span>
            );
        case 'margin_percent':
            return <Percent value={row.margin_percent} />;
        case 'reversals':
            return <Reversal amount={row.reversals} />;
        default:
            return <TakaFixed amount={row[column]} />;
    }
}

function TotalCell({ column, totals }: { column: ColumnKey; totals: ProfitabilityReport['totals'] }) {
    switch (column) {
        case 'category':
        case 'products':
        case 'name':
            return null;
        case 'units':
            return <>{formatCount(totals.units)}</>;
        case 'margin_percent':
            return <Percent value={totals.margin_percent} />;
        case 'reversals':
            return <Reversal amount={totals.reversals} />;
        default:
            return <TakaFixed amount={totals[column]} />;
    }
}
