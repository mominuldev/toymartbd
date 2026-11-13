import { useEffect, useId, useState } from 'react';
import { CaretDownIcon, CaretUpIcon, DownloadSimpleIcon, MagnifyingGlassIcon } from '@phosphor-icons/react';
import { useSearchParams } from 'react-router';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { can, useMe } from '@/features/auth/api';
import { buttonClass } from '@/components/ui/Button';
import { ContentHeader } from '@/components/shell/ContentHeader';
import { DataTableFooter, dataTable } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { QueryError } from '@/components/ui/QueryError';
import { useValuation, valuationExportUrl, valuationSorts, type InventoryValuation, type ValuationSort } from './api';

const TAKA = '৳';

/** "5680.00" → "5,680", "355.0000" → "355": groups the server's decimal string, never parses it (rule 1). */
function formatAmount(decimal: string): string {
    const negative = decimal.startsWith('-');
    const [whole = '', fraction = ''] = (negative ? decimal.slice(1) : decimal).split('.');
    const trimmed = fraction.replace(/0+$/, '');
    return `${negative ? '−' : ''}${whole.replace(/\B(?=(\d{3})+(?!\d))/g, ',')}${trimmed ? `.${trimmed}` : ''}`;
}

const count = (n: number) => new Intl.NumberFormat('en-US').format(n);

function Money({ amount, signClass = 't-admin-currency-num' }: { amount: string; signClass?: string }) {
    return (
        <span className="tabular whitespace-nowrap">
            <span className={signClass}>{TAKA}</span>
            {formatAmount(amount)}
        </span>
    );
}

function dhakaToday(): string {
    return new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Dhaka', year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date());
}

/**
 * Inventory valuation (06-admin-feature-checklist §2.7 "valuation at cost"; built in the
 * B · 14 Inventory 241:39494 language — StatCard, location cards, DataTable): stock on hand
 * × weighted-average cost per variant and location, now or as at the end of a past Dhaka
 * day (replayed from the movement ledger). Totals by location and by category are the
 * server's; the browser only lays them out (rule 1). Filters live in the URL.
 */
