import { useEffect, useMemo, useRef, useState, type ReactNode, type RefObject } from 'react';
import { Link, useSearchParams } from 'react-router';
import {
    ArrowClockwiseIcon,
    ArrowsLeftRightIcon,
    ClockCountdownIcon,
    CoinsIcon,
    HandCoinsIcon,
    ListChecksIcon,
    NotebookIcon,
    PackageIcon,
    ShoppingCartIcon,
    SlidersHorizontalIcon,
    SparkleIcon,
    WarningCircleIcon,
    WarningIcon,
    XCircleIcon,
} from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { can, useMe } from '@/features/auth/api';
import { ActionMenu, type ActionMenuItem } from '@/components/ui/ActionMenu';
import { Button, buttonClass } from '@/components/ui/Button';
import { ContentHeader } from '@/components/shell/ContentHeader';
import { DataTableFooter, dataTable } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { KpiCard } from '@/components/ui/KpiCard';
import { QueryError } from '@/components/ui/QueryError';
import { SearchField } from '@/components/ui/SearchField';
import { TabButton } from '@/components/ui/Tabs';
import { stockStates, useAdjustments, useAlerts, useInventorySummary, useStock, type AdjustmentReason, type InventoryLocation, type StockRow, type StockState } from './api';
import { AdjustmentDialog } from './AdjustmentDialog';
import { TransferDialog } from './TransferDialog';
import { LocationCards, StockStatusChip, TAKA, Taka, VariantCell, dhakaWhen, formatMoney, formatUnits, variantLabel } from './parts';
import { useWorkingBranch } from '@/components/shell/workingBranch';

const tabs: { value: StockState | ''; label: MessageKey; count: 'all' | StockState }[] = [
    { value: '', label: 'inventory.tab.all', count: 'all' },
    { value: 'low', label: 'inventory.tab.low', count: 'low' },
    { value: 'out', label: 'inventory.tab.out', count: 'out' },
    { value: 'overstock', label: 'inventory.tab.overstock', count: 'overstock' },
    { value: 'idle', label: 'inventory.tab.idle', count: 'idle' },
];

type Adjust = { reason: AdjustmentReason; row: StockRow | null } | null;
type Transfer = { fromId: number; toId: number | null; variantId: number | null; quantity: number | null } | null;

function useDebounced<T>(value: T, ms = 300): T {
    const [debounced, setDebounced] = useState(value);
    useEffect(() => {
        const id = window.setTimeout(() => setDebounced(value), ms);
        return () => window.clearTimeout(id);
    }, [value, ms]);
    return debounced;
}

/** At least 640 wide. jsdom has no matchMedia and lays out as a desktop. */
function useWide(): boolean {
    const query = '(min-width: 40rem)';
    const read = () => typeof window === 'undefined' || typeof window.matchMedia !== 'function' || window.matchMedia(query).matches;
    const [wide, setWide] = useState(read);
    useEffect(() => {
        if (typeof window.matchMedia !== 'function') return;
        const list = window.matchMedia(query);
        const update = () => setWide(list.matches);
        list.addEventListener('change', update);
        return () => list.removeEventListener('change', update);
    }, []);
    return wide;
}

/** "/" focuses the search, as in most admin tools — never while typing in a field, never with a modifier (⌘K stays the global search). */
function useSlashFocus(target: RefObject<HTMLInputElement | null>) {
    useEffect(() => {
        const onKey = (event: globalThis.KeyboardEvent) => {
            if (event.key !== '/' || event.metaKey || event.ctrlKey || event.altKey) return;
            const el = event.target as HTMLElement | null;
            if (el && (el.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(el.tagName))) return;
            if (document.querySelector('dialog[open]')) return;
            event.preventDefault();
            target.current?.focus();
            target.current?.select();
        };
        document.addEventListener('keydown', onKey);
        return () => document.removeEventListener('keydown', onKey);
    }, [target]);
}

