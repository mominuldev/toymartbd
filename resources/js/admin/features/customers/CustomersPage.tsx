import { useEffect, useMemo, useState, type ReactNode } from 'react';
import { Link, useSearchParams } from 'react-router';
import { ArrowLeftIcon, ArrowRightIcon, ChartLineIcon, FunnelIcon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { can, useMe } from '@/features/auth/api';
import { Button, buttonClass } from '@/components/ui/Button';
import { ContentHeader } from '@/components/shell/ContentHeader';
import { DataTableFooter, dataTable } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { QueryError } from '@/components/ui/QueryError';
import { SearchField } from '@/components/ui/SearchField';
import { Select } from '@/components/ui/Select';
import { Tag } from '@/components/ui/Tag';
import {
    lastOrderRanges,
    riskStatuses,
    segments,
    sorts,
    useCustomers,
    useDistricts,
    type CustomerFilters,
    type CustomerListMeta,
    type CustomerRow,
    type LastOrderRange,
} from './api';
import { Taka, count, dhakaDate, dhakaDay, takaText } from './format';
import { Initials, RiskPill, RiskStatusTag, SegmentTag, segmentLabel } from './parts';

const pick = <T extends string>(value: string | null, allowed: readonly T[]): T | '' => (allowed.includes(value as T) ? (value as T) : '');

function useDebounced<T>(value: T, ms = 250): T {
    const [debounced, setDebounced] = useState(value);
    useEffect(() => {
        const id = window.setTimeout(() => setDebounced(value), ms);
        return () => window.clearTimeout(id);
    }, [value, ms]);
    return debounced;
}

/** Below 1024 the table becomes cards (the Orders board's 768 pattern, Figma 229:21429); jsdom lays out as 1440. */
function useDesktop(): boolean {
    const query = '(min-width: 64rem)';
    const read = () => typeof window === 'undefined' || typeof window.matchMedia !== 'function' || window.matchMedia(query).matches;
    const [desktop, setDesktop] = useState(read);
    useEffect(() => {
        if (typeof window.matchMedia !== 'function') return;
        const list = window.matchMedia(query);
        const update = () => setDesktop(list.matches);
        list.addEventListener('change', update);
        return () => list.removeEventListener('change', update);
    }, []);
    return desktop;
}

/** The last-order choice → inclusive Dhaka-date bounds for the API. */
function lastOrderBounds(range: LastOrderRange | ''): { from: string; to: string } {
    if (range === '') return { from: '', to: '' };
    if (range === 'older') return { from: '', to: dhakaDate(180) };
    return { from: dhakaDate(Number(range) - 1), to: '' };
}

type TileKey = 'new' | 'active' | 'vip' | 'at_risk' | 'blacklisted';
const tiles: { key: TileKey; tone: string }[] = [
    { key: 'new', tone: 'bg-tile-sky' },
    { key: 'active', tone: 'bg-tile-mint' },
    { key: 'vip', tone: 'bg-tile-butter' },
    { key: 'at_risk', tone: 'bg-tile-peach' },
    { key: 'blacklisted', tone: 'bg-tile-pink' },
];

/**
 * Customers (Figma B · 17 241:40845, Theme B): ContentHeader, five segment tiles (pastel
 * tiles, counts from the server under the current search and filters), search + allowlisted
 * filters in the URL, and the DataTable — masked phone always (rule 20), lifetime value from
 * the ledger, segment and list-status tags, our RiskPill. Page-numbered; 768 shows cards.
 */
export function CustomersPage() {
    const { t, locale } = useI18n();
    const { data: me } = useMe();
    const desktop = useDesktop();
    const [params, setParams] = useSearchParams();
    const [search, setSearch] = useState(params.get('q') ?? '');
    const debounced = useDebounced(search.trim());
    const [filtersOpen, setFiltersOpen] = useState(false);
    const last = pick(params.get('last'), lastOrderRanges);
    const districts = useDistricts(can(me, 'orders.create'));

    const filters: CustomerFilters = useMemo(() => {
        const bounds = lastOrderBounds(last);
        const page = Number(params.get('page') ?? '1');
        return {
            search: params.get('q') ?? '',
            segment: pick(params.get('segment'), segments),
            account: pick(params.get('account'), ['1', '0'] as const),
            risk: pick(params.get('risk'), riskStatuses),
            district: /^\d+$/.test(params.get('district') ?? '') ? params.get('district')! : '',
            lastOrderFrom: bounds.from,
            lastOrderTo: bounds.to,
            sort: pick(params.get('sort'), sorts) || '-last_order',
            page: Number.isInteger(page) && page > 0 ? page : 1,
        };
    }, [params, last]);
    const list = useCustomers(filters);

    const setParam = (changes: Record<string, string>) =>
        setParams(
            (current) => {
                const next = new URLSearchParams(current);
                for (const [key, value] of Object.entries(changes)) {
                    if (value) next.set(key, value);
                    else next.delete(key);
                }
                if (!('page' in changes)) next.delete('page');
                return next;
            },
            { replace: true },
        );

    useEffect(() => {
        if (debounced !== (params.get('q') ?? '')) setParam({ q: debounced });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [debounced]);

    const rows = list.data?.data ?? [];
    const meta = list.data?.meta;
    const activeFilters = [filters.segment, filters.account, filters.risk, filters.district, last].filter(Boolean).length;

    const pickTile = (key: TileKey) => {
        if (key === 'blacklisted') setParam({ risk: filters.risk === 'blacklist' ? '' : 'blacklist' });
        else setParam({ segment: filters.segment === key ? '' : key });
    };
    const tileActive = (key: TileKey) => (key === 'blacklisted' ? filters.risk === 'blacklist' : filters.segment === key);

    const filterControls = (
        <>
            {districts.data && (
                <FilterSelect label={t('customers.filter.district')} value={filters.district} onChange={(v) => setParam({ district: v })} all={t('customers.filter.districtAll')}>
                    {districts.data.map((d) => (
                        <option key={d.id} value={String(d.id)}>
                            {locale === 'bn' && d.name_bn ? d.name_bn : d.name}
                        </option>
                    ))}
                </FilterSelect>
            )}
            <FilterSelect label={t('customers.filter.segment')} value={filters.segment} onChange={(v) => setParam({ segment: v })} all={t('customers.filter.segmentAll')}>
                {segments.map((s) => (
                    <option key={s} value={s}>
                        {t(segmentLabel(s))}
                    </option>
                ))}
            </FilterSelect>
            <FilterSelect label={t('customers.filter.account')} value={filters.account} onChange={(v) => setParam({ account: v })} all={t('customers.filter.accountAll')}>
                <option value="1">{t('customers.filter.account.yes')}</option>
                <option value="0">{t('customers.filter.account.no')}</option>
            </FilterSelect>
            <FilterSelect label={t('customers.filter.risk')} value={filters.risk} onChange={(v) => setParam({ risk: v })} all={t('customers.filter.riskAll')}>
                {riskStatuses.map((s) => (
                    <option key={s} value={s}>
                        {t(`customers.filter.risk.${s}` as MessageKey)}
                    </option>
                ))}
            </FilterSelect>
            <FilterSelect label={t('customers.filter.lastOrder')} value={last} onChange={(v) => setParam({ last: v })} all={t('customers.filter.lastOrderAll')}>
                {lastOrderRanges.map((r) => (
                    <option key={r} value={r}>
                        {t(`customers.filter.lastOrder.${r}` as MessageKey)}
                    </option>
                ))}
            </FilterSelect>
            <label className="w-full sm:w-auto">
                <span className="sr-only">{t('customers.sort')}</span>
                <Select className="w-full sm:w-52" value={filters.sort} onChange={(e) => setParam({ sort: e.target.value === '-last_order' ? '' : e.target.value })}>
                    {sorts.map((s) => (
                        <option key={s} value={s}>
                            {t(`customers.sort.${s}` as MessageKey)}
                        </option>
                    ))}
                </Select>
            </label>
        </>
    );

    const searchField = (
        <SearchField
            label={t('customers.searchLabel')}
            placeholder={t('customers.searchPlaceholder')}
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            className={desktop ? 'w-full sm:w-90' : 'min-w-0 flex-1'}
        />
    );

    return (
        <section className="flex flex-col gap-6">
            <ContentHeader
                breadcrumb={[{ label: t('nav.group.sell') }, { label: t('customers.crumb') }]}
                title={t('customers.title')}
                subtitle={meta && t('customers.subtitle', { count: count(meta.counts.all ?? 0) })}
                actions={
                    can(me, 'reports.view') && (
                        <Link to="/analytics/customers" className={buttonClass('outline')}>
                            <ChartLineIcon size={16} aria-hidden />
                            {t('customers.analytics')}
                        </Link>
                    )
                }
            />

            {meta && <Tiles meta={meta} active={tileActive} onPick={pickTile} />}

            {desktop ? (
                <div className="flex flex-wrap items-center gap-2">
                    {searchField}
                    {filterControls}
                </div>
            ) : (
                <div className="flex flex-col gap-2">
                    <div className="flex items-center gap-2">
                        {searchField}
                        <Button variant="outline" aria-expanded={filtersOpen} onClick={() => setFiltersOpen((open) => !open)}>
                            <FunnelIcon size={16} aria-hidden />
                            {t('customers.filters')}
                            {activeFilters > 0 && <span className="tabular">{activeFilters}</span>}
                        </Button>
                    </div>
                    {filtersOpen && <div className="flex flex-wrap items-center gap-2 rounded-md border border-line-default bg-surface p-3">{filterControls}</div>}
                </div>
            )}

            {list.isError ? (
                <QueryError error={list.error} onRetry={() => void list.refetch()} />
            ) : list.isPending ? (
                <p className="t-admin-body text-fg-tertiary" aria-busy="true">
                    {t('common.loading')}
                </p>
            ) : rows.length === 0 ? (
                <EmptyState title={t('customers.empty')} body={t('customers.emptyBody')} />
            ) : (
                <>
                    {desktop ? (
                        <div className={dataTable.card}>
                            <div className="overflow-x-auto">
                                <table className={dataTable.table}>
                                    <thead className={dataTable.head}>
                                        <tr className="border-b border-line-strong">
                                            <th scope="col" className={dataTable.th}>
                                                {t('customers.col.customer')}
                                            </th>
                                            <th scope="col" className={dataTable.th}>
                                                {t('customers.col.district')}
                                            </th>
                                            <th scope="col" className={`${dataTable.th} text-end`}>
                                                {t('customers.col.orders')}
                                            </th>
                                            <th scope="col" className={`${dataTable.th} text-end`}>
                                                {t('customers.col.delivered')}
                                            </th>
                                            <th scope="col" className={`${dataTable.th} text-end`}>
                                                <abbr title={t('customers.ltvNote')} className="no-underline">
                                                    {t('customers.col.ltv')}
                                                </abbr>
                                            </th>
                                            <th scope="col" className={dataTable.th}>
                                                {t('customers.col.lastOrder')}
                                            </th>
                                            <th scope="col" className={dataTable.th}>
                                                {t('customers.col.tags')}
                                            </th>
                                            <th scope="col" className={dataTable.th}>
                                                {t('customers.col.risk')}
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {rows.map((row) => (
                                            <tr key={row.id} className={dataTable.row}>
                                                <td className={dataTable.td}>
                                                    <CustomerCell row={row} />
                                                </td>
                                                <td className={`${dataTable.td} t-admin-body`}>{row.district ?? <span className="text-fg-tertiary">{t('customers.none')}</span>}</td>
                                                <td className={`${dataTable.td} t-admin-num text-end tabular`}>{count(row.orders_count)}</td>
                                                <td className={`${dataTable.td} t-admin-num text-end tabular`}>{count(row.delivered_orders_count)}</td>
                                                <td className={`${dataTable.td} t-admin-num text-end`}>
                                                    <Taka amount={row.lifetime_value} />
                                                </td>
                                                <td className={`${dataTable.td} t-admin-body tabular whitespace-nowrap`}>
                                                    {row.last_order_at ? dhakaDay(row.last_order_at, locale) : <span className="text-fg-tertiary">{t('customers.none')}</span>}
                                                </td>
                                                <td className={dataTable.td}>
                                                    <Tags row={row} />
                                                </td>
                                                <td className={dataTable.td}>
                                                    <RiskCell row={row} />
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                            {meta && (
                                <DataTableFooter summary={t('customers.footer', { from: count(meta.from ?? 0), to: count(meta.to ?? 0), total: count(meta.total) })}>
                                    <Pager meta={meta} onPage={(page) => setParam({ page: page > 1 ? String(page) : '' })} />
                                </DataTableFooter>
                            )}
                        </div>
                    ) : (
                        <>
                            <ul className="flex flex-col gap-3" aria-label={t('customers.title')}>
                                {rows.map((row) => (
                                    <li key={row.id} className="flex flex-col gap-2 rounded-md border border-line-default bg-surface p-4">
                                        <div className="flex items-center justify-between gap-3">
                                            <CustomerCell row={row} />
                                            <span className="t-admin-num">
                                                <Taka amount={row.lifetime_value} />
                                            </span>
                                        </div>
                                        <p className="t-admin-caption text-fg-secondary tabular">
                                            {[row.district, `${t('customers.col.orders')} ${count(row.orders_count)}`, `${t('customers.col.delivered')} ${count(row.delivered_orders_count)}`, row.last_order_at ? dhakaDay(row.last_order_at, locale) : null]
                                                .filter(Boolean)
                                                .join(' · ')}
                                        </p>
                                        <div className="flex flex-wrap items-center gap-2">
                                            <Tags row={row} />
                                            <RiskCell row={row} />
                                        </div>
                                    </li>
                                ))}
                            </ul>
                            {meta && <Pager meta={meta} onPage={(page) => setParam({ page: page > 1 ? String(page) : '' })} />}
                        </>
                    )}
                    <p className="t-admin-caption text-fg-secondary">{t('customers.ltvNote')}</p>
                </>
            )}
        </section>
    );
}

/** The five segment tiles (Figma 241:40860): pastel tile, label, count, the rule in force. A tile filters the list. */
function Tiles({ meta, active, onPick }: { meta: CustomerListMeta; active: (key: TileKey) => boolean; onPick: (key: TileKey) => void }) {
    const { t } = useI18n();
    const hint = (key: TileKey): string => {
        if (key === 'active' || key === 'at_risk') return t(`customers.tile.${key}Hint` as MessageKey, { days: meta.thresholds.at_risk_after_days });
        if (key === 'vip') return t('customers.tile.vipHint', { amount: takaText(meta.thresholds.vip_min_lifetime_value) });
        return t(`customers.tile.${key}Hint` as MessageKey);
    };
    return (
        <div role="group" aria-label={t('customers.tiles')} className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
            {tiles.map(({ key, tone }) => (
                <button
                    key={key}
                    type="button"
                    aria-pressed={active(key)}
                    onClick={() => onPick(key)}
                    className={`flex min-w-0 flex-col items-start gap-1 rounded-md px-4 py-3.5 text-start ${tone} ${active(key) ? 'ring-2 ring-line-focus ring-inset' : ''}`}
                >
                    {/* Named by its visible text (WCAG 2.5.3): label, count, rule. */}
                    <span className="t-admin-caption-strong text-fg-primary">{t(`customers.tile.${key}` as MessageKey)}</span>{' '}
                    <span className="t-admin-num-lg text-fg-primary tabular">{count(meta.counts[key] ?? 0)}</span>{' '}
                    <span className="t-admin-caption text-fg-secondary">{hint(key)}</span>
                </button>
            ))}
        </div>
    );
}

function CustomerCell({ row }: { row: CustomerRow }) {
    return (
        <div className="flex min-w-0 items-center gap-2.5">
            <Initials name={row.name} />
            <div className="flex min-w-0 flex-col">
                <Link to={`/customers/${row.id}`} className="truncate t-admin-body-strong text-fg-primary hover:text-fg-link hover:underline">
                    {row.name}
                </Link>
                <span className="t-admin-caption text-fg-secondary tabular">{row.phone_masked}</span>
            </div>
        </div>
    );
}

function Tags({ row }: { row: CustomerRow }) {
    return (
        <div className="flex flex-wrap items-center gap-1.5">
            <SegmentTag segment={row.segment} />
            {row.risk && <RiskStatusTag status={row.risk.status} />}
            {row.tags.map((tag) => (
                <Tag key={tag} label={tag} tone="lavender" />
            ))}
        </div>
    );
}

function RiskCell({ row }: { row: CustomerRow }) {
    const { t } = useI18n();
    if (!row.risk) return <span className="t-admin-caption text-fg-tertiary">{t('customers.risk.notScored')}</span>;
    return <RiskPill level={row.risk.level} score={row.risk.score} />;
}

function Pager({ meta, onPage }: { meta: CustomerListMeta; onPage: (page: number) => void }) {
    const { t } = useI18n();
    return (
        <div className="flex items-center gap-2">
            <Button variant="outline" size="sm" disabled={meta.current_page <= 1} onClick={() => onPage(meta.current_page - 1)}>
                <ArrowLeftIcon size={16} aria-hidden />
                {t('customers.prev')}
            </Button>
            <Button variant="outline" size="sm" disabled={meta.current_page >= meta.last_page} onClick={() => onPage(meta.current_page + 1)}>
                {t('customers.next')}
                <ArrowRightIcon size={16} aria-hidden />
            </Button>
        </div>
    );
}

function FilterSelect({ label, value, onChange, all, children }: { label: string; value: string; onChange: (value: string) => void; all: string; children: ReactNode }) {
    return (
        <label className="w-full sm:w-auto">
            <span className="sr-only">{label}</span>
            <Select className="w-full sm:w-38" value={value} onChange={(e) => onChange(e.target.value)}>
                <option value="">{all}</option>
                {children}
            </Select>
        </label>
    );
}

