import { useEffect, useMemo, useState, type ReactNode } from 'react';
import { Link, useSearchParams } from 'react-router';
import { ClockCounterClockwiseIcon, ListChecksIcon, SlidersHorizontalIcon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { can, useMe } from '@/features/auth/api';
import { Button, buttonClass } from '@/components/ui/Button';
import { ContentHeader } from '@/components/shell/ContentHeader';
import { DataTableFooter, dataTable } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { QueryError } from '@/components/ui/QueryError';
import { SearchField } from '@/components/ui/SearchField';
import { TabButton } from '@/components/ui/Tabs';
import { stockStates, useInventorySummary, useStock, type AdjustmentReason, type StockRow, type StockState } from './api';
import { AdjustmentDialog } from './AdjustmentDialog';
import { AvailableCell, LocationCards, StatCard, TAKA, Taka, VariantCell, formatMoney, formatUnits } from './parts';

const tabs: { value: StockState | ''; label: MessageKey; count: 'all' | StockState }[] = [
    { value: '', label: 'inventory.tab.all', count: 'all' },
    { value: 'low', label: 'inventory.tab.low', count: 'low' },
    { value: 'out', label: 'inventory.tab.out', count: 'out' },
    { value: 'idle', label: 'inventory.tab.idle', count: 'idle' },
];

function useDebounced<T>(value: T, ms = 250): T {
    const [debounced, setDebounced] = useState(value);
    useEffect(() => {
        const id = window.setTimeout(() => setDebounced(value), ms);
        return () => window.clearTimeout(id);
    }, [value, ms]);
    return debounced;
}

type Dialog = { row: StockRow | null; reason: AdjustmentReason } | null;

/**
 * Inventory overview (Figma "Inventory (multi-branch)" 213:6955, Theme B colours): ContentHeader,
 * four StatCards 189:360, location cards, state AdminTabs 185:125 with counts, and the
 * DataTable 192:532 of variants. Every figure is the server's; the location, tab and search
 * live in the URL (`?location=1&state=low&q=lego`).
 */
export function InventoryPage() {
    const { t, locale } = useI18n();
    const { data: me } = useMe();
    const [params, setParams] = useSearchParams();
    const location = /^\d+$/.test(params.get('location') ?? '') ? params.get('location')! : '';
    const stateParam = params.get('state');
    const state: StockState | '' = stockStates.includes(stateParam as StockState) ? (stateParam as StockState) : '';
    const [search, setSearch] = useState(params.get('q') ?? '');
    const debounced = useDebounced(search.trim());
    const summary = useInventorySummary(location);
    const stock = useStock({ location, state, search: debounced });
    const rows = useMemo(() => stock.data?.pages.flatMap((page) => page.data) ?? [], [stock.data]);
    const [dialog, setDialog] = useState<Dialog>(null);

    const canAdjust = can(me, 'inventory.adjust');
    const viewCost = can(me, 'catalog.view_cost');
    const data = summary.data;
    const locations = data?.locations ?? [];
    const here = locations.find((l) => l.id === data?.location_id);
    const hereName = here ? (locale === 'bn' ? (here.name_bn ?? here.name) : here.name) : '';
    const multi = locations.length > 1;

    const setParam = (key: string, value: string) =>
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
        if (debounced !== (params.get('q') ?? '')) setParam('q', debounced);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [debounced]);

    const actions = (
        <>
            <Link to="/inventory/movements" className={buttonClass('outline')}>
                <ClockCounterClockwiseIcon size={16} aria-hidden />
                {t('inventory.movementsAction')}
            </Link>
            {canAdjust && (
                <>
                    <Button variant="outline" onClick={() => setDialog({ row: null, reason: 'recount' })}>
                        <ListChecksIcon size={16} aria-hidden />
                        {t('inventory.stockCount')}
                    </Button>
                    <Button onClick={() => setDialog({ row: null, reason: 'damage' })}>
                        <SlidersHorizontalIcon size={16} aria-hidden />
                        {t('inventory.newAdjustment')}
                    </Button>
                </>
            )}
        </>
    );

    const filtered = Boolean(state || debounced);
    const cell = `${dataTable.td.replace("h-15", "h-14")} max-lg:h-auto max-lg:p-0`;
    const num = `${cell} t-admin-num tabular lg:text-end`;

    return (
        <section className="flex flex-col gap-6">
            <ContentHeader
                breadcrumb={[{ label: t('catalog.crumb') }, { label: t('inventory.crumb') }]}
                title={t('inventory.title')}
                subtitle={data && (multi ? t('inventory.subtitle', { count: locations.length }) : t('inventory.subtitleOne', { location: hereName }))}
                actions={actions}
            />

            {summary.isError ? (
                <QueryError error={summary.error} onRetry={() => void summary.refetch()} />
            ) : (
                data && (
                    <>
                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                            {viewCost && data.total_value !== undefined && data.value !== undefined ? (
                                <StatCard
                                    label={t('inventory.stat.value')}
                                    value={
                                        <span className="t-admin-currency-num-lg">
                                            {TAKA}
                                            {formatMoney(data.total_value)}
                                        </span>
                                    }
                                    hint={
                                        <>
                                            {t('inventory.stat.valueHint', { units: formatUnits(data.on_hand), location: hereName })}
                                            <Taka amount={data.value} signClass="t-admin-currency-caption" />
                                        </>
                                    }
                                />
                            ) : (
                                <StatCard
                                    label={t('inventory.stat.units')}
                                    value={<span className="t-admin-num-lg">{formatUnits(data.on_hand)}</span>}
                                    hint={t('inventory.stat.unitsHint', { location: hereName })}
                                />
                            )}
                            <StatCard edge="low-stock" label={t('inventory.stat.low')} value={<span className="t-admin-num-lg">{formatUnits(data.counts.low)}</span>} hint={t('inventory.stat.lowHint')} />
                            <StatCard
                                label={t('inventory.stat.out')}
                                value={<span className="t-admin-num-lg">{formatUnits(data.counts.out)}</span>}
                                hint={data.counts.out === 0 ? '—' : t('inventory.stat.outHint')}
                            />
                            <StatCard label={t('inventory.stat.reserved')} value={<span className="t-admin-num-lg">{formatUnits(data.reserved)}</span>} hint={t('inventory.stat.reservedHint')} />
                        </div>

                        <LocationCards locations={locations} selected={data.location_id} showValue={viewCost} onSelect={(id) => setParam('location', locations.find((l) => l.id === id)?.is_default ? '' : String(id))} />
                    </>
                )
            )}

            <div className="flex flex-wrap items-end gap-x-4 gap-y-3 border-b border-line-default">
                <div role="group" aria-label={t('inventory.tabs')} className="scrollbar-none -mb-px flex flex-1 gap-0.5 overflow-x-auto">
                    {tabs.map((tab) => (
                        <TabButton key={tab.count} active={state === tab.value} label={t(tab.label)} count={data?.counts[tab.count]} onClick={() => setParam('state', tab.value)} />
                    ))}
                </div>
                <SearchField
                    label={t('inventory.searchLabel')}
                    value={search}
                    onChange={(e) => setSearch(e.target.value)}
                    placeholder={t('inventory.searchPlaceholder')}
                    className="mb-2 w-full lg:w-72"
                />
            </div>

            {stock.isError ? (
                <QueryError error={stock.error} onRetry={() => void stock.refetch()} />
            ) : stock.isPending ? (
                <p className="t-admin-body text-fg-tertiary" aria-busy="true">
                    {t('common.loading')}
                </p>
            ) : rows.length === 0 ? (
                filtered ? (
                    <EmptyState reason="allClear" title={t('inventory.emptyFiltered')} body={t('inventory.emptyFilteredBody')} />
                ) : (
                    <EmptyState title={t('inventory.empty')} body={t('inventory.emptyBody')} />
                )
            ) : (
                <div className={dataTable.card}>
                    <table className={`${dataTable.table} lg:table-fixed`}>
                        <thead className={`hidden lg:table-header-group ${dataTable.head}`}>
                            {/* Figma DataTable 192:532 header: a border/strong rule under the column names. */}
                            <tr className="border-b border-line-strong">
                                <Th className="w-75">{t('inventory.col.product')}</Th>
                                <Th end className="w-24">
                                    {t('inventory.col.onHand')}
                                </Th>
                                <Th end className="w-24">
                                    {t('inventory.col.reserved')}
                                </Th>
                                <Th end className="w-27.5">
                                    {t('inventory.col.available')}
                                </Th>
                                <Th end className="w-25">
                                    {t('inventory.col.reorderAt')}
                                </Th>
                                {multi && <Th className="w-37.5">{t('inventory.col.elsewhere')}</Th>}
                                {viewCost && (
                                    <Th end className="w-24">
                                        {t('inventory.col.avgCost')}
                                    </Th>
                                )}
                                <th scope="col" className={dataTable.th}>
                                    <span className="sr-only">{t('inventory.col.actions')}</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map((row) => (
                                <tr key={row.variant_id} className={`flex flex-wrap items-center gap-x-4 gap-y-1 px-4 py-3 first:border-t-0 lg:table-row lg:p-0 lg:first:border-t ${dataTable.row}`}>
                                    <td className={`min-w-0 basis-full lg:basis-auto ${cell}`}>
                                        <VariantCell row={row} />
                                    </td>
                                    <td className={num}>
                                        <Label text={t('inventory.col.onHand')} />
                                        {formatUnits(row.on_hand)}
                                    </td>
                                    <td className={`${num} text-fg-secondary`}>
                                        <Label text={t('inventory.col.reserved')} />
                                        {formatUnits(row.reserved)}
                                    </td>
                                    <td className={`${cell} lg:text-end`}>
                                        <Label text={t('inventory.col.available')} />
                                        <AvailableCell available={row.available} state={row.state} />
                                    </td>
                                    <td className={`${num} text-fg-secondary`}>
                                        <Label text={t('inventory.col.reorderAt')} />
                                        {formatUnits(row.reorder_point)}
                                    </td>
                                    {multi && (
                                        <td className={`${cell} t-admin-body-strong text-fg-secondary`}>
                                            {row.elsewhere.map((e) => `${e.code.toUpperCase()} ${formatUnits(e.on_hand)}`).join(' · ') || '—'}
                                        </td>
                                    )}
                                    {viewCost && (
                                        <td className={`${num} whitespace-nowrap`}>
                                            <Label text={t('inventory.col.avgCost')} />
                                            {row.unit_cost !== undefined && <Taka amount={row.unit_cost} />}
                                        </td>
                                    )}
                                    <td className={`${cell} max-lg:basis-full`}>
                                        <div className="flex items-center gap-2.5">
                                            {canAdjust && (
                                                <Button variant="outline" size="sm" onClick={() => setDialog({ row, reason: row.state === 'out' ? 'found' : 'damage' })}>
                                                    {t('inventory.adjust')}
                                                </Button>
                                            )}
                                            <Link to={`/inventory/movements?variant=${row.variant_id}&sku=${encodeURIComponent(row.sku)}`} className={buttonClass('ghost', 'sm')}>
                                                {t('inventory.ledger')}
                                            </Link>
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                    <DataTableFooter summary={t('inventory.count', { count: rows.length })}>
                        {stock.hasNextPage && (
                            <Button variant="outline" size="sm" onClick={() => void stock.fetchNextPage()} disabled={stock.isFetchingNextPage}>
                                {stock.isFetchingNextPage ? t('common.loading') : t('common.loadMore')}
                            </Button>
                        )}
                    </DataTableFooter>
                </div>
            )}

            {data && (
                <AdjustmentDialog
                    open={dialog !== null}
                    onClose={() => setDialog(null)}
                    row={dialog?.row ?? null}
                    initialReason={dialog?.reason ?? 'damage'}
                    locations={locations}
                    locationId={data.location_id}
                />
            )}
        </section>
    );
}

/** Column name in front of a value on the stacked (below lg) row; hidden where the header shows. */
function Label({ text }: { text: string }) {
    return <span className="me-1.5 t-admin-caption text-fg-tertiary lg:hidden">{text}</span>;
}

function Th({ children, end, className = '' }: { children: ReactNode; end?: boolean; className?: string }) {
    return (
        <th scope="col" className={`${end ? dataTable.th.replace('text-start', 'text-end') : dataTable.th} ${className}`}>
            {children}
        </th>
    );
}