export function InventoryValuationPage() {
    const { t, locale } = useI18n();
    const { data: me } = useMe();
    const [params, setParams] = useSearchParams();
    const asAt = /^\d{4}-\d{2}-\d{2}$/.test(params.get('as_at') ?? '') ? params.get('as_at')! : '';
    const location = /^\d+$/.test(params.get('location') ?? '') ? params.get('location')! : '';
    const sortParam = params.get('sort') ?? '';
    const sort = valuationSorts.includes(sortParam.replace(/^-/, '') as ValuationSort) ? sortParam : '-value';
    const search = params.get('q') ?? '';
    const [draft, setDraft] = useState(search);
    const asAtId = useId();

    const set = (key: string, value: string) =>
        setParams(
            (current) => {
                const next = new URLSearchParams(current);
                if (value) next.set(key, value);
                else next.delete(key);
                return next;
            },
            { replace: true },
        );

    useEffect(() => {
        const timer = setTimeout(() => {
            if (draft !== search) set('q', draft);
        }, 300);
        return () => clearTimeout(timer);
    }, [draft, search]);

    const filters = { asAt, location, search, sort };
    const report = useValuation(filters);
    const data = report.data;
    const name = (n: string, bn: string | null | undefined) => (locale === 'bn' && bn ? bn : n);

    return (
        <section className="flex flex-col gap-6">
            <ContentHeader
                breadcrumb={[{ label: t('catalog.crumb') }, { label: t('inventory.crumb'), to: '/inventory' }, { label: t('reports.valuation.crumb') }]}
                title={t('reports.valuation.title')}
                subtitle={asAt ? t('reports.valuation.subtitleAsAt', { date: asAt }) : t('reports.valuation.subtitleNow')}
                actions={
                    <>
                        <label htmlFor={asAtId} className="t-admin-body text-fg-secondary">
                            {t('reports.valuation.asAt')}
                        </label>
                        <div className="w-40 shrink-0"><Input id={asAtId} type="date" max={dhakaToday()} value={asAt} onChange={(e) => set('as_at', e.target.value)} className="tabular" /></div>
                        {can(me, 'finance.export') && (
                            <a href={valuationExportUrl(filters)} download className={buttonClass('outline')}>
                                <DownloadSimpleIcon size={16} aria-hidden />
                                {t('reports.export')}
                            </a>
                        )}
                    </>
                }
            />

            {report.isError ? (
                <QueryError error={report.error} onRetry={() => void report.refetch()} />
            ) : !data ? (
                <p className="t-admin-body text-fg-tertiary" aria-busy="true">
                    {t('reports.loading')}
                </p>
            ) : (
                <>
                    <div className="flex flex-col gap-4 lg:flex-row">
                        <div className="flex min-w-0 flex-col gap-1.5 rounded-md border border-line-default bg-surface p-4 shadow-e1 lg:w-72">
                            <p className="t-admin-overline text-fg-secondary">{t('reports.valuation.total')}</p>
                            <p className="t-admin-num-lg text-fg-primary">
                                <Money amount={data.totals.value} signClass="t-admin-currency-num-lg" />
                            </p>
                            <p className="t-admin-caption text-fg-tertiary tabular">{t('reports.valuation.totalHint', { units: count(data.totals.on_hand), rows: count(data.totals.rows) })}</p>
                        </div>
                        <div role="group" aria-label={t('reports.valuation.byLocation')} className="flex flex-1 flex-wrap gap-4">
                            {location && (
                                <button type="button" onClick={() => set('location', '')} className="flex min-w-40 flex-col justify-center rounded-md border border-line-default bg-surface p-4 text-start shadow-e1 hover:bg-subtle">
                                    <span className="t-admin-body-strong text-fg-primary">{t('reports.valuation.allLocations')}</span>
                                </button>
                            )}
                            {data.by_location.map((l) => {
                                const selected = String(l.location_id) === location;
                                return (
                                    <button
                                        key={l.location_id}
                                        type="button"
                                        aria-pressed={selected}
                                        onClick={() => set('location', selected ? '' : String(l.location_id))}
                                        className={`flex min-w-48 flex-1 flex-col gap-1 rounded-md p-4 text-start shadow-e1 ${selected ? 'border-2 border-brand bg-brand-subtle' : 'border border-line-default bg-surface hover:bg-subtle'}`}
                                    >
                                        <span className="t-admin-body-strong text-fg-primary">{name(l.name, l.name_bn)}</span>
                                        <span className="t-admin-num text-fg-primary">
                                            <Money amount={l.value} />
                                        </span>
                                        <span className="t-admin-caption text-fg-tertiary tabular">{t('inventory.location.units', { count: count(l.on_hand) })}</span>
                                    </button>
                                );
                            })}
                        </div>
                    </div>

                    {data.totals.estimated_rows > 0 && <p className="t-admin-caption text-fg-secondary">{t('reports.valuation.estimatedHint', { count: count(data.totals.estimated_rows) })}</p>}

                    <div className="flex flex-col gap-6 lg:flex-row lg:items-start">
                        <div className="min-w-0 flex-1">
                            <div className="mb-4 w-full sm:w-72">
                                <Input
                                    type="search"
                                    aria-label={t('reports.valuation.search')}
                                    placeholder={t('reports.valuation.search')}
                                    leading={<MagnifyingGlassIcon size={16} aria-hidden />}
                                    value={draft}
                                    onChange={(e) => setDraft(e.target.value)}
                                />
                            </div>
                            <Rows data={data} sort={sort} onSort={(key) => set('sort', sort === `-${key}` ? key : `-${key}`)} name={name} />
                        </div>
                        <ByCategory data={data} name={name} />
                    </div>
                </>
            )}
        </section>
    );
}

type Namer = (n: string, bn: string | null | undefined) => string;

