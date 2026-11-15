import { useEffect, useId, useLayoutEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import { Link, useSearchParams } from 'react-router';
import {
    ArrowLeftIcon,
    ArrowRightIcon,
    ArrowSquareOutIcon,
    CalendarBlankIcon,
    CheckCircleIcon,
    CheckIcon,
    ColumnsIcon,
    CopyIcon,
    DotsThreeIcon,
    FunnelIcon,
    PackageIcon,
    PlusIcon,
    PrinterIcon,
    ReceiptIcon,
    ShieldWarningIcon,
    TruckIcon,
    WarningCircleIcon,
    WarningIcon,
    XIcon,
} from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { can, useMe } from '@/features/auth/api';
import { ActionMenu, type ActionMenuItem } from '@/components/ui/ActionMenu';
import { Button, buttonClass } from '@/components/ui/Button';
import { ContentHeader } from '@/components/shell/ContentHeader';
import { CountBadge } from '@/components/ui/CountBadge';
import { DataTableFooter, dataTable } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { IconButton } from '@/components/ui/IconButton';
import { KpiCard } from '@/components/ui/KpiCard';
import { QueryError } from '@/components/ui/QueryError';
import { SearchField } from '@/components/ui/SearchField';
import { Select } from '@/components/ui/Select';
import { TabList, tabClass } from '@/components/ui/Tabs';
import { useToast } from '@/components/ui/Toast';
import { ApiError } from '@/lib/api';
import {
    paymentStatuses,
    riskLevels,
    sources,
    useBulkConfirm,
    useOrders,
    type BoardFilters,
    type BoardTab,
    type BulkResult,
    type OrderRow,
    type OrderSource,
    type PaymentStatus,
    type RiskLevel,
} from './api';
import { openInvoices, useBulkPack } from './composeApi';
import { useBulkFulfil, type FulfilMove } from './fulfilmentApi';
import { CourierBookingPanel } from '@/features/couriers';
import { Taka, ageLabel, dhakaDate, dhakaParts, isZeroAmount, rangeLabel } from './format';
import { OrderStatusBadge, PaymentChip, RiskPill, paymentLabel, sourceLabel } from './parts';

/** Status tabs in Figma 370:66878 order, with On hold and Processing kept so every pre-ship order has a home. */
const tabs: BoardTab[] = ['all', 'pending', 'verify', 'on_hold', 'confirmed', 'processing', 'ready_to_ship', 'shipped', 'delivered', 'returned', 'cancelled'];
const ranges = ['all', '1', '7', '30', '90'] as const;
type Range = (typeof ranges)[number];
/** On hold and Processing are not in the Figma tab row (370:66878): they wait behind the row's ⋯ menu and join the row only while open. */
const quietTabs = new Set<BoardTab>(['on_hold', 'processing']);
const preShip = new Set(['pending', 'on_hold', 'confirmed', 'processing', 'ready_to_ship']);
/** Statuses a courier can be booked from (the booking panel and the server have the final say). */
const bookable = new Set(['confirmed', 'processing', 'ready_to_ship']);

/** Columns the operator may hide (the column picker 370:66905); order, customer, total and status always show. */
const optionalColumns = ['placed', 'payment', 'risk', 'courier'] as const;
type OptionalColumn = (typeof optionalColumns)[number];

function useDebounced<T>(value: T, ms = 250): T {
    const [debounced, setDebounced] = useState(value);
    useEffect(() => {
        const id = window.setTimeout(() => setDebounced(value), ms);
        return () => window.clearTimeout(id);
    }, [value, ms]);
    return debounced;
}

const pick = <T extends string>(value: string | null, allowed: readonly T[], fallback: T | ''): T | '' => (allowed.includes(value as T) ? (value as T) : fallback);

const tabLabel = (tab: BoardTab): MessageKey => `orders.tab.${tab}` as MessageKey;

/** The hidden board columns, per staff member, in this browser only (a convenience, never shared state). */
function useHiddenColumns(userId: number | undefined): [Set<OptionalColumn>, (column: OptionalColumn) => void] {
    const key = `toymart.admin.orders.columns.${userId ?? 'anon'}`;
    const read = (): Set<OptionalColumn> => {
        try {
            const parsed: unknown = JSON.parse(localStorage.getItem(key) ?? '[]');
            return new Set(Array.isArray(parsed) ? optionalColumns.filter((column) => parsed.includes(column)) : []);
        } catch {
            return new Set();
        }
    };
    const [hidden, setHidden] = useState<Set<OptionalColumn>>(read);
    // eslint-disable-next-line react-hooks/exhaustive-deps
    useEffect(() => setHidden(read()), [key]);
    const toggle = (column: OptionalColumn) =>
        setHidden((current) => {
            const next = new Set(current);
            if (next.has(column)) next.delete(column);
            else next.add(column);
            try {
                localStorage.setItem(key, JSON.stringify([...next]));
            } catch {
                // Private mode or blocked storage: the choice lasts for this visit only.
            }
            return next;
        });
    return [hidden, toggle];
}

/**
 * Order board (Figma Premium pass "B · 02 Orders board" 370:66862, row menu 371:67709): the
 * ContentHeader with the date range, four KpiCards (Orders · Pending · Verify · Ready to ship,
 * the server's board counts — the same numbers the tabs show), status AdminTabs with the
 * Anomalies pill at the end, and one table card holding the filter bar, the DataTable with a
 * ⋯ row menu, and the pager. The floating BulkActionBar 193:740 handles selections. Every
 * filter is a server-side allowlisted parameter; the URL carries all of it (`?tab=pending&risk=high`).
 */
export function OrdersPage() {
    const { t } = useI18n();
    const { data: me } = useMe();
    const toast = useToast();
    const [params, setParams] = useSearchParams();
    const tab = (pick(params.get('tab'), [...tabs, 'anomalies'] as BoardTab[], 'all') || 'all') as BoardTab;
    const range = (pick(params.get('range'), ranges, 'all') || 'all') as Range;
    const [search, setSearch] = useState(params.get('q') ?? '');
    const debounced = useDebounced(search.trim());
    const cursor = params.get('cursor');
    const today = dhakaDate(0);
    const desktop = useDesktop();
    const [filtersOpen, setFiltersOpen] = useState(false);
    const [hidden, toggleColumn] = useHiddenColumns(me?.id);
    const shows = (column: OptionalColumn) => !hidden.has(column);

    const filters: BoardFilters = useMemo(
        () => ({
            tab,
            payment: pick(params.get('payment'), paymentStatuses, '') as PaymentStatus | '',
            source: pick(params.get('source'), sources, '') as OrderSource | '',
            risk: pick(params.get('risk'), riskLevels, '') as RiskLevel | '',
            from: range === 'all' ? '' : dhakaDate(Number(range) - 1),
            to: range === 'all' ? '' : today,
            search: params.get('q') ?? '',
            onlyAnomalies: params.get('anomalies') === '1',
        }),
        [params, tab, range, today],
    );
    const orders = useOrders(filters, cursor);
    const bulk = useBulkConfirm();
    const [selected, setSelected] = useState<Set<number>>(new Set());
    const [result, setResult] = useState<{ done: number; failed: BulkResult[] } | null>(null);
    const canConfirm = can(me, 'orders.confirm');
    const pack = useBulkPack();
    const [printing, setPrinting] = useState(false);
    const canPack = can(me, 'orders.update');
    // Manual fulfilment (P5, until Phase 6 courier booking): orders.update + shipments.book.
    const shipBulk = useBulkFulfil('ship');
    const deliverBulk = useBulkFulfil('deliver');
    const canFulfil = canPack && can(me, 'shipments.book');
    const canPrint = can(me, 'orders.view') && can(me, 'customers.view_pii');
    // P6: bulk courier submission from the board (orders.update + shipments.book), via the booking panel.
    const [booking, setBooking] = useState<number[] | null>(null);

    const rows = orders.data?.data ?? [];
    const counts = orders.data?.meta.counts ?? {};
    const next = orders.data?.meta.next_cursor ?? null;
    const prev = orders.data?.meta.prev_cursor ?? null;

    const setParam = (changes: Record<string, string>, keepCursor = false) =>
        setParams(
            (current) => {
                const nextParams = new URLSearchParams(current);
                for (const [key, value] of Object.entries(changes)) {
                    if (value) nextParams.set(key, value);
                    else nextParams.delete(key);
                }
                if (!keepCursor && !('cursor' in changes)) nextParams.delete('cursor');
                return nextParams;
            },
            { replace: true },
        );
    const pickTab = (value: BoardTab) => setParam({ tab: value === 'all' ? '' : value });

    useEffect(() => {
        if (debounced !== (params.get('q') ?? '')) setParam({ q: debounced });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [debounced]);

    // A view change (another tab) clears the selection (admin spec, bulk selection rule).
    useEffect(() => {
        setSelected(new Set());
        setResult(null);
    }, [tab]);

    const toggle = (id: number) =>
        setSelected((current) => {
            const nextSet = new Set(current);
            if (nextSet.has(id)) nextSet.delete(id);
            else nextSet.add(id);
            return nextSet;
        });
    const allOnPage = rows.length > 0 && rows.every((row) => selected.has(row.id));
    const toggleAll = () => setSelected(allOnPage ? new Set() : new Set(rows.map((row) => row.id)));

    const confirmSelected = () => {
        const ids = [...selected];
        bulk.mutate(ids, {
            onSuccess: (response) => {
                const failed = response.data.filter((r) => r.outcome !== 'done');
                // Keep only the failures selected, so the operator can act on exactly those.
                setSelected(new Set(failed.map((r) => r.order_id)));
                setResult({ done: response.meta.done, failed });
                if (failed.length === 0) toast.notify(t('orders.bulk.allDone', { count: response.meta.done }));
            },
            onError: (error) => toast.notify(error instanceof ApiError ? error.problem.title : t('error.title'), 'danger'),
        });
    };

    const packSelected = () => {
        const ids = [...selected];
        pack.mutate(ids, {
            onSuccess: (response) => {
                const failed = response.data.filter((r) => r.outcome !== 'done');
                setSelected(new Set(failed.map((r) => r.order_id)));
                setResult({ done: response.meta.done, failed });
                if (failed.length === 0) toast.notify(t('orders.bulk.packedAll', { count: response.meta.done }));
            },
            onError: (error) => toast.notify(error instanceof ApiError ? error.problem.title : t('error.title'), 'danger'),
        });
    };

    const fulfilSelected = (move: FulfilMove) => {
        const ids = [...selected];
        (move === 'ship' ? shipBulk : deliverBulk).mutate(ids, {
            onSuccess: (response) => {
                const failed = response.data.filter((r) => r.outcome !== 'done');
                setSelected(new Set(failed.map((r) => r.order_id)));
                setResult({ done: response.meta.done, failed });
                if (failed.length === 0) toast.notify(t(move === 'ship' ? 'orders.bulk.shippedAll' : 'orders.bulk.deliveredAll', { count: response.meta.done }));
            },
            onError: (error) => toast.notify(error instanceof ApiError ? error.problem.title : t('error.title'), 'danger'),
        });
    };

    const print = (ids: number[]) => {
        setPrinting(true);
        openInvoices(ids)
            .then(({ missing, notInvoiced }) => {
                if (missing.length > 0) toast.notify(t('orders.invoice.missing', { count: missing.length }), 'danger');
                if (notInvoiced.length > 0) toast.notify(t('invoices.bulk.notInvoiced', { count: notInvoiced.length }), 'danger');
            })
            .catch((error: unknown) => toast.notify(error instanceof ApiError ? (error.problem.detail ?? error.problem.title) : t('orders.invoice.failed'), 'danger'))
            .finally(() => setPrinting(false));
    };

    /** The row menu's Confirm: the same server bulk endpoint with one order, so the state machine still decides. */
    const confirmOne = (row: OrderRow) =>
        bulk.mutate([row.id], {
            onSuccess: (response) => {
                const failure = response.data.find((r) => r.outcome !== 'done');
                if (failure) toast.notify(failure.detail ?? t(failure.outcome === 'not_found' ? 'orders.bulk.notFound' : 'orders.bulk.conflict'), 'danger');
                else toast.notify(t('orders.action.confirmed', { number: row.number }));
            },
            onError: (error) => toast.notify(error instanceof ApiError ? error.problem.title : t('error.title'), 'danger'),
        });

    const copyNumber = (row: OrderRow) => {
        const done = () => toast.notify(t('orders.row.copied', { number: row.number }));
        if (navigator.clipboard) void navigator.clipboard.writeText(row.number).then(done, () => toast.notify(t('orders.row.copyFailed'), 'danger'));
        else toast.notify(t('orders.row.copyFailed'), 'danger');
    };

    /** The ⋯ row menu (Figma ActionMenu 371:68992): only actions the board already offers, each behind its permission. */
    const rowMenu = (row: OrderRow): ActionMenuItem[] => [
        { key: 'open', label: t('orders.row.open'), icon: <ArrowSquareOutIcon size={16} aria-hidden />, to: `/orders/${row.id}` },
        ...(canConfirm && row.status === 'pending' ? [{ key: 'confirm', label: t('orders.action.confirm'), icon: <CheckIcon size={16} aria-hidden />, onSelect: () => confirmOne(row) }] : []),
        ...(canFulfil && bookable.has(row.status) ? [{ key: 'book', label: t('orders.bulk.bookCourier'), icon: <TruckIcon size={16} aria-hidden />, onSelect: () => setBooking([row.id]) }] : []),
        ...(canPrint ? [{ key: 'print', label: t('orders.invoice.print'), icon: <PrinterIcon size={16} aria-hidden />, onSelect: () => print([row.id]) }] : []),
        { key: 'copy', label: t('orders.row.copy'), icon: <CopyIcon size={16} aria-hidden />, onSelect: () => copyNumber(row) },
    ];

    const rangeText = (value: Range): string =>
        value === 'all' ? t('orders.range.all') : value === '1' ? t('orders.range.today', { range: rangeLabel(today, today) }) : t(`orders.range.${value}` as MessageKey, { range: rangeLabel(dhakaDate(Number(value) - 1), today) });

    const subtitleCount = counts.all ?? 0;
    const highRisk = rows.filter((row) => selected.has(row.id) && row.risk_level === 'high').length;
    const activeFilters = [filters.payment, filters.source, filters.risk, range === 'all' ? '' : range, filters.onlyAnomalies ? '1' : ''].filter(Boolean).length;
    const periodLabel = range === 'all' ? t('orders.kpi.orders') : t('orders.kpi.ordersIn', { range: rangeLabel(dhakaDate(Number(range) - 1), today) });

    const rangeSelect = (
        <label className="relative flex w-full sm:w-65">
            <span className="sr-only">{t('orders.range')}</span>
            <CalendarBlankIcon size={16} aria-hidden className="pointer-events-none absolute start-3 top-1/2 z-10 -translate-y-1/2 text-fg-secondary" />
            <Select className="w-full [&_select]:h-9 [&_select]:ps-9" value={range} onChange={(e) => setParam({ range: e.target.value === 'all' ? '' : e.target.value })}>
                {ranges.map((value) => (
                    <option key={value} value={value}>
                        {rangeText(value)}
                    </option>
                ))}
            </Select>
        </label>
    );

    const filterControls = (
        <>
            <FilterSelect label={t('orders.filter.payment')} value={filters.payment} onChange={(v) => setParam({ payment: v })} all={t('orders.filter.paymentAll')}>
                {paymentStatuses.map((value) => (
                    <option key={value} value={value}>
                        {t(paymentLabel(value))}
                    </option>
                ))}
            </FilterSelect>
            <FilterSelect label={t('orders.filter.source')} value={filters.source} onChange={(v) => setParam({ source: v })} all={t('orders.filter.sourceAll')}>
                {sources.map((value) => (
                    <option key={value} value={value}>
                        {t(sourceLabel(value))}
                    </option>
                ))}
            </FilterSelect>
            <FilterSelect label={t('orders.filter.risk')} value={filters.risk} onChange={(v) => setParam({ risk: v })} all={t('orders.filter.riskAll')}>
                {riskLevels.map((value) => (
                    <option key={value} value={value}>
                        {t(`orders.risk.level.${value}` as MessageKey)}
                    </option>
                ))}
            </FilterSelect>
            <label className="inline-flex items-center gap-2 px-2 t-admin-body whitespace-nowrap text-fg-primary">
                <button
                    type="button"
                    role="switch"
                    aria-checked={filters.onlyAnomalies}
                    onClick={() => setParam({ anomalies: filters.onlyAnomalies ? '' : '1' })}
                    className={`relative inline-flex h-5 w-9 shrink-0 items-center rounded-full p-0.5 transition-colors duration-150 ease-standard ${filters.onlyAnomalies ? 'bg-brand' : 'bg-line-strong'}`}
                >
                    <span aria-hidden className={`size-4 rounded-full bg-surface shadow-e1 transition-transform duration-150 ease-standard ${filters.onlyAnomalies ? 'translate-x-4' : 'translate-x-0'}`} />
                </button>
                {t('orders.filter.onlyAnomalies')}
            </label>
        </>
    );

    const searchField = (
        <SearchField
            label={t('orders.searchLabel')}
            placeholder={t('orders.searchPlaceholder')}
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            className={desktop ? 'min-w-56 flex-1' : 'min-w-0 flex-1'}
        />
    );

    /** Pending, Verify and Ready to ship are shortcuts to their tab; a second click goes back to All. */
    const shortcut = (value: BoardTab) => ({ active: tab === value, onClick: () => pickTab(tab === value ? 'all' : value) });
    const figure = (count: number | undefined) => <span className="t-admin-num-lg">{orders.data ? (count ?? 0) : '—'}</span>;

    const kpis = (
        <div role="group" aria-label={t('orders.kpi.label')} className="grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-4">
            <KpiCard
                icon={ReceiptIcon}
                tone="brand"
                compact
                label={periodLabel}
                value={figure(counts.all)}
                hint={t('orders.kpi.ordersHint', { channel: filters.source ? t(sourceLabel(filters.source)) : t('orders.kpi.allChannels') })}
            />
            <KpiCard icon={WarningIcon} tone="warning" compact label={t('orders.tab.pending')} value={figure(counts.pending)} hint={t('orders.kpi.pendingHint')} {...shortcut('pending')} />
            <KpiCard icon={ShieldWarningIcon} tone="danger" compact label={t('orders.tab.verify')} value={figure(counts.verify)} hint={t('orders.kpi.verifyHint')} {...shortcut('verify')} />
            <KpiCard icon={TruckIcon} tone="info" compact label={t('orders.tab.ready_to_ship')} value={figure(counts.ready_to_ship)} hint={t('orders.kpi.readyHint')} {...shortcut('ready_to_ship')} />
        </div>
    );

    const pager = (
        <>
            <Button variant="outline" size="sm" disabled={!prev} onClick={() => setParam({ cursor: prev ?? '' }, true)}>
                <ArrowLeftIcon size={16} aria-hidden />
                {t('orders.newer')}
            </Button>
            <Button variant="outline" size="sm" disabled={!next} onClick={() => setParam({ cursor: next ?? '' }, true)}>
                {t('orders.older')}
                <ArrowRightIcon size={16} aria-hidden />
            </Button>
        </>
    );

    const status = orders.isError ? (
        <QueryError error={orders.error} onRetry={() => void orders.refetch()} />
    ) : orders.isPending ? (
        <p className="t-admin-body text-fg-tertiary" aria-busy="true">
            {t('common.loading')}
        </p>
    ) : null;

    const desktopTable = (
        <div className={dataTable.card}>
            {/* Filter bar 370:66893: inside the table card, above the header row. */}
            <div className="flex flex-wrap items-center gap-2 border-b border-line-default px-4 py-3">
                {searchField}
                {filterControls}
                <ColumnPicker hidden={hidden} onToggle={toggleColumn} />
            </div>
            {status ? (
                <div className="p-4">{status}</div>
            ) : rows.length === 0 ? (
                <EmptyState reason="allClear" title={t('orders.empty')} body={t('orders.emptyBody')} className="rounded-none border-0" />
            ) : (
                <>
                    <div className="overflow-x-auto">
                        <table className={`${dataTable.table} table-fixed`}>
                            <thead className={dataTable.head}>
                                <tr className="border-b border-line-strong">
                                    <th scope="col" className={`${dataTable.th} w-10 px-0 text-center`}>
                                        {canConfirm && <input type="checkbox" checked={allOnPage} onChange={toggleAll} aria-label={t('orders.selectPage')} className="size-5 accent-brand" />}
                                    </th>
                                    <Th className="w-33">{t('orders.col.order')}</Th>
                                    {shows('placed') && <Th className="w-25">{t('orders.col.placed')}</Th>}
                                    <Th className="w-41">{t('orders.col.customer')}</Th>
                                    <Th end className="w-24">
                                        {t('orders.col.total')}
                                    </Th>
                                    {shows('payment') && <Th className="hidden w-28 xl:table-cell">{t('orders.col.payment')}</Th>}
                                    <Th className="w-51">{t('orders.col.status')}</Th>
                                    {shows('risk') && <Th className="w-27">{t('orders.col.risk')}</Th>}
                                    {shows('courier') && <Th className="hidden xl:table-cell">{t('orders.col.courier')}</Th>}
                                    <th scope="col" className={`${dataTable.th} w-12 px-0`}>
                                        <span className="sr-only">{t('orders.col.actions')}</span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {rows.map((row) => (
                                    <OrderRowView key={row.id} row={row} shows={shows} selectable={canConfirm} selected={selected.has(row.id)} onToggle={() => toggle(row.id)} menu={rowMenu(row)} />
                                ))}
                            </tbody>
                        </table>
                    </div>
                    <DataTableFooter summary={t(selected.size > 0 ? 'orders.footer.selected' : 'orders.footer', { count: rows.length, selected: selected.size })}>{pager}</DataTableFooter>
                </>
            )}
        </div>
    );

    return (
        <section className={`flex flex-col gap-4 lg:gap-6 ${desktop ? 'pb-24' : 'pb-36'}`}>
            <ContentHeader
                breadcrumb={[{ label: t('nav.group.sell') }, { label: t('orders.crumb') }]}
                title={t('orders.title')}
                subtitle={orders.data && t('orders.subtitle', { count: subtitleCount, range: rangeText(range) })}
                actions={
                    <>
                        {desktop && rangeSelect}
                        {can(me, 'orders.create') && (
                            <Link to="/orders/new" className={buttonClass('primary')}>
                                <PlusIcon size={16} aria-hidden />
                                {desktop ? t('orders.new') : t('orders.newShort')}
                            </Link>
                        )}
                    </>
                }
            />

            {kpis}

            {desktop ? (
                <div className="flex items-center gap-4 border-b border-line-default">
                    <StatusTabs tab={tab} counts={counts} onPick={pickTab} />
                    {/* Anomalies 370:66889: a warning-tinted pill at the end of the row, the exception the eye should land on. */}
                    <button
                        type="button"
                        aria-pressed={tab === 'anomalies'}
                        onClick={() => pickTab(tab === 'anomalies' ? 'all' : 'anomalies')}
                        className={`mb-1 inline-flex shrink-0 items-center gap-1.5 rounded-xs-plus bg-warning-subtle px-2.5 py-1.5 t-admin-body-strong whitespace-nowrap text-fg-primary transition-shadow duration-150 ease-standard ${
                            tab === 'anomalies' ? 'ring-2 ring-inset ring-feedback-warning' : 'hover:shadow-e1'
                        }`}
                    >
                        <WarningIcon size={14} aria-hidden className="text-feedback-warning" />
                        {t('orders.tab.anomalies')}
                        <span className="t-admin-caption-strong tabular">{counts.anomalies ?? 0}</span>
                    </button>
                </div>
            ) : (
                <ChipTabs tab={tab} counts={counts} onPick={pickTab} />
            )}

            {desktop ? (
                desktopTable
            ) : (
                <>
                    <div className="flex flex-col gap-2">
                        <div className="flex items-center gap-2">
                            {searchField}
                            <Button variant="outline" aria-expanded={filtersOpen} onClick={() => setFiltersOpen((open) => !open)}>
                                <FunnelIcon size={16} aria-hidden />
                                {t('orders.filters')}
                                {activeFilters > 0 && <span className="tabular">{activeFilters}</span>}
                            </Button>
                        </div>
                        {filtersOpen && (
                            <div className="flex flex-wrap items-center gap-2 rounded-md border border-line-default bg-surface p-3">
                                {rangeSelect}
                                {filterControls}
                            </div>
                        )}
                    </div>
                    {status ??
                        (rows.length === 0 ? (
                            <EmptyState reason="allClear" title={t('orders.empty')} body={t('orders.emptyBody')} />
                        ) : (
                            <>
                                {/* 768: OrderCard 228:1891 replaces the table row. */}
                                <ul className="flex flex-col gap-4" aria-label={t('orders.title')}>
                                    {rows.map((row) => (
                                        <OrderCardView key={row.id} row={row} selectable={canConfirm} selected={selected.has(row.id)} onToggle={() => toggle(row.id)} />
                                    ))}
                                </ul>
                                <div className="flex gap-2">
                                    {prev && (
                                        <Button variant="outline" className="flex-1" onClick={() => setParam({ cursor: prev }, true)}>
                                            <ArrowLeftIcon size={16} aria-hidden />
                                            {t('orders.newer')}
                                        </Button>
                                    )}
                                    <Button variant="outline" className="flex-1" disabled={!next} onClick={() => setParam({ cursor: next ?? '' }, true)}>
                                        {t('orders.older')}
                                        <ArrowRightIcon size={16} aria-hidden />
                                    </Button>
                                </div>
                            </>
                        ))}
                </>
            )}

            {(selected.size > 0 || result) && (
                <BulkActionBar
                    docked={!desktop}
                    count={selected.size}
                    highRisk={highRisk}
                    pending={bulk.isPending}
                    packing={pack.isPending}
                    shipping={shipBulk.isPending}
                    delivering={deliverBulk.isPending}
                    printing={printing}
                    canConfirm={canConfirm}
                    canPack={canPack}
                    canFulfil={canFulfil}
                    canPrint={canPrint}
                    canBook={canFulfil}
                    result={result}
                    onConfirm={confirmSelected}
                    onPack={packSelected}
                    onFulfil={fulfilSelected}
                    onPrint={() => print([...selected])}
                    onBook={() => setBooking([...selected])}
                    onClear={() => {
                        setSelected(new Set());
                        setResult(null);
                    }}
                />
            )}
            {canFulfil && <CourierBookingPanel open={booking !== null} orderIds={booking ?? []} onClose={() => setBooking(null)} />}
        </section>
    );
}

/** Below 1024 the board switches to the 768 layout (Figma 229:21429); jsdom has no matchMedia and lays out as 1440. */
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

/**
 * The column picker (AdminIconButton 370:66905 at the end of the filter bar): a checkbox per
 * optional column, remembered per staff member in this browser.
 */
function ColumnPicker({ hidden, onToggle }: { hidden: Set<OptionalColumn>; onToggle: (column: OptionalColumn) => void }) {
    const { t } = useI18n();
    const [open, setOpen] = useState(false);
    const panelId = useId();
    const root = useRef<HTMLDivElement>(null);
    const button = useRef<HTMLButtonElement>(null);

    useEffect(() => {
        if (!open) return;
        const close = (event: MouseEvent | KeyboardEvent) => {
            if (event instanceof KeyboardEvent) {
                if (event.key !== 'Escape') return;
                setOpen(false);
                button.current?.focus();
            } else if (!root.current?.contains(event.target as Node)) setOpen(false);
        };
        document.addEventListener('mousedown', close);
        document.addEventListener('keydown', close);
        return () => {
            document.removeEventListener('mousedown', close);
            document.removeEventListener('keydown', close);
        };
    }, [open]);

    return (
        <div ref={root} className="relative ms-auto">
            <IconButton ref={button} variant="outline" aria-label={t('orders.columns')} aria-expanded={open} aria-controls={open ? panelId : undefined} onClick={() => setOpen((value) => !value)}>
                <ColumnsIcon size={18} aria-hidden />
            </IconButton>
            {open && (
                <fieldset id={panelId} className="absolute end-0 top-full z-30 mt-1 flex min-w-52 flex-col rounded-sm border border-line-default bg-surface-raised p-1 shadow-e3">
                    <legend className="sr-only">{t('orders.columns')}</legend>
                    <p aria-hidden className="px-3 pt-2 pb-1 t-admin-overline text-fg-secondary">
                        {t('orders.columns')}
                    </p>
                    {optionalColumns.map((column) => (
                        <label key={column} className="flex h-9 cursor-pointer items-center gap-3 rounded-xs px-3 t-admin-body whitespace-nowrap text-fg-primary hover:bg-subtle">
                            <input type="checkbox" checked={!hidden.has(column)} onChange={() => onToggle(column)} className="size-4 accent-brand" />
                            {t(`orders.col.${column}` as MessageKey)}
                        </label>
                    ))}
                </fieldset>
            )}
        </div>
    );
}

/**
 * The 1440 status tabs (Figma AdminTabs 370:66878): Figma's nine in one row, never clipped. Tabs
 * that do not fit the row's width — and On hold and Processing, which the frame leaves out —
 * move behind a ⋯ menu at the row's end with their server counts; the open tab always stays in
 * the row. Widths come from an invisible measuring copy, so the split never oscillates.
 */
function StatusTabs({ tab, counts, onPick }: { tab: BoardTab; counts: Partial<Record<string, number>>; onPick: (tab: BoardTab) => void }) {
    const { t } = useI18n();
    const box = useRef<HTMLDivElement>(null);
    const measure = useRef<HTMLDivElement>(null);
    const [fit, setFit] = useState<number | null>(null);
    const candidates = tabs.filter((value) => !quietTabs.has(value) || tab === value);

    useLayoutEffect(() => {
        const update = () => {
            const width = box.current?.clientWidth ?? 0;
            const items = Array.from(measure.current?.children ?? []) as HTMLElement[];
            // jsdom (and a box not laid out yet) measures 0: show every tab.
            if (width === 0 || items.length === 0) {
                setFit(null);
                return;
            }
            const room = width - 40;
            let used = 0;
            let count = 0;
            for (const [index, item] of items.entries()) {
                used += item.offsetWidth + (index > 0 ? 2 : 0);
                if (used > room) break;
                count += 1;
            }
            setFit(count);
        };
        update();
        const observer = typeof ResizeObserver === 'function' ? new ResizeObserver(update) : null;
        if (box.current) observer?.observe(box.current);
        return () => observer?.disconnect();
    }, [counts, candidates.length, t]);

    let shown = fit === null ? candidates : candidates.slice(0, fit);
    if (!shown.includes(tab) && candidates.includes(tab)) shown = [...shown.slice(0, Math.max(0, shown.length - 1)), tab];
    const overflow = [...candidates.filter((value) => !shown.includes(value)), ...[...quietTabs].filter((value) => !candidates.includes(value))];
    const button = (value: BoardTab, measuring = false) => (
        <button
            key={value}
            type="button"
            tabIndex={measuring ? -1 : undefined}
            aria-pressed={measuring ? undefined : tab === value}
            onClick={() => onPick(value)}
            className={tabClass(tab === value)}
        >
            {t(tabLabel(value))}
            <CountBadge count={counts[value] ?? 0} />
        </button>
    );

    return (
        <div ref={box} className="relative flex min-w-0 flex-1 items-center overflow-hidden">
            <div ref={measure} aria-hidden inert className="invisible absolute flex gap-0.5">
                {candidates.map((value) => button(value, true))}
            </div>
            <TabList label={t('orders.tabs')} className="min-w-0 gap-0.5 border-b-0">
                {shown.map((value) => button(value))}
            </TabList>
            {overflow.length > 0 && (
                <ActionMenu
                    label={t('orders.tab.more')}
                    items={overflow.map((value) => ({ key: value, label: t('orders.tab.withCount', { tab: t(tabLabel(value)), count: counts[value] ?? 0 }), onSelect: () => onPick(value) }))}
                />
            )}
        </div>
    );
}

/**
 * 768 status tabs (Figma 229:21477): a scrolling row of chips — the active one brand-bordered,
 * Anomalies warning-tinted — with empty statuses left out and All last.
 */
function ChipTabs({ tab, counts, onPick }: { tab: BoardTab; counts: Partial<Record<string, number>>; onPick: (tab: BoardTab) => void }) {
    const { t } = useI18n();
    const order: BoardTab[] = [...tabs.filter((value) => value !== 'all').flatMap((value) => (value === 'pending' ? [value, 'anomalies' as BoardTab] : [value])), 'all'];
    const shown = order.filter((value) => value === 'all' || value === tab || (counts[value] ?? 0) > 0);
    return (
        <div role="group" aria-label={t('orders.tabs')} className="flex gap-2 overflow-x-auto">
            {shown.map((value) => {
                const active = tab === value;
                const anomalies = value === 'anomalies';
                const look = active ? 'border-2 border-brand bg-brand-subtle' : anomalies ? 'border border-transparent bg-warning-subtle' : 'border border-line-default bg-surface hover:bg-subtle';
                return (
                    <button
                        key={value}
                        type="button"
                        aria-pressed={active}
                        onClick={() => onPick(active && value !== 'all' ? 'all' : value)}
                        className={`inline-flex shrink-0 items-center gap-1 rounded-xs px-2.5 py-1 t-admin-caption-strong whitespace-nowrap text-fg-primary ${look}`}
                    >
                        {anomalies && <WarningIcon size={14} aria-hidden className="text-feedback-warning" />}
                        {t(tabLabel(value))}
                        <span className="tabular">{counts[value] ?? 0}</span>
                    </button>
                );
            })}
        </div>
    );
}

/** The verify-first flag: a warning-tinted chip (no edge), the same in the card and the courier cell. */
function VerifyFirst() {
    const { t } = useI18n();
    return (
        <span className="inline-flex h-5.5 items-center gap-1 rounded-xs bg-warning-subtle px-2 t-admin-caption-strong whitespace-nowrap text-fg-primary">
            <WarningIcon size={14} aria-hidden className="shrink-0 text-feedback-warning" />
            {t('orders.courier.verifyFirst')}
        </span>
    );
}

/**
 * Figma OrderCard 228:1891: order + money, customer, then the workflow chip, our RiskPill and
 * only the exceptions. Selected = bg/brand-subtle.
 */
function OrderCardView({ row, selectable, selected, onToggle }: { row: OrderRow; selectable: boolean; selected: boolean; onToggle: () => void }) {
    const { t } = useI18n();
    const verify = row.verification_status === 'pending';
    return (
        <li className={`flex flex-col gap-2 rounded-md border border-line-default p-4 ${selected ? 'bg-brand-subtle' : 'bg-surface'}`}>
            <div className="flex items-center gap-2.5">
                {selectable && (
                    <label className="-my-3 -ms-1 grid size-11 shrink-0 place-items-center">
                        <input type="checkbox" checked={selected} onChange={onToggle} aria-label={t('orders.select', { number: row.number })} className="size-5 accent-brand" />
                    </label>
                )}
                <Link to={`/orders/${row.id}`} className="min-w-0 flex-1 truncate t-admin-mono text-fg-primary hover:text-fg-link hover:underline">
                    {row.number}
                </Link>
                <p className="t-admin-num tabular whitespace-nowrap text-fg-primary">
                    <Taka amount={row.grand_total} />
                </p>
            </div>
            <p className="truncate t-admin-caption tabular text-fg-secondary">{[row.customer_name, row.customer_phone_masked, row.district].filter(Boolean).join(' · ')}</p>
            <OrderStatusBadge
                form="compact"
                order={{ ...row, paid: row.paid_total, refunded: row.refunded_total }}
                extra={
                    <>
                        <RiskPill level={row.risk_level} score={row.risk_score} />
                        {verify && <VerifyFirst />}
                    </>
                }
            />
        </li>
    );
}

function FilterSelect({ label, value, onChange, all, children }: { label: string; value: string; onChange: (value: string) => void; all: string; children: ReactNode }) {
    return (
        <label className="flex">
            <span className="sr-only">{label}</span>
            <Select className="w-36 [&_select]:h-9" value={value} onChange={(e) => onChange(e.target.value)}>
                <option value="">{all}</option>
                {children}
            </Select>
        </label>
    );
}

function OrderRowView({
    row,
    shows,
    selectable,
    selected,
    onToggle,
    menu,
}: {
    row: OrderRow;
    shows: (column: OptionalColumn) => boolean;
    selectable: boolean;
    selected: boolean;
    onToggle: () => void;
    menu: ActionMenuItem[];
}) {
    const { t, locale } = useI18n();
    const placed = row.placed_at ?? row.created_at;
    const at = placed ? dhakaParts(placed, locale) : null;
    const age = placed ? ageLabel(placed) : null;
    const verify = row.verification_status === 'pending';
    const partlyPaid = !isZeroAmount(row.paid_total) && !isZeroAmount(row.due_total);
    const cell = `${dataTable.td.replace('h-15', 'h-auto')} py-3`;

    return (
        <tr className={selected ? `${dataTable.row.replace('hover:bg-subtle', '')} bg-brand-subtle` : dataTable.row} aria-selected={selectable ? selected : undefined}>
            <td className={`${cell} w-10 px-0 text-center`}>
                {selectable && <input type="checkbox" checked={selected} onChange={onToggle} aria-label={t('orders.select', { number: row.number })} className="size-5 accent-brand" />}
            </td>
            <td className={cell}>
                <Link to={`/orders/${row.id}`} className="block t-admin-mono whitespace-nowrap text-fg-primary hover:text-fg-link hover:underline">
                    {row.number}
                </Link>
                <p className="truncate t-admin-caption tabular text-fg-tertiary">
                    {t(sourceLabel(row.source))} · {t(row.items_count === 1 ? 'orders.row.item' : 'orders.row.items', { count: row.items_count })}
                </p>
            </td>
            {shows('placed') && (
                <td className={cell}>
                    {at && (
                        <>
                            <p className="t-admin-body-strong tabular whitespace-nowrap text-fg-primary">{at.day}</p>
                            <p className="t-admin-caption tabular text-fg-tertiary">{age ? t(age.key, age.vars) : at.time}</p>
                        </>
                    )}
                </td>
            )}
            <td className={`min-w-0 ${cell}`}>
                <p className="truncate t-admin-body-strong text-fg-primary">{row.customer_name}</p>
                <p className="truncate t-admin-caption tabular text-fg-tertiary">{[row.customer_phone_masked, row.district].filter(Boolean).join(' · ')}</p>
            </td>
            <td className={`${cell} text-end`}>
                <p className="t-admin-num tabular whitespace-nowrap text-fg-primary">
                    <Taka amount={row.grand_total} />
                </p>
                {partlyPaid && (
                    <p className="t-admin-caption tabular whitespace-nowrap text-fg-tertiary">
                        {t('orders.duePrefix')}
                        <Taka amount={row.due_total} signClass="t-admin-currency-caption" />
                    </p>
                )}
                {/* Below 1280 the Payment column folds in here, under the amount. */}
                {shows('payment') && (
                    <span className="mt-1 flex justify-end xl:hidden">
                        <PaymentChip method={row.payment_method} />
                    </span>
                )}
            </td>
            {shows('payment') && (
                <td className={`${cell} hidden xl:table-cell`}>
                    <PaymentChip method={row.payment_method} />
                </td>
            )}
            <td className={cell}>
                <OrderStatusBadge order={{ ...row, paid: row.paid_total, refunded: row.refunded_total }} />
            </td>
            {shows('risk') && (
                <td className={cell}>
                    <RiskPill level={row.risk_level} score={row.risk_score} />
                    {verify && <p className="mt-1 t-admin-caption text-fg-tertiary xl:hidden">{t('orders.courier.verifyFirst')}</p>}
                </td>
            )}
            {shows('courier') && (
                <td className={`${cell} hidden xl:table-cell`}>
                    {/* Nothing booked yet: say so, never invent a consignment. */}
                    <p className="truncate t-admin-body-strong text-fg-secondary">{preShip.has(row.status) ? t('orders.courier.notBooked') : t('orders.courier.noData')}</p>
                    {verify && <p className="truncate t-admin-caption text-fg-tertiary" title={t('orders.courier.verifyFirst')}>{t('orders.courier.verifyFirst')}</p>}
                </td>
            )}
            <td className={`${cell} w-12 px-0 text-center`}>
                <ActionMenu label={t('orders.row.menu', { number: row.number })} items={menu} />
            </td>
        </tr>
    );
}

/**
 * Figma BulkActionBar 193:740 (board instance 241:31951): one 64-high row floating 24 above
 * the viewport bottom at 1440/1024 — count, context, then Confirm · Mark packed · Book courier
 * (Phase 6: disabled, with a tooltip) · Print invoices · the ⋯ overflow (Mark shipped /
 * delivered, manual until couriers, P5) · divider · close. Docked to the bottom at 768
 * (Figma 229:21704): summary + Clear, then equal-width actions. Each action shows only with
 * its permission. The result is always two numbers, never "done": the failures stay selected
 * and are listed with the server's reason.
 */
function BulkActionBar({
    docked,
    count,
    highRisk,
    pending,
    packing,
    shipping,
    delivering,
    printing,
    canConfirm,
    canPack,
    canFulfil,
    canPrint,
    canBook,
    result,
    onConfirm,
    onPack,
    onFulfil,
    onPrint,
    onBook,
    onClear,
}: {
    docked: boolean;
    count: number;
    highRisk: number;
    pending: boolean;
    packing: boolean;
    shipping: boolean;
    delivering: boolean;
    printing: boolean;
    canConfirm: boolean;
    canPack: boolean;
    canFulfil: boolean;
    canPrint: boolean;
    canBook: boolean;
    result: { done: number; failed: BulkResult[] } | null;
    onConfirm: () => void;
    onPack: () => void;
    onFulfil: (move: FulfilMove) => void;
    onPrint: () => void;
    onBook: () => void;
    onClear: () => void;
}) {
    const { t } = useI18n();
    const grow = docked ? 'min-w-0 flex-1' : '';

    const confirm = canConfirm && (
        <Button onClick={onConfirm} disabled={count === 0 || pending} className={grow}>
            <CheckIcon size={16} aria-hidden />
            {pending ? t('orders.bulk.confirming', { count }) : t('orders.bulk.confirm', { count })}
        </Button>
    );
    const packButton = canPack && (
        <Button variant="outline" onClick={onPack} disabled={count === 0 || packing} className={grow}>
            <PackageIcon size={16} aria-hidden />
            {packing ? t('orders.bulk.packing', { count }) : t('orders.bulk.pack')}
        </Button>
    );
    const print = canPrint && (
        <Button variant="outline" onClick={onPrint} disabled={count === 0 || printing} aria-busy={printing || undefined} className={grow}>
            <PrinterIcon size={16} aria-hidden />
            {printing ? t('orders.invoice.printing') : docked ? t('orders.bulk.print') : t('orders.bulk.printInvoices')}
        </Button>
    );
    const more = canFulfil && (
        <MoreMenu
            label={t('orders.bulk.more')}
            items={[
                { key: 'ship', icon: <TruckIcon size={18} aria-hidden />, label: shipping ? t('orders.bulk.shipping', { count }) : t('orders.bulk.ship'), disabled: count === 0 || shipping, onSelect: () => onFulfil('ship') },
                { key: 'deliver', icon: <CheckCircleIcon size={18} aria-hidden />, label: delivering ? t('orders.bulk.delivering', { count }) : t('orders.bulk.deliver'), disabled: count === 0 || delivering, onSelect: () => onFulfil('deliver') },
            ]}
        />
    );

    const resultView = result && (
        <div className="flex flex-col gap-1.5 border-t border-line-default pt-3" aria-live="polite">
            <p className="flex flex-wrap items-center gap-x-4 gap-y-1 t-admin-body text-fg-primary">
                <span className="inline-flex items-center gap-1.5">
                    <CheckCircleIcon size={16} aria-hidden className="text-feedback-success" />
                    {t('orders.bulk.done', { count: result.done })}
                </span>
                <span className="inline-flex items-center gap-1.5">
                    <WarningCircleIcon size={16} aria-hidden className="text-feedback-danger" />
                    {t('orders.bulk.failed', { count: result.failed.length })}
                </span>
            </p>
            {result.failed.length > 0 && (
                <ul className="flex flex-col gap-1">
                    {result.failed.map((failure) => (
                        <li key={failure.order_id} className="t-admin-caption text-fg-secondary">
                            <span className="t-admin-mono-sm text-fg-primary">{failure.number ?? `#${failure.order_id}`}</span>
                            {' · '}
                            {failure.detail ?? t(failure.outcome === 'not_found' ? 'orders.bulk.notFound' : 'orders.bulk.conflict')}
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );

    if (docked) {
        return (
            <div role="region" aria-label={t('orders.bulk.label')} className="fixed inset-x-0 bottom-0 z-40 flex flex-col gap-2.5 rounded-t-lg border border-line-strong bg-surface-raised px-4 pt-3 pb-4 shadow-e4">
                <div className="flex items-center gap-2">
                    <p className="min-w-0 flex-1 t-admin-body-strong text-fg-primary" aria-live="polite">
                        {t('orders.bulk.selectedShort', { count })}
                        {highRisk > 0 && ` · ${t('orders.bulk.highRisk', { count: highRisk })}`}
                    </p>
                    <Button variant="ghost" size="sm" onClick={onClear}>
                        {t('orders.bulk.clearShort')}
                    </Button>
                </div>
                <div className="flex items-center gap-2">
                    {confirm}
                    {packButton}
                    {print}
                    {more}
                </div>
                {resultView}
            </div>
        );
    }

    return (
        <div
            role="region"
            aria-label={t('orders.bulk.label')}
            className="fixed bottom-6 start-[calc(50%+7.75rem)] z-40 flex w-[min(70rem,calc(100%-18.5rem))] -translate-x-1/2 flex-col gap-3 rounded-md border border-line-strong bg-surface-raised py-3 ps-4 pe-3 shadow-e4"
        >
            <div className="flex flex-wrap items-center gap-4">
                <div className="flex flex-col">
                    <p className="t-admin-body-strong whitespace-nowrap text-fg-primary" aria-live="polite">
                        {t('orders.bulk.selected', { count })}
                    </p>
                    <button type="button" onClick={onClear} className="self-start t-admin-caption text-fg-link hover:underline">
                        {t('orders.bulk.clear')}
                    </button>
                </div>
                {highRisk > 0 && (
                    <p className="flex items-center gap-1.5 t-admin-caption-strong whitespace-nowrap text-fg-primary">
                        <ShieldWarningIcon size={14} aria-hidden className="text-feedback-danger" />
                        {t('orders.bulk.highRisk', { count: highRisk })}
                    </p>
                )}
                <div className="ms-auto flex flex-wrap items-center justify-end gap-2">
                    {confirm}
                    {packButton}
                    {canBook && (
                        <Button variant="outline" onClick={onBook} disabled={count === 0}>
                            <TruckIcon size={16} aria-hidden />
                            {t('orders.bulk.bookCourier')}
                        </Button>
                    )}
                    {print}
                    {more}
                </div>
                <span aria-hidden className="h-8 w-px bg-line-default" />
                <IconButton aria-label={t('orders.bulk.clear')} onClick={onClear}>
                    <XIcon size={18} aria-hidden />
                </IconButton>
            </div>
            {resultView}
        </div>
    );
}

type MenuItem = { key: string; icon: ReactNode; label: string; disabled: boolean; onSelect: () => void };

/** The bar's ⋯ overflow (AdminIconButton outline + a surface-raised e3 menu, opening upwards). */
function MoreMenu({ label, items }: { label: string; items: MenuItem[] }) {
    const [open, setOpen] = useState(false);
    const menuId = useId();
    const root = useRef<HTMLDivElement>(null);

    useEffect(() => {
        if (!open) return;
        const close = (event: MouseEvent | KeyboardEvent) => {
            if (event instanceof KeyboardEvent ? event.key === 'Escape' : !root.current?.contains(event.target as Node)) setOpen(false);
        };
        document.addEventListener('mousedown', close);
        document.addEventListener('keydown', close);
        return () => {
            document.removeEventListener('mousedown', close);
            document.removeEventListener('keydown', close);
        };
    }, [open]);

    return (
        <div ref={root} className="relative">
            <IconButton variant="outline" aria-label={label} aria-haspopup="menu" aria-expanded={open} aria-controls={open ? menuId : undefined} onClick={() => setOpen((value) => !value)}>
                <DotsThreeIcon size={18} aria-hidden />
            </IconButton>
            {open && (
                <div id={menuId} role="menu" aria-label={label} className="absolute end-0 bottom-full z-50 mb-1 min-w-52 rounded-sm border border-line-default bg-surface-raised p-1 shadow-e3">
                    {items.map((item, index) => (
                        <button
                            key={item.key}
                            type="button"
                            role="menuitem"
                            autoFocus={index === 0}
                            disabled={item.disabled}
                            onClick={() => {
                                setOpen(false);
                                item.onSelect();
                            }}
                            className="flex h-9 w-full items-center gap-2 rounded-xs px-3 t-admin-body whitespace-nowrap text-fg-primary hover:bg-subtle disabled:text-fg-disabled"
                        >
                            {item.icon}
                            {item.label}
                        </button>
                    ))}
                </div>
            )}
        </div>
    );
}

function Th({ children, end, className = '' }: { children?: ReactNode; end?: boolean; className?: string }) {
    return (
        <th scope="col" className={`${end ? dataTable.th.replace('text-start', 'text-end') : dataTable.th} ${className}`}>
            {children}
        </th>
    );
}
