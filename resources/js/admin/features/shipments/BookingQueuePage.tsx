import { useMemo, useState } from 'react';
import { useSearchParams } from 'react-router';
import { TruckIcon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { can, useMe } from '@/features/auth/api';
import { CourierBookingPanel } from '@/features/couriers';
import { ContentHeader } from '@/components/shell/ContentHeader';
import { Button } from '@/components/ui/Button';
import { dataTable, DataTableFooter } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { QueryError } from '@/components/ui/QueryError';
import { TabButton, TabList } from '@/components/ui/Tabs';
import { bookingTabs, useBookingSummary, useQueueShipments, useReadyOrders, useUnmappedAreas, type BookingTab, type ShipmentTab } from './bookingApi';
import { BulkBookDialog, type BulkGroup } from './bookingBulkDialog';
import { BulkNotice, CourierCard, useSubtitle } from './bookingParts';
import { ReadyTable, chosenCourier, rowBlock } from './bookingReadyTable';
import { ShipmentsTable, UnmappedTable } from './bookingTabs';

/**
 * Deliver → Shipments → Booking (Figma B · 07 241:34046, docs/api/booking-queue.md). A queue of
 * packed orders to send to a courier, plus the shipments waiting on one. Every count, weight,
 * COD figure and "can this courier take it" answer comes from the server (rule 1). One order
 * books through the existing booking drawer (CourierBookingPanel, the Figma drawer); a selection
 * submits through `POST /shipments/bulk`, one call per courier with an Idempotency-Key, and
 * moves to "Submitted — awaiting ID" because bulk returns no consignment ids (rule 24d).
 */
export function BookingQueuePage() {
    const { t } = useI18n();
    const { data: me } = useMe();
    const canView = can(me, 'shipments.view');
    const canBook = can(me, 'orders.update') && can(me, 'shipments.book');
    const [params, setParams] = useSearchParams();
    const raw = params.get('tab');
    const tab: BookingTab = (bookingTabs as readonly string[]).includes(raw ?? '') ? (raw as BookingTab) : 'ready';

    const summary = useBookingSummary(canView);
    const subtitle = useSubtitle(summary.data);

    if (me && !canView) return <EmptyState reason="noPermission" title={t('error.forbidden')} />;

    const select = (next: BookingTab) => setParams(next === 'ready' ? {} : { tab: next }, { replace: true });

    return (
        <section className="flex flex-col gap-6">
            <ContentHeader
                breadcrumb={[{ label: t('nav.group.deliver') }, { label: t('nav.shipments'), to: '/shipments' }, { label: t('bookingQueue.crumb') }]}
                title={t('bookingQueue.title')}
                subtitle={subtitle}
            />
            {summary.isError && <QueryError error={summary.error} onRetry={() => void summary.refetch()} />}
            <TabList label={t('bookingQueue.tabs')}>
                {bookingTabs.map((key) => (
                    <TabButton key={key} active={tab === key} label={t(`bookingQueue.tab.${key}` as MessageKey)} count={summary.data?.counts[key]} onClick={() => select(key)} />
                ))}
            </TabList>
            {tab === 'ready' && <ReadyPanel canBook={canBook} />}
            {(tab === 'submitted' || tab === 'booked_today' || tab === 'failed') && <ShipmentsPanel tab={tab} key={tab} />}
            {tab === 'unmapped' && <UnmappedPanel />}
        </section>
    );
}

function ReadyPanel({ canBook }: { canBook: boolean }) {
    const { t } = useI18n();
    const summary = useBookingSummary();
    const ready = useReadyOrders();
    const [picks, setPicks] = useState<Record<number, number>>({});
    const [selected, setSelected] = useState<Set<number>>(new Set());
    const [single, setSingle] = useState<number | null>(null);
    const [bulkOpen, setBulkOpen] = useState(false);
    const rows = useMemo(() => ready.data?.pages.flatMap((p) => p.data) ?? [], [ready.data]);
    const couriers = summary.data?.couriers ?? [];

    // Only rows still present and still bookable with their chosen courier count as selected.
    const live = rows.filter((r) => selected.has(r.order_id) && rowBlock(r, chosenCourier(r, picks)) === null);
    const groups: BulkGroup[] = couriers
        .map((courier) => ({
            courier,
            orderIds: live.filter((r) => chosenCourier(r, picks) === courier.id).map((r) => r.order_id),
        }))
        .filter((g) => g.orderIds.length > 0);

    const toggle = (id: number) =>
        setSelected((prev) => {
            const next = new Set(prev);
            if (next.has(id)) next.delete(id);
            else next.add(id);
            return next;
        });
    const toggleAll = (ids: number[], on: boolean) =>
        setSelected((prev) => {
            const next = new Set(prev);
            for (const id of ids) {
                if (on) next.add(id);
                else next.delete(id);
            }
            return next;
        });
    const pick = (orderId: number, providerId: number) => {
        setPicks((prev) => ({ ...prev, [orderId]: providerId }));
        const row = rows.find((r) => r.order_id === orderId);
        if (row && rowBlock(row, providerId) !== null) toggleAll([orderId], false);
    };

    return (
        <>
            {couriers.length > 0 && (
                <ul className="grid gap-4 md:grid-cols-2" aria-label={t('bookingQueue.couriers')}>
                    {couriers.map((c) => (
                        <CourierCard key={c.id} courier={c} />
                    ))}
                </ul>
            )}
            {canBook && live.length > 0 && (
                <div className="flex flex-wrap items-center gap-3 rounded-md border border-line-default bg-surface px-4 py-3 shadow-e1" aria-live="polite">
                    <span className="t-admin-body-strong text-fg-primary">{t('bookingQueue.selected', { count: live.length })}</span>
                    <Button variant="ghost" size="sm" onClick={() => setSelected(new Set())}>
                        {t('bookingQueue.clear')}
                    </Button>
                    <span className="flex-1" />
                    <Button onClick={() => setBulkOpen(true)}>
                        <TruckIcon size={16} aria-hidden />
                        {t('bookingQueue.bulk.button', { count: live.length })}
                    </Button>
                </div>
            )}
            {ready.isError && <QueryError error={ready.error} onRetry={() => void ready.refetch()} />}
            {ready.isPending && (
                <p className="t-admin-body text-fg-tertiary" aria-busy="true">
                    {t('common.loading')}
                </p>
            )}
            {ready.isSuccess && rows.length === 0 && <EmptyState reason="allClear" title={t('bookingQueue.empty.ready')} body={t('bookingQueue.empty.readyBody')} />}
            {ready.isSuccess && rows.length > 0 && (
                <div className={dataTable.card}>
                    <ReadyTable
                        rows={rows}
                        couriers={couriers}
                        picks={picks}
                        selected={selected}
                        canBook={canBook}
                        onPick={pick}
                        onToggle={toggle}
                        onToggleAll={toggleAll}
                        onBookOne={setSingle}
                    />
                    <DataTableFooter
                        summary={t('bookingQueue.showing', {
                            count: rows.length,
                        })}
                    >
                        {ready.hasNextPage && (
                            <Button variant="outline" size="sm" disabled={ready.isFetchingNextPage} onClick={() => void ready.fetchNextPage()}>
                                {t('bookingQueue.loadMore')}
                            </Button>
                        )}
                    </DataTableFooter>
                </div>
            )}
            <BulkNotice />
            <CourierBookingPanel open={single !== null} orderIds={single === null ? [] : [single]} onClose={() => setSingle(null)} />
            <BulkBookDialog
                open={bulkOpen}
                groups={groups}
                onClose={() => setBulkOpen(false)}
                onDone={() => {
                    setBulkOpen(false);
                    setSelected(new Set());
                }}
            />
        </>
    );
}

function ShipmentsPanel({ tab }: { tab: ShipmentTab }) {
    const { t } = useI18n();
    const list = useQueueShipments(tab);
    const rows = list.data?.pages.flatMap((p) => p.data) ?? [];
    if (list.isError) return <QueryError error={list.error} onRetry={() => void list.refetch()} />;
    if (list.isPending)
        return (
            <p className="t-admin-body text-fg-tertiary" aria-busy="true">
                {t('common.loading')}
            </p>
        );
    if (rows.length === 0) return <EmptyState reason="allClear" title={t(`bookingQueue.empty.${tab}` as MessageKey)} body={t(`bookingQueue.empty.${tab}Body` as MessageKey)} />;
    return (
        <>
            <div className={dataTable.card}>
                <ShipmentsTable tab={tab} rows={rows} />
                <DataTableFooter summary={t('bookingQueue.showing', { count: rows.length })}>
                    {list.hasNextPage && (
                        <Button variant="outline" size="sm" disabled={list.isFetchingNextPage} onClick={() => void list.fetchNextPage()}>
                            {t('bookingQueue.loadMore')}
                        </Button>
                    )}
                </DataTableFooter>
            </div>
            {tab === 'submitted' && <BulkNotice />}
        </>
    );
}

function UnmappedPanel() {
    const { t } = useI18n();
    const areas = useUnmappedAreas();
    if (areas.isError) return <QueryError error={areas.error} onRetry={() => void areas.refetch()} />;
    if (areas.isPending)
        return (
            <p className="t-admin-body text-fg-tertiary" aria-busy="true">
                {t('common.loading')}
            </p>
        );
    if (areas.data.length === 0) return <EmptyState reason="allClear" title={t('bookingQueue.empty.unmapped')} body={t('bookingQueue.empty.unmappedBody')} />;
    return (
        <>
            <p className="t-admin-body text-fg-secondary">{t('bookingQueue.unmapped.lead')}</p>
            <div className={dataTable.card}>
                <UnmappedTable rows={areas.data} />
            </div>
        </>
    );
}