function Rows({ data, sort, onSort, name }: { data: InventoryValuation; sort: string; onSort: (key: ValuationSort) => void; name: Namer }) {
    const { t } = useI18n();
    if (data.data.length === 0) return <EmptyState title={t('reports.valuation.empty')} body={t('reports.valuation.subtitleNow')} />;

    const header = (key: ValuationSort | 'location', label: MessageKey, numeric: boolean) => {
        const current = sort.replace(/^-/, '') === key;
        const sortable = key !== 'location';
        return (
            <th scope="col" aria-sort={current ? (sort.startsWith('-') ? 'descending' : 'ascending') : undefined} className={`${dataTable.th} ${numeric ? 'text-end' : ''}`}>
                {sortable ? (
                    <button type="button" onClick={() => onSort(key)} aria-label={t('reports.profit.sortBy', { column: t(label) })} className="inline-flex items-center gap-1 uppercase hover:text-fg-primary">
                        {t(label)}
                        {current && (sort.startsWith('-') ? <CaretDownIcon size={12} aria-hidden /> : <CaretUpIcon size={12} aria-hidden />)}
                    </button>
                ) : (
                    t(label)
                )}
            </th>
        );
    };

    return (
        <div className={dataTable.card}>
            <div className="overflow-x-auto">
                <table className={dataTable.table}>
                    <thead className={dataTable.head}>
                        <tr className="border-b border-line-strong">
                            {header('name', 'reports.valuation.col.product', false)}
                            {header('location', 'reports.valuation.col.location', false)}
                            {header('on_hand', 'reports.valuation.col.onHand', true)}
                            {header('unit_cost', 'reports.valuation.col.unitCost', true)}
                            {header('value', 'reports.valuation.col.value', true)}
                        </tr>
                    </thead>
                    <tbody>
                        {data.data.map((row) => (
                            <tr key={`${row.variant_id}-${row.location_id}`} className={dataTable.row}>
                                <td className={`${dataTable.td} t-admin-body`}>
                                    <span className="flex flex-col">
                                        <span>
                                            {name(row.name, row.name_bn)}
                                            {row.variant_name && <span className="text-fg-secondary">{` · ${row.variant_name}`}</span>}
                                        </span>
                                        <span className="t-admin-mono-sm text-fg-tertiary">{row.sku}</span>
                                    </span>
                                </td>
                                <td className={`${dataTable.td} t-admin-body text-fg-secondary`}>{name(row.location_name, row.location_name_bn)}</td>
                                <td className={`${dataTable.td} t-admin-num text-end`}>{count(row.on_hand)}</td>
                                <td className={`${dataTable.td} t-admin-num text-end`}>
                                    <Money amount={row.unit_cost} />
                                    {row.estimated && <span className="block t-admin-caption text-fg-tertiary">{t('reports.valuation.estimated')}</span>}
                                </td>
                                <td className={`${dataTable.td} t-admin-num text-end`}>
                                    <Money amount={row.value} />
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
            <DataTableFooter summary={data.truncated ? t('reports.valuation.truncated') : t('reports.valuation.totalHint', { units: count(data.totals.on_hand), rows: count(data.totals.rows) })} />
        </div>
    );
}

function ByCategory({ data, name }: { data: InventoryValuation; name: Namer }) {
    const { t } = useI18n();
    return (
        <section className="flex w-full shrink-0 flex-col gap-3 rounded-lg border border-line-default bg-surface p-5 shadow-e1 lg:w-96">
            <h2 className="t-admin-section text-fg-primary">{t('reports.valuation.byCategory')}</h2>
            <ul className="flex flex-col gap-2">
                {data.by_category.map((c) => (
                    <li key={c.category_id ?? 0} className="flex items-center justify-between gap-3">
                        <span className="t-admin-body text-fg-primary">{c.name ? name(c.name, c.name_bn) : t('reports.profit.noCategory')}</span>
                        <span className="t-admin-num text-fg-primary">
                            <Money amount={c.value} />
                        </span>
                    </li>
                ))}
            </ul>
        </section>
    );
}
