import { useEffect, useState } from 'react';
import { CaretDownIcon, CaretUpIcon, MagnifyingGlassIcon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { ContentHeader } from '@/components/shell/ContentHeader';
import { DataTableFooter, dataTable } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { ExportMenu } from '@/components/ui/ExportMenu';
import { Input } from '@/components/ui/Input';
import { QueryError } from '@/components/ui/QueryError';
import { SegmentedControl } from '@/components/ui/SegmentedControl';
import { profitBys, profitSorts, profitabilityExportUrl, useProfitability, type ProfitBy, type ProfitSort, type ProfitabilityRow } from './api';
import { Money, PeriodPicker, Percent, ReportTabs, formatCount, formatDay, useRangeParams } from './parts';

type Column = { key: ProfitSort | 'returned' | 'reversals' | 'category' | 'products'; label: MessageKey; numeric: boolean; sortable: boolean };

/**
 * Profitability (06-admin-feature-checklist §2.13 "[C] product/order profitability tables";
 * DataTable 192:532 in Theme B): the lines of orders delivered in the period, by product,
 * primary category or recommendation placement (rule 25d). Net revenue has returns and
 * completed refunds netted; COGS is the cost frozen on the order line (rule 7). Sorting and
 * filtering go to the server's allowlists; the browser computes nothing (rule 1).
 */
export function ProfitabilityPage() {
    const { t, locale } = useI18n();
    const { range, preset, params, set, choosePreset } = useRangeParams();
    const byParam = params.get('by') as ProfitBy | null;
    const by: ProfitBy = byParam && profitBys.includes(byParam) ? byParam : 'product';
    const sortParam = params.get('sort') ?? '';
    const sort = profitSorts.includes(sortParam.replace(/^-/, '') as ProfitSort) ? sortParam : '-gross_profit';
    const search = params.get('q') ?? '';
    const [draft, setDraft] = useState(search);
    useEffect(() => {
        const timer = setTimeout(() => {
            if (draft !== search) set({ q: draft });
        }, 300);
        return () => clearTimeout(timer);
    }, [draft, search, set]);

    const filters = { by, sort, search, source: '' };
    const report = useProfitability(range, filters);
    const data = report.data;

    const columns: Column[] = [
        { key: 'name', label: by === 'source' ? 'reports.profit.col.source' : 'reports.profit.col.name', numeric: false, sortable: true },
        ...(by === 'product' ? [{ key: 'category', label: 'reports.profit.col.category', numeric: false, sortable: false } as Column] : []),
        ...(by !== 'product' ? [{ key: 'products', label: 'reports.profit.col.products', numeric: true, sortable: false } as Column] : []),
        { key: 'units', label: 'reports.profit.col.units', numeric: true, sortable: true },
        { key: 'returned', label: 'reports.profit.col.returned', numeric: true, sortable: false },
        { key: 'net_revenue', label: 'reports.profit.col.revenue', numeric: true, sortable: true },
        { key: 'reversals', label: 'reports.profit.col.reversals', numeric: true, sortable: false },
        { key: 'cogs', label: 'reports.profit.col.cogs', numeric: true, sortable: true },
        { key: 'gross_profit', label: 'reports.profit.col.profit', numeric: true, sortable: true },
        { key: 'margin_percent', label: 'reports.profit.col.margin', numeric: true, sortable: true },
    ];

    const toggleSort = (key: string) => set({ sort: sort === `-${key}` ? key : `-${key}` });
    const rangeLabel = data ? t('reports.period.range', { from: formatDay(data.from, locale), to: formatDay(data.to, locale) }) : '';

    const name = (row: ProfitabilityRow) => {
        if (by === 'source') return row.source === 'none' ? t('reports.profit.source.none') : row.name;
        if (by === 'category' && row.category_id === null) return t('reports.profit.noCategory');
        return locale === 'bn' && row.name_bn ? row.name_bn : row.name;
    };

    return (
        <section className="flex flex-col gap-6">
            <ContentHeader
                breadcrumb={[{ label: t('reports.crumb.money') }, { label: t('reports.crumb.finance') }, { label: t('reports.profit.title') }]}
                title={t('reports.profit.title')}
                subtitle={data && t('reports.profit.subtitle', { range: rangeLabel })}
                actions={
                    <>
                        <PeriodPicker range={range} preset={preset} onPreset={choosePreset} onRange={(r) => set({ preset: 'custom', ...r })} />
                        <ExportMenu href={(format, locale) => profitabilityExportUrl(range, filters, { format, locale })} />
                    </>
                }
            />
            <ReportTabs />

            <div className="flex flex-wrap items-center gap-3">
                <SegmentedControl
                    label={t('reports.profit.by')}
                    options={profitBys.map((b) => ({ value: b, label: t(`reports.profit.by.${b}` as MessageKey) }))}
                    value={by}
                    onChange={(b) => set({ by: b === 'product' ? '' : b })}
                />
                <div className="w-full sm:w-72">
                    <Input
                        type="search"
                        aria-label={t('reports.profit.search')}
                        placeholder={t('reports.profit.search')}
                        leading={<MagnifyingGlassIcon size={16} aria-hidden />}
                        value={draft}
                        onChange={(e) => setDraft(e.target.value)}
                    />
                </div>
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
                                <tr className="border-b border-line-strong">
                                    {columns.map((c) => {
                                        const current = sort.replace(/^-/, '') === c.key;
                                        const ariaSort = current ? (sort.startsWith('-') ? 'descending' : 'ascending') : undefined;
                                        return (
                                            <th key={c.key} scope="col" aria-sort={ariaSort} className={`${dataTable.th} ${c.numeric ? 'text-end' : ''}`}>
                                                {c.sortable ? (
                                                    <button type="button" onClick={() => toggleSort(c.key)} aria-label={t('reports.profit.sortBy', { column: t(c.label) })} className="inline-flex items-center gap-1 uppercase hover:text-fg-primary">
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
                                            <TotalCell column={c.key} totals={data.totals} />
                                        </td>
                                    ))}
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <DataTableFooter summary={data.truncated ? t('reports.profit.truncated') : t('reports.profit.total', { rows: formatCount(data.totals.rows), orders: formatCount(data.totals.orders) })} />
                </div>
            )}
        </section>
    );
}

function Cell({ column, row, name }: { column: Column['key']; row: ProfitabilityRow; name: string }) {
    const { locale } = useI18n();
    switch (column) {
        case 'name':
            return (
                <span className="flex flex-col">
                    <span>{name}</span>
                    {row.sku && <span className="t-admin-mono-sm text-fg-tertiary">{row.sku}</span>}
                </span>
            );
        case 'category':
            return <span className="text-fg-secondary">{(locale === 'bn' && row.category_name_bn) || row.category_name || '—'}</span>;
        case 'products':
            return <>{formatCount(row.products ?? 0)}</>;
        case 'units':
            return <>{formatCount(row.units)}</>;
        case 'returned':
            return <span className="text-fg-secondary">{formatCount(row.units_returned)}</span>;
        case 'margin_percent':
            return <Percent value={row.margin_percent} />;
        default:
            return <Money amount={row[column]} />;
    }
}

function TotalCell({ column, totals }: { column: Column['key']; totals: { units: number; units_returned: number; net_revenue: string; reversals: string; cogs: string; gross_profit: string; margin_percent: string | null } }) {
    const { t } = useI18n();
    switch (column) {
        case 'name':
            return <>{t('reports.daily.total')}</>;
        case 'category':
        case 'products':
            return null;
        case 'units':
            return <>{formatCount(totals.units)}</>;
        case 'returned':
            return <>{formatCount(totals.units_returned)}</>;
        case 'margin_percent':
            return <Percent value={totals.margin_percent} />;
        default:
            return <Money amount={totals[column]} />;
    }
}
