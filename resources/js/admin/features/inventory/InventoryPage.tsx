import { useMemo, useState, type ReactNode } from 'react';
import { Link, useSearchParams } from 'react-router';
import { ArrowsLeftRightIcon, ClockCountdownIcon, ListChecksIcon, SlidersHorizontalIcon, WarningCircleIcon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { can, useMe } from '@/features/auth/api';
import { Button, buttonClass } from '@/components/ui/Button';
import { ContentHeader } from '@/components/shell/ContentHeader';
import { DataTableFooter, dataTable } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { QueryError } from '@/components/ui/QueryError';
import { TabButton } from '@/components/ui/Tabs';
import { stockStates, useAdjustments, useAlerts, useInventorySummary, useStock, type AdjustmentReason, type StockRow, type StockState } from './api';
import { AdjustmentDialog } from './AdjustmentDialog';
import { TransferDialog } from './TransferDialog';
import { AvailableCell, LocationCards, StatCard, TAKA, Taka, VariantCell, formatMoney, formatUnits } from './parts';

const tabs: { value: StockState | ''; label: MessageKey; count: 'all' | StockState }[] = [
    { value: '', label: 'inventory.tab.all', count: 'all' },
    { value: 'low', label: 'inventory.tab.low', count: 'low' },
    { value: 'out', label: 'inventory.tab.out', count: 'out' },
    { value: 'overstock', label: 'inventory.tab.overstock', count: 'overstock' },
    { value: 'idle', label: 'inventory.tab.idle', count: 'idle' },
];

type Dialog = { reason: AdjustmentReason } | null;
type Transfer = { fromId: number; toId: number | null; variantId: number | null; quantity: number | null } | null;

/**
 * Inventory overview (Figma "B · 14 Inventory" 241:39494): ContentHeader with Transfer stock ·
 * Stock count · New adjustment, four StatCards 189:360, one location card per stock location,
 * state AdminTabs 185:125 with server counts, and the DataTable 192:532 of variants with
 * contextual row actions. Every figure is the server's; the location and tab live in the URL
 * (`?location=2&state=low`). Selecting a location scopes the stat cards, tabs and table.
 *
 * Transfer stock (header) and a short row's Transfer N open TransferDialog (P5-INV; the form
 * itself is not drawn); Create PO opens the purchase order form.
 * Each healthy row's Ledger opens the movements ledger for that variant. When adjustments wait
 * for approval or the nightly reconciliation found drift, a notice strip (the ledger's
 * reconciliation-strip pattern, bg/subtle) links to the queue and the alerts; it is absent
 * in the drawn state, where neither exists.
 */
export function InventoryPage() {
    const { t, locale } = useI18n();
    const { data: me } = useMe();
    const [params, setParams] = useSearchParams();
    const location = /^\d+$/.test(params.get('location') ?? '') ? params.get('location')! : '';
    const stateParam = params.get('state');
    const state: StockState | '' = stockStates.includes(stateParam as StockState) ? (stateParam as StockState) : '';
    const summary = useInventorySummary(location);
    const stock = useStock({ location, state, search: '' });
    const rows = useMemo(() => stock.data?.pages.flatMap((page) => page.data) ?? [], [stock.data]);
    const [dialog, setDialog] = useState<Dialog>(null);
    const [transfer, setTransfer] = useState<Transfer>(null);

    const canAdjust = can(me, 'inventory.adjust');
    const canTransfer = can(me, 'inventory.transfer');
    const canView = can(me, 'inventory.view');
    const pending = useAdjustments({ status: 'pending' }, canView).data?.pages[0]?.meta.counts.pending ?? 0;
    const alertCounts = useAlerts({ kind: '' }, canView).data?.pages[0]?.meta.counts;
    const drift = (alertCounts?.ledger_drift ?? 0) + (alertCounts?.reserved_drift ?? 0);
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

    const actions = (
        <>
            {multi && canTransfer && data && (
                <Button variant="outline" onClick={() => setTransfer({ fromId: data.location_id, toId: null, variantId: null, quantity: null })}>
                    <ArrowsLeftRightIcon size={16} aria-hidden />
                    {t('inventory.transferStock')}
                </Button>
            )}
            {canAdjust && (
                <>
                    <Button variant="outline" onClick={() => setDialog({ reason: 'recount' })}>
                        <ListChecksIcon size={16} aria-hidden />
                        {t('inventory.stockCount')}
                    </Button>
                    <Button onClick={() => setDialog({ reason: 'damage' })}>
                        <SlidersHorizontalIcon size={16} aria-hidden />
                        {t('inventory.newAdjustment')}
                    </Button>
                </>
            )}
        </>
    );

    const filtered = Boolean(state);
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

            {/* B · 14 "tabs" 241:39551: the tab row alone on its baseline (no search field in the frame). */}
            <div role="group" aria-label={t('inventory.tabs')} className="scrollbar-none flex overflow-x-auto border-b border-line-default">
                {tabs.map((tab) => (
                    <TabButton key={tab.count} active={state === tab.value} label={t(tab.label)} count={data?.counts[tab.count]} onClick={() => setParam('state', tab.value)} />
                ))}
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
                                        <td className={`${cell} t-admin-body-strong whitespace-nowrap text-fg-secondary`}>
                                            <Label text={t('inventory.col.elsewhere')} />
                                            {row.elsewhere.map((e) => `${e.code.toUpperCase()} ${formatUnits(e.available)}`).join(' · ') || '—'}
                                        </td>
                                    )}
                                    {viewCost && (
                                        <td className={`${num} whitespace-nowrap`}>
                                            <Label text={t('inventory.col.avgCost')} />
                                            {row.unit_cost !== undefined && <Taka amount={row.unit_cost} />}
                                        </td>
                                    )}
                                    <td className={`${cell} max-lg:basis-full`}>
                                        <RowActions
                                            row={row}
                                            location={location}
                                            onTransfer={
                                                canTransfer && data
                                                    ? (from) => setTransfer({ fromId: from.location_id, toId: data.location_id, variantId: row.variant_id, quantity: from.available })
                                                    : undefined
                                            }
                                        />
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                    {/* The Figma table ends at its last row; the footer appears only when there is more to load. */}
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
                    open={dialog !== null}
                    onClose={() => setDialog(null)}
                    row={null}
                    initialReason={dialog?.reason ?? 'damage'}
                    locations={locations}
                    locationId={data.location_id}
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
                />
            )}
        </section>
    );
}