/**
 * Inventory overview (Figma "B · 14 Inventory" 241:39494, reworked for day-to-day use within
 * Theme B). ContentHeader with Transfer stock · Stock count · New adjustment; KPI cards (Low and
 * Out are shortcuts to their tab); one card per branch, each a filter for the whole page; a
 * search over name, SKU and barcode (`filter[search]`, debounced, Enter commits at once for a
 * barcode scanner, "/" focuses it); the state tabs with server counts; and the DataTable
 * 192:532 of variants. Each row shows the variant as its own tag, one status chip, the figures
 * right-aligned, branch stock spelled out, and a single ⋯ menu: Adjust stock, Transfer, Create
 * PO (short rows), View ledger. Rows do not expand (owner, 2026-11-16): branch stock is in the
 * Other branches chips, history in View ledger.
 *
 * Every figure, state and count is the server's; nothing is computed here (rule 1). Location,
 * tab and search live in the URL (`?location=2&state=low&q=lego`). The list has a fixed server
 * order (product, then variant): `/inventory/stock` refuses `sort`, so no column sorts — sorting
 * one loaded page of a cursor-paginated list would be a lie.
 */
export function InventoryPage() {
    const { t, locale } = useI18n();
    const { data: me } = useMe();
    const wide = useWide();
    const [params, setParams] = useSearchParams();
    // No ?location: the sidebar's working branch when it is not the default (multiple branches only).
    const working = useWorkingBranch();
    const fallback = working.multi && working.current && !working.current.is_default ? String(working.current.id) : '';
    const location = /^\d+$/.test(params.get('location') ?? '') ? params.get('location')! : fallback;
    const stateParam = params.get('state');
    const state: StockState | '' = stockStates.includes(stateParam as StockState) ? (stateParam as StockState) : '';
    const q = (params.get('q') ?? '').trim();
    const [search, setSearch] = useState(q);
    const debounced = useDebounced(search.trim());
    const searchRef = useRef<HTMLInputElement>(null);
    useSlashFocus(searchRef);

    const summary = useInventorySummary(location);
    const stock = useStock({ location, state, search: q });
    const rows = useMemo(() => stock.data?.pages.flatMap((page) => page.data) ?? [], [stock.data]);
    const [adjust, setAdjust] = useState<Adjust>(null);
    const [transfer, setTransfer] = useState<Transfer>(null);
    const [flash, setFlash] = useState<number | null>(null);

    const canAdjust = can(me, 'inventory.adjust');
    const canTransfer = can(me, 'inventory.transfer');
    const canView = can(me, 'inventory.view');
    const canCreatePo = can(me, 'purchasing.create');
    const pending = useAdjustments({ status: 'pending' }, canView).data?.pages[0]?.meta.counts.pending ?? 0;
    const alertCounts = useAlerts({ kind: '' }, canView).data?.pages[0]?.meta.counts;
    const drift = (alertCounts?.ledger_drift ?? 0) + (alertCounts?.reserved_drift ?? 0);
    const viewCost = can(me, 'catalog.view_cost');
    const data = summary.data;
    const locations = data?.locations ?? [];
    const here = locations.find((l) => l.id === data?.location_id);
    const nameOf = (l: InventoryLocation) => (locale === 'bn' ? (l.name_bn ?? l.name) : l.name);
    const hereName = here ? nameOf(here) : '';
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

    // The typed search reaches the URL (and so the API) once typing pauses.
    useEffect(() => {
        if (debounced !== q) setParam('q', debounced);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [debounced]);
    // Back / forward or a cleared filter: the field follows the URL.
    useEffect(() => {
        if (q !== search.trim() && q !== debounced) setSearch(q);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [q]);

    // A saved adjustment or transfer: every inventory query is invalidated (api.ts), the row
    // refreshes in place, and it is briefly highlighted so the eye finds it again.
    useEffect(() => {
        if (flash === null) return;
        const id = window.setTimeout(() => setFlash(null), 2400);
        return () => window.clearTimeout(id);
    }, [flash]);

    const openTransfer = (row: StockRow | null, from?: { location_id: number; available: number }) => {
        if (!data) return;
        if (from) setTransfer({ fromId: from.location_id, toId: data.location_id, variantId: row?.variant_id ?? null, quantity: from.available });
        else setTransfer({ fromId: data.location_id, toId: null, variantId: row?.variant_id ?? null, quantity: null });
    };

    // `?transfer={id}` (Store settings › Branch setup, a branch blocking a single shop): open the
    // transfer dialog from that branch to the default location, then drop the parameter.
    const transferFrom = params.get('transfer');
    useEffect(() => {
        if (transferFrom === null || !data) return;
        const fromId = /^\d+$/.test(transferFrom) ? Number(transferFrom) : null;
        const home = locations.find((l) => l.is_default);
        if (fromId !== null && canTransfer && multi && locations.some((l) => l.id === fromId)) {
            setTransfer({ fromId, toId: home && home.id !== fromId ? home.id : null, variantId: null, quantity: null });
        }
        setParam('transfer', '');
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [transferFrom, data, canTransfer, multi]);

    const ledgerPath = (row: StockRow) => `/inventory/movements?variant=${row.variant_id}&sku=${encodeURIComponent(row.sku)}${location ? `&location=${location}` : ''}`;

    const rowItems = (row: StockRow): ActionMenuItem[] => {
        const items: ActionMenuItem[] = [];
        if (canAdjust) items.push({ key: 'adjust', label: t('inventory.action.adjust'), icon: <SlidersHorizontalIcon size={16} aria-hidden />, onSelect: () => setAdjust({ reason: 'damage', row }) });
        if (canTransfer && multi) {
            const from = row.transfer_from;
            const fromLocation = from ? locations.find((l) => l.id === from.location_id) : undefined;
            items.push(
                from && fromLocation
                    ? {
                          key: 'transfer',
                          label: t('inventory.action.transferIn', { count: formatUnits(from.available), branch: nameOf(fromLocation) }),
                          icon: <ArrowsLeftRightIcon size={16} aria-hidden />,
                          onSelect: () => openTransfer(row, from),
                      }
                    : { key: 'transfer', label: t('inventory.action.transfer'), icon: <ArrowsLeftRightIcon size={16} aria-hidden />, onSelect: () => openTransfer(row) },
            );
        }
        // Create PO opens the purchase order form with this SKU searched (purchasing, P5).
        if (canCreatePo && (row.state === 'low' || row.state === 'out'))
            items.push({ key: 'po', label: t('inventory.createPo'), icon: <ShoppingCartIcon size={16} aria-hidden />, to: `/purchasing/new?sku=${encodeURIComponent(row.sku)}` });
        items.push({ key: 'ledger', label: t('inventory.action.ledger'), icon: <NotebookIcon size={16} aria-hidden />, to: ledgerPath(row) });
        return items;
    };

    const headerActions = wide ? (
        <>
            <Link to="/inventory/restock" className={buttonClass('outline')}>
                <SparkleIcon size={16} aria-hidden />
                {t('aiRestock.navLink')}
            </Link>
            {multi && canTransfer && data && (
                <Button variant="outline" onClick={() => openTransfer(null)}>
                    <ArrowsLeftRightIcon size={16} aria-hidden />
                    {t('inventory.transferStock')}
                </Button>
            )}
            {canAdjust && (
                <>
                    <Button variant="outline" onClick={() => setAdjust({ reason: 'recount', row: null })}>
                        <ListChecksIcon size={16} aria-hidden />
                        {t('inventory.stockCount')}
                    </Button>
                    <Button onClick={() => setAdjust({ reason: 'damage', row: null })}>
                        <SlidersHorizontalIcon size={16} aria-hidden />
                        {t('inventory.newAdjustment')}
                    </Button>
                </>
            )}
        </>
    ) : (
        // Below 640: the primary action stays a button, the rest go behind one ⋯.
        <>
            {canAdjust && (
                <Button onClick={() => setAdjust({ reason: 'damage', row: null })}>
                    <SlidersHorizontalIcon size={16} aria-hidden />
                    {t('inventory.newAdjustment')}
                </Button>
            )}
            <ActionMenu
                label={t('inventory.moreActions')}
                variant="outline"
                size="md"
                items={[
                    { key: 'restock', label: t('aiRestock.navLink'), icon: <SparkleIcon size={16} aria-hidden />, to: '/inventory/restock' },
                    ...(multi && canTransfer && data
                        ? [{ key: 'transfer', label: t('inventory.transferStock'), icon: <ArrowsLeftRightIcon size={16} aria-hidden />, onSelect: () => openTransfer(null) }]
                        : []),
                    ...(canAdjust ? [{ key: 'count', label: t('inventory.stockCount'), icon: <ListChecksIcon size={16} aria-hidden />, onSelect: () => setAdjust({ reason: 'recount', row: null }) }] : []),
                ]}
            />
        </>
    );

    const filtered = Boolean(state);
    const updatedAt = Math.max(stock.dataUpdatedAt, summary.dataUpdatedAt);
    const refreshing = (stock.isFetching && !stock.isFetchingNextPage) || summary.isFetching;
    const refresh = () => {
        void summary.refetch();
        void stock.refetch();
    };
    const commitSearch = () => {
        const term = search.trim();
        if (term !== q) setParam('q', term);
        // A scanner types the next code straight after Enter: select it so the next scan replaces it.
        searchRef.current?.select();
    };
    const clearSearch = () => {
        setSearch('');
        setParam('q', '');
        searchRef.current?.focus();
    };

    // Table from 1280 (xl); below it each row is a card. Columns hug their figures; the product takes the rest.
    const cell = `${dataTable.td.replace('h-15', 'xl:h-16')} max-xl:h-auto max-xl:p-0`;
    const figure = `${cell} t-admin-num tabular xl:text-end max-xl:flex max-xl:min-w-0 max-xl:grow max-xl:basis-20 max-xl:flex-col max-xl:gap-0.5 max-xl:order-6`;

    return (
        <section className="flex flex-col gap-6 max-sm:gap-4">
            <ContentHeader
                breadcrumb={[{ label: t('catalog.crumb') }, { label: t('inventory.crumb') }]}
                title={t('inventory.title')}
                subtitle={data && (multi ? t('inventory.subtitle', { count: locations.length }) : t('inventory.subtitleOne', { location: hereName }))}
                actions={headerActions}
            />

            {(pending > 0 || drift > 0) && (
                <div className="flex flex-col gap-2 rounded-md bg-subtle px-4 py-3" aria-live="polite">
                    {pending > 0 && (
                        <p className="flex flex-wrap items-center gap-2 t-admin-body text-fg-primary">
                            <ClockCountdownIcon size={16} aria-hidden className="shrink-0 text-feedback-warning" />
                            <span className="min-w-0 flex-1">{t('inventory.notice.pending', { count: formatUnits(pending) })}</span>
                            <Link to="/inventory/adjustments" className={buttonClass('ghost', 'sm')}>
                                {t('inventory.notice.review')}
                            </Link>
                        </p>
                    )}
                    {drift > 0 && (
                        <p className="flex flex-wrap items-center gap-2 t-admin-body text-fg-primary">
                            <WarningCircleIcon size={16} aria-hidden className="shrink-0 text-feedback-danger" />
                            <span className="min-w-0 flex-1 t-admin-body-strong">{t('inventory.notice.drift', { count: formatUnits(drift) })}</span>
                            <Link to="/inventory/alerts" className={buttonClass('ghost', 'sm')}>
                                {t('inventory.notice.review')}
                            </Link>
                        </p>
                    )}
                </div>
            )}

            {summary.isError ? (
                <QueryError error={summary.error} onRetry={() => void summary.refetch()} />
            ) : data ? (
                <>
                    {/* Below 640: the value card across the top, the three counts in one compact row under it. */}
                    <div className="grid grid-cols-3 gap-3 sm:gap-4 lg:grid-cols-4">
                        {viewCost && data.total_value !== undefined && data.value !== undefined ? (
                            <KpiCard
                                className="col-span-3 lg:col-span-1"
                                icon={CoinsIcon}
                                tone="brand"
                                label={t('inventory.stat.value')}
                                value={
                                    // The figure opens the full valuation report (by variant, branch and category).
                                    <Link to="/inventory/valuation" title={t('inventory.stat.valueLink')} className="t-admin-currency-num-lg hover:underline">
                                        {TAKA}
                                        {formatMoney(data.total_value)}
                                        <span className="sr-only"> — {t('inventory.stat.valueLink')}</span>
                                    </Link>
                                }
                                hint={
                                    <>
                                        {t('inventory.stat.valueHint', { units: formatUnits(data.on_hand), location: hereName })}
                                        <Taka amount={data.value} signClass="t-admin-currency-caption" />
                                    </>
                                }
                            />
                        ) : (
                            <KpiCard
                                className="col-span-3 lg:col-span-1"
                                icon={PackageIcon}
                                tone="brand"
                                label={t('inventory.stat.units')}
                                value={<span className="t-admin-num-lg">{formatUnits(data.on_hand)}</span>}
                                hint={t('inventory.stat.unitsHint', { location: hereName })}
                            />
                        )}
                        <KpiCard
                            icon={WarningIcon}
                            tone="warning"
                            compact
                            label={t('inventory.stat.low')}
                            value={<span className="t-admin-num-lg">{formatUnits(data.counts.low)}</span>}
                            hint={t('inventory.stat.lowHint')}
                            active={state === 'low'}
                            onClick={() => setParam('state', state === 'low' ? '' : 'low')}
                        />
                        <KpiCard
                            icon={XCircleIcon}
                            tone="danger"
                            compact
                            label={t('inventory.stat.out')}
                            value={<span className="t-admin-num-lg">{formatUnits(data.counts.out)}</span>}
                            hint={data.counts.out === 0 ? '—' : t('inventory.stat.outHint')}
                            active={state === 'out'}
                            onClick={() => setParam('state', state === 'out' ? '' : 'out')}
                        />
                        <KpiCard
                            icon={HandCoinsIcon}
                            tone="info"
                            compact
                            label={t('inventory.stat.reserved')}
                            value={<span className="t-admin-num-lg">{formatUnits(data.reserved)}</span>}
                            hint={t('inventory.stat.reservedHint')}
                        />
                    </div>

                    {multi && <LocationCards locations={locations} selected={data.location_id} showValue={viewCost} onSelect={(id) => setParam('location', fallback === '' && locations.find((l) => l.id === id)?.is_default ? '' : String(id))} />}
                </>
            ) : (
                <div aria-hidden className="grid grid-cols-3 gap-3 sm:gap-4 lg:grid-cols-4">
                    {[0, 1, 2, 3].map((i) => (
                        <div key={i} className={`h-28 rounded-md bg-skeleton motion-safe:animate-pulse max-sm:h-20 ${i === 0 ? 'col-span-3 lg:col-span-1' : ''}`} />
                    ))}
                </div>
            )}

            <div className="flex flex-col gap-3">
                {/* Search + freshness: the search is a server filter (`filter[search]`), never a filter over loaded rows. */}
                <div className="flex flex-wrap items-center gap-x-4 gap-y-2">
                    <SearchField
                        ref={searchRef}
                        label={t('inventory.search.label')}
                        placeholder={t('inventory.search.placeholder')}
                        shortcut="/"
                        aria-keyshortcuts="/"
                        value={search}
                        onChange={(event) => setSearch(event.target.value)}
                        onKeyDown={(event) => {
                            if (event.key === 'Enter') {
                                event.preventDefault();
                                commitSearch();
                            } else if (event.key === 'Escape' && search) {
                                event.preventDefault();
                                clearSearch();
                            }
                        }}
                        enterKeyHint="search"
                        autoComplete="off"
                        spellCheck={false}
                        className="w-full sm:w-96"
                    />
                    <p className="min-w-0 flex-1 t-admin-caption text-fg-secondary tabular" aria-live="polite">
                        {q && !stock.isPending && rows.length > 0 && t(stock.hasNextPage ? 'inventory.resultsMore' : 'inventory.results', { count: formatUnits(rows.length) })}
                    </p>
                    <div className="flex items-center gap-2 t-admin-caption text-fg-tertiary tabular">
                        {updatedAt > 0 && <span>{t('inventory.updated', { time: dhakaWhen(updatedAt, locale).time })}</span>}
                        <Button variant="ghost" size="sm" onClick={refresh} disabled={refreshing} aria-label={refreshing ? t('inventory.refreshing') : t('inventory.refresh')}>
                            <ArrowClockwiseIcon size={16} aria-hidden className={refreshing ? 'motion-safe:animate-spin' : ''} />
                            <span className="max-sm:sr-only">{t('inventory.refresh')}</span>
                        </Button>
                    </div>
                </div>

                {/* B · 14 "tabs" 241:39551: the state tabs on their baseline; scrolls sideways on a phone. */}
                <div role="group" aria-label={t('inventory.tabs')} className="scrollbar-none flex overflow-x-auto border-b border-line-default max-sm:-mx-4 max-sm:px-4">
                    {tabs.map((tab) => (
                        <TabButton key={tab.count} active={state === tab.value} label={t(tab.label)} count={data?.counts[tab.count]} onClick={() => setParam('state', tab.value)} />
                    ))}
                </div>
            </div>

            {stock.isError ? (
                <QueryError error={stock.error} onRetry={() => void stock.refetch()} />
            ) : stock.isPending ? (
                <SkeletonRows label={t('common.loading')} />
            ) : rows.length === 0 ? (
                q ? (
                    <EmptyState
                        reason="notFound"
                        title={t('inventory.emptySearch', { term: q })}
                        body={t('inventory.emptySearchBody')}
                        actions={
                            <Button variant="outline" onClick={clearSearch}>
                                {t('inventory.clearSearch')}
                            </Button>
                        }
                    />
                ) : filtered ? (
                    <EmptyState reason="allClear" title={t('inventory.emptyFiltered')} body={t('inventory.emptyFilteredBody')} />
                ) : (
                    <EmptyState title={t('inventory.empty')} body={t('inventory.emptyBody')} />
                )
            ) : (
                // The card clips its corners but not its header: `overflow-clip` keeps the sticky header working.
                <div className={`${dataTable.card.replace('overflow-hidden', 'overflow-clip')} transition-opacity duration-150 ease-standard ${stock.isPlaceholderData ? 'opacity-60' : ''}`} aria-busy={stock.isPlaceholderData}>
                    <table className={`${dataTable.table} xl:table-fixed max-xl:block`}>
                        <thead className={`hidden xl:table-header-group ${dataTable.head} sticky top-15 z-10`}>
                            {/* Figma DataTable 192:532 header: a border/strong rule under the column names. */}
                            <tr className="border-b border-line-strong">
                                {/* Percentage widths (the 1440 layout): on a wide display every column grows, instead of the
                                    product column alone leaving the figures bunched at the right (owner, 2026-11-16). */}
                                <Th className="w-[28%]">{t('inventory.col.product')}</Th>
                                <Th className="w-[10%]">{t('inventory.col.status')}</Th>
                                <Th end className="w-[8%]">
                                    {t('inventory.col.onHand')}
                                </Th>
                                <Th end className="w-[8%]">
                                    {t('inventory.col.reserved')}
                                </Th>
                                <Th end className="w-[8%]">
                                    {t('inventory.col.available')}
                                </Th>
                                <Th end className="w-[8%]">
                                    {t('inventory.col.reorderAt')}
                                </Th>
                                {multi && <Th className="w-[13%]">{t('inventory.col.elsewhere')}</Th>}
                                {viewCost && (
                                    <Th end className="w-[10%]">
                                        {t('inventory.col.avgCost')}
                                    </Th>
                                )}
                                <th scope="col" className={`${dataTable.th} w-12`}>
                                    <span className="sr-only">{t('inventory.col.actions')}</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody className="max-xl:block">
                            {rows.map((row) => {
                                const name = variantLabel(row, locale);
                                return (
                                        <tr
                                            key={row.variant_id}
                                            className={`flex flex-wrap items-center gap-x-4 gap-y-3 px-4 py-3 transition-colors duration-300 ease-standard max-xl:border-t max-xl:border-line-default max-xl:first:border-t-0 xl:table-row xl:p-0 ${dataTable.row} ${
                                                flash === row.variant_id ? 'bg-brand-subtle' : ''
                                            }`}
                                        >
                                            <td className={`min-w-0 max-xl:order-1 max-xl:flex-1 max-xl:basis-0 ${cell}`}>
                                                <VariantCell row={row} />
                                            </td>
                                            <td className={`${cell} max-xl:order-5 max-xl:basis-full`}>
                                                <StockStatusChip state={row.state} />
                                            </td>
                                            <td className={figure}>
                                                <Label text={t('inventory.col.onHand')} />
                                                {formatUnits(row.on_hand)}
                                            </td>
                                            <td className={`${figure} text-fg-secondary`}>
                                                <Label text={t('inventory.col.reserved')} />
                                                {formatUnits(row.reserved)}
                                            </td>
                                            <td className={`${figure} t-admin-body-strong`}>
                                                <Label text={t('inventory.col.available')} />
                                                {formatUnits(row.available)}
                                            </td>
                                            <td className={`${figure} text-fg-secondary`}>
                                                <Label text={t('inventory.col.reorderAt')} />
                                                {formatUnits(row.reorder_point)}
                                            </td>
                                            {multi && (
                                                <td className={`${cell} max-xl:order-7 max-xl:basis-full`}>
                                                    <Label text={t('inventory.col.elsewhere')} />
                                                    <BranchStock row={row} locations={locations} />
                                                </td>
                                            )}
                                            {viewCost && (
                                                <td className={`${figure} whitespace-nowrap`}>
                                                    <Label text={t('inventory.col.avgCost')} />
                                                    {row.unit_cost !== undefined ? <Taka amount={row.unit_cost} /> : '—'}
                                                </td>
                                            )}
                                            <td className={`${cell} max-xl:order-3 xl:text-end`}>
                                                <ActionMenu label={t('inventory.rowMenu', { name })} items={rowItems(row)} />
                                            </td>
                                        </tr>
                                );
                            })}
                        </tbody>
                    </table>
                    {stock.hasNextPage && (
                        <DataTableFooter summary={t('inventory.count', { count: rows.length })}>
                            <Button variant="outline" size="sm" onClick={() => void stock.fetchNextPage()} disabled={stock.isFetchingNextPage}>
                                {stock.isFetchingNextPage ? t('common.loading') : t('common.loadMore')}
                            </Button>
                        </DataTableFooter>
                    )}
                </div>
            )}

            {data && (
                <AdjustmentDialog
                    open={adjust !== null}
                    onClose={() => setAdjust(null)}
                    row={adjust?.row ?? null}
                    initialReason={adjust?.reason ?? 'damage'}
                    locations={locations}
                    locationId={data.location_id}
                    onSaved={setFlash}
                />
            )}
            {data && multi && (
                <TransferDialog
                    open={transfer !== null}
                    onClose={() => setTransfer(null)}
                    locations={locations}
                    fromId={transfer?.fromId ?? data.location_id}
                    toId={transfer?.toId ?? null}
                    variantId={transfer?.variantId ?? null}
                    quantity={transfer?.quantity ?? null}
                    onSaved={setFlash}
                />
            )}
        </section>
    );
}


/**
 * What the other branches hold available, spelled out: a short code chip per branch with the full
 * branch name as tooltip and for screen readers ("Chattogram · Agrabad: 7 available").
 */
function BranchStock({ row, locations }: { row: StockRow; locations: InventoryLocation[] }) {
    const { t, locale } = useI18n();
    if (row.elsewhere.length === 0) return <span className="t-admin-caption text-fg-tertiary">{t('inventory.noOtherStock')}</span>;
    return (
        <ul className="inline-flex flex-wrap gap-1.5">
            {row.elsewhere.map((e) => {
                const l = locations.find((x) => x.id === e.location_id);
                const name = l ? (locale === 'bn' ? (l.name_bn ?? l.name) : l.name) : e.code.toUpperCase();
                const full = t('inventory.branchAvailable', { name, count: formatUnits(e.available) });
                return (
                    <li
                        key={e.location_id}
                        title={full}
                        className={`inline-flex items-center gap-1 rounded-xs border border-line-default px-1.5 t-admin-caption tabular ${e.available > 0 ? 'bg-surface text-fg-primary' : 'bg-subtle text-fg-tertiary'}`}
                    >
                        <span aria-hidden className="t-admin-caption-strong">
                            {e.code.toUpperCase()}
                        </span>
                        <span aria-hidden>{formatUnits(e.available)}</span>
                        <span className="sr-only">{full}</span>
                    </li>
                );
            })}
        </ul>
    );
}


/** Placeholder rows while the first page loads: the card's shape, not a blank page. Not a table, so nothing reads it as data. */
function SkeletonRows({ label }: { label: string }) {
    return (
        <div className={dataTable.card} aria-busy="true">
            <span className="sr-only">{label}</span>
            <div aria-hidden className="h-10 bg-subtle max-xl:hidden" />
            {[0, 1, 2, 3, 4, 5].map((i) => (
                <div key={i} aria-hidden className="flex items-center gap-4 border-t border-line-default px-4 py-3 first:border-t-0 xl:first:border-t">
                    <div className="size-10 shrink-0 rounded-xs-plus bg-skeleton motion-safe:animate-pulse" />
                    <div className="flex min-w-0 flex-1 flex-col gap-2">
                        <div className="h-3.5 w-3/5 rounded-xs bg-skeleton motion-safe:animate-pulse" />
                        <div className="h-3 w-2/5 rounded-xs bg-skeleton motion-safe:animate-pulse" />
                    </div>
                    <div className="h-5.5 w-20 rounded-xs bg-skeleton motion-safe:animate-pulse max-sm:hidden" />
                    <div className="h-3.5 w-24 rounded-xs bg-skeleton motion-safe:animate-pulse max-md:hidden" />
                </div>
            ))}
        </div>
    );
}

/** Column name over a value on the stacked (below xl) row; hidden where the header shows. */
function Label({ text }: { text: string }) {
    return <span className="t-admin-caption text-fg-tertiary xl:hidden max-xl:me-1.5">{text}</span>;
}

function Th({ children, end, className = '' }: { children: ReactNode; end?: boolean; className?: string }) {
    return (
        <th scope="col" className={`${end ? dataTable.th.replace('text-start', 'text-end') : dataTable.th} ${className}`}>
            {children}
        </th>
    );
}