/**
 * Contextual row actions (B · 14 rows 241:39599 / 241:39681): a short row (low or out) offers
 * Create PO and, when another branch has units
 * available, Transfer N — which opens the transfer dialog from that branch to this one (needs
 * inventory.transfer); every other row offers its Ledger.
 */
function RowActions({ row, location, onTransfer }: { row: StockRow; location: string; onTransfer?: (from: NonNullable<StockRow['transfer_from']>) => void }) {
    const { t } = useI18n();
    const { data: me } = useMe();
    // Create PO opens the purchase order form with this SKU searched (purchasing, P5).
    const canCreatePo = can(me, 'purchasing.create');
    if (row.state === 'low' || row.state === 'out') {
        const from = row.transfer_from;
        return (
            <div className="flex items-center gap-2.5">
                {canCreatePo && (
                    <Link to={`/purchasing/new?sku=${encodeURIComponent(row.sku)}`} className={buttonClass('outline', 'sm')}>
                        {t('inventory.createPo')}
                    </Link>
                )}
                {from && onTransfer && (
                    <Button variant="ghost" size="sm" onClick={() => onTransfer(from)}>
                        {t('inventory.transferN', { count: formatUnits(from.available) })}
                    </Button>
                )}
            </div>
        );
    }
    const ledger = `/inventory/movements?variant=${row.variant_id}&sku=${encodeURIComponent(row.sku)}${location ? `&location=${location}` : ''}`;
    return (
        <Link to={ledger} className={buttonClass('ghost', 'sm')}>
            {t('inventory.ledger')}
        </Link>
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
