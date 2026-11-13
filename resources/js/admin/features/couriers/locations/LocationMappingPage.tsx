import { useMemo, useState } from 'react';
import { useSearchParams } from 'react-router';
import { ArrowsClockwiseIcon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import { ApiError } from '@/lib/api';
import { can, useMe } from '@/features/auth/api';
import { Button } from '@/components/ui/Button';
import { ContentHeader } from '@/components/shell/ContentHeader';
import { DataTableFooter, dataTable } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { QueryError } from '@/components/ui/QueryError';
import { SearchField } from '@/components/ui/SearchField';
import { SegmentedControl } from '@/components/ui/SegmentedControl';
import { Select } from '@/components/ui/Select';
import { StatusChip } from '@/components/ui/StatusChip';
import { TabButton } from '@/components/ui/Tabs';
import { useToast } from '@/components/ui/Toast';
import {
    useCourierLocations,
    useLocationOverview,
    useNeedsMapping,
    useSyncLocations,
    useUnmapLocation,
    type CourierLocation,
    type LocationFilters,
    type LocationStatusFilter,
} from './api';
import { formatDhakaTime, formatUnits, placeLabel, zoneLabel } from './format';
import { MapLocationDialog, type MapTarget } from './MapLocationDialog';

/**
 * Shipments → Location mapping (P6; admin checklist §2.3 "location mapping table", DATABASE-DESIGN
 * §3.6). Not drawn in Figma: the Theme B Shipments frame (B · 08, 241:34682) only links here
 * ("1 order blocked by an unmapped area — map it"). Built from the same parts as the Suppliers
 * list — ContentHeader 187:530, AdminTab 185:125, SearchField 185:28, AdminSelect 184:369,
 * SegmentedControl 185:110, DataTable 192:532, StatusChip 185:79.
 *
 * "Needs mapping" puts first the places real orders are waiting on. Every mapping is a human
 * choice; suggestions are labelled and need a confirm (rule 32).
 */
export function LocationMappingPage() {
    const { t, locale } = useI18n();
    const { notify } = useToast();
    const { data: me } = useMe();
    const [params, setParams] = useSearchParams();
    const provider = params.get('provider') ?? 'pathao';
    const tab = params.get('tab') === 'zones' ? 'zones' : 'needs';
    const canMap = can(me, 'shipments.manage_locations');

    const overview = useLocationOverview(provider);
    const needs = useNeedsMapping(provider);
    const sync = useSyncLocations(provider);
    const unmap = useUnmapLocation(provider);
    const [target, setTarget] = useState<MapTarget | null>(null);

    const [city, setCity] = useState('');
    const [status, setStatus] = useState<LocationStatusFilter>('');
    const [search, setSearch] = useState('');
    const filters: LocationFilters = { city, status, search: search.trim() };
    const list = useCourierLocations(provider, filters, tab === 'zones');
    const rows = useMemo(() => list.data?.pages.flatMap((p) => p.data) ?? [], [list.data]);

    const courierName = overview.data ? (locale === 'bn' && overview.data.provider.name_bn ? overview.data.provider.name_bn : overview.data.provider.name) : '';
    const cell = `${dataTable.td} max-lg:h-auto max-lg:p-0`;
    const needsRows = needs.data?.data ?? [];

    function setTab(next: 'needs' | 'zones') {
        const nextParams = new URLSearchParams(params);
        if (next === 'zones') nextParams.set('tab', 'zones');
        else nextParams.delete('tab');
        setParams(nextParams, { replace: true });
    }

    function runSync() {
        sync.mutate(undefined, {
            onSuccess: () => notify(t('locations.syncQueued'), 'info'),
            onError: (error) => notify(error instanceof ApiError ? error.problem.title : t('error.title'), 'danger'),
        });
    }

    function remove(row: CourierLocation) {
        unmap.mutate(row.id, {
            onSuccess: () => notify(t('locations.unmapped')),
            onError: (error) => notify(error instanceof ApiError ? error.problem.title : t('error.title'), 'danger'),
        });
    }

    if (overview.isError) {
        return <QueryError error={overview.error} onRetry={() => void overview.refetch()} />;
    }

    return (
        <section className="flex flex-col gap-6">
            <ContentHeader
                breadcrumb={[{ label: t('locations.crumb.deliver') }, { label: t('locations.crumb.shipments'), to: '/shipments' }, { label: t('locations.title') }]}
                title={t('locations.title')}
                subtitle={t('locations.subtitle', { courier: courierName })}
                actions={
                    canMap && (
                        <Button variant="outline" onClick={runSync} disabled={sync.isPending || !overview.data}>
                            <ArrowsClockwiseIcon size={16} aria-hidden />
                            {sync.isPending ? t('locations.syncing') : t('locations.sync', { courier: courierName })}
                        </Button>
                    )
                }
            />

            {overview.data && (
                <dl className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                    <Stat label={t('locations.stat.zones')} value={formatUnits(overview.data.zones)} />
                    <Stat label={t('locations.stat.areas')} value={formatUnits(overview.data.areas)} />
                    <Stat
                        label={t('locations.stat.mapped')}
                        value={formatUnits(overview.data.mapped)}
                        note={overview.data.mapped_inactive > 0 ? `${t('locations.stat.retired')}: ${formatUnits(overview.data.mapped_inactive)}` : undefined}
                    />
                    <Stat
                        label={t('locations.stat.lastSync')}
                        value={overview.data.last_sync ? formatDhakaTime(overview.data.last_sync.finished_at, locale) : t('locations.stat.never')}
                    />
                </dl>
            )}

            <div className="flex flex-wrap items-end justify-between gap-3 border-b border-line-default">
                <div role="group" aria-label={t('locations.title')} className="flex">
                    <TabButton active={tab === 'needs'} label={t('locations.tab.needs')} count={needs.data ? needsRows.length : undefined} onClick={() => setTab('needs')} />
                    <TabButton active={tab === 'zones'} label={t('locations.tab.zones')} count={overview.data?.zones} onClick={() => setTab('zones')} />
                </div>
            </div>

            {tab === 'needs' ? (
                needs.isError ? (
                    <QueryError error={needs.error} onRetry={() => void needs.refetch()} />
                ) : needs.isPending ? (
                    <p className="t-admin-body text-fg-tertiary" aria-busy="true">
                        {t('common.loading')}
                    </p>
                ) : needsRows.length === 0 ? (
                    <EmptyState reason="allClear" title={t('locations.needs.empty')} body={t('locations.needs.emptyBody')} />
                ) : (
                    <div className="flex flex-col gap-3">
                        <p className="t-admin-body text-fg-secondary">{t('locations.needs.hint', { courier: courierName })}</p>
                        <div className={dataTable.card}>
                            <table className={dataTable.table}>
                                <thead className={`hidden lg:table-header-group ${dataTable.head}`}>
                                    <tr className="border-b border-line-strong">
                                        <th className={dataTable.th}>{t('locations.col.place')}</th>
                                        <th className={`${dataTable.th} text-end`}>{t('locations.col.waiting')}</th>
                                        <th className={dataTable.th}>
                                            <span className="sr-only">{t('locations.map')}</span>
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {needsRows.map((place) => (
                                        <tr
                                            key={`${place.district_id}:${place.upazila_id ?? ''}`}
                                            className={`flex flex-wrap items-center gap-x-4 gap-y-1 px-4 py-3 first:border-t-0 lg:table-row lg:p-0 lg:first:border-t ${dataTable.row}`}
                                        >
                                            <td className={`${cell} t-admin-body-strong basis-full lg:basis-auto`}>{placeLabel(place, locale, t)}</td>
                                            <td className={`${cell} t-admin-num tabular lg:text-end`}>
                                                <span className="lg:hidden t-admin-caption text-fg-tertiary">{t('locations.col.waiting')}: </span>
                                                {formatUnits(place.waiting_orders)}
                                            </td>
                                            <td className={`${cell} lg:text-end`}>
                                                {canMap && (
                                                    <Button variant="secondary" size="sm" onClick={() => setTarget({ kind: 'place', place })}>
                                                        {t('locations.map')}
                                                    </Button>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>
                )
            ) : (
                <div className="flex flex-col gap-3">
                    <div className="flex flex-wrap items-center gap-3">
                        <Select
                            aria-label={t('locations.filter.city')}
                            value={city}
                            onChange={(e) => setCity(e.target.value)}
                            className="w-full sm:w-56"
                        >
                            <option value="">{t('locations.filter.allCities')}</option>
                            {(overview.data?.cities ?? []).map((c) => (
                                <option key={c.id} value={c.id}>
                                    {c.name} ({formatUnits(c.mapped)}/{formatUnits(c.zones)})
                                </option>
                            ))}
                        </Select>
                        <SegmentedControl
                            label={t('locations.filter.status')}
                            value={status}
                            onChange={setStatus}
                            options={[
                                { value: '', label: t('locations.filter.all') },
                                { value: 'unmapped', label: t('locations.filter.unmapped') },
                                { value: 'mapped', label: t('locations.filter.mapped') },
                                { value: 'inactive', label: t('locations.filter.retired') },
                            ]}
                        />
                        <div className="w-full sm:ms-auto sm:w-72">
                            <SearchField label={t('locations.search')} placeholder={t('locations.search')} value={search} onChange={(e) => setSearch(e.target.value)} />
                        </div>
                    </div>

                    {list.isError ? (
                        <QueryError error={list.error} onRetry={() => void list.refetch()} />
                    ) : list.isPending ? (
                        <p className="t-admin-body text-fg-tertiary" aria-busy="true">
                            {t('common.loading')}
                        </p>
                    ) : rows.length === 0 ? (
                        <EmptyState reason="noDataYet" title={t('locations.zones.empty')} body={t('locations.zones.emptyBody')} />
                    ) : (
                        <div className={dataTable.card}>
                            <table className={dataTable.table}>
                                <thead className={`hidden lg:table-header-group ${dataTable.head}`}>
                                    <tr className="border-b border-line-strong">
                                        <th className={dataTable.th}>{t('locations.col.city')}</th>
                                        <th className={dataTable.th}>{t('locations.col.zone')}</th>
                                        <th className={dataTable.th}>{t('locations.col.mappedTo')}</th>
                                        <th className={dataTable.th}>{t('locations.col.status')}</th>
                                        <th className={dataTable.th}>
                                            <span className="sr-only">{t('locations.map')}</span>
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {rows.map((row) => (
                                        <tr
                                            key={row.id}
                                            className={`flex flex-wrap items-center gap-x-4 gap-y-1 px-4 py-3 first:border-t-0 lg:table-row lg:p-0 lg:first:border-t ${dataTable.row}`}
                                        >
                                            <td className={`${cell} t-admin-body text-fg-secondary`}>{row.city_name}</td>
                                            <td className={`${cell} basis-full lg:basis-auto`}>
                                                <span className="t-admin-body-strong text-fg-primary">{zoneLabel(row)}</span>
                                                <span className="block t-admin-caption text-fg-tertiary">{t(row.level === 'zone' ? 'locations.zone' : 'locations.area')}</span>
                                            </td>
                                            <td className={`${cell} t-admin-body`}>
                                                {row.district
                                                    ? placeLabel(
                                                          {
                                                              district: row.district.name,
                                                              district_bn: row.district.name_bn,
                                                              upazila: row.upazila?.name ?? null,
                                                              upazila_bn: row.upazila?.name_bn ?? null,
                                                          },
                                                          locale,
                                                          t,
                                                      )
                                                    : '—'}
                                            </td>
                                            <td className={cell}>
                                                <StatusChip
                                                    label={t(!row.is_active ? 'locations.status.retired' : row.is_mapped ? 'locations.status.mapped' : 'locations.status.unmapped')}
                                                    tone={!row.is_active ? (row.is_mapped ? 'anomaly' : 'neutral') : row.is_mapped ? 'success' : 'warning'}
                                                />
                                            </td>
                                            <td className={`${cell} lg:text-end`}>
                                                {canMap && (
                                                    <span className="inline-flex gap-2">
                                                        {row.is_active && (
                                                            <Button variant={row.is_mapped ? 'outline' : 'secondary'} size="sm" onClick={() => setTarget({ kind: 'location', location: row })}>
                                                                {row.is_mapped ? t('locations.change') : t('locations.map')}
                                                            </Button>
                                                        )}
                                                        {row.is_mapped && (
                                                            <Button variant="ghost" size="sm" onClick={() => remove(row)} disabled={unmap.isPending}>
                                                                {t('locations.unmap')}
                                                            </Button>
                                                        )}
                                                    </span>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                            <DataTableFooter summary={t('locations.count', { count: formatUnits(rows.length) })}>
                                {list.hasNextPage && (
                                    <Button variant="outline" size="sm" onClick={() => void list.fetchNextPage()} disabled={list.isFetchingNextPage}>
                                        {list.isFetchingNextPage ? t('common.loading') : t('common.loadMore')}
                                    </Button>
                                )}
                            </DataTableFooter>
                        </div>
                    )}
                </div>
            )}

            <MapLocationDialog provider={provider} courierName={courierName} target={target} onClose={() => setTarget(null)} />
        </section>
    );
}

/** A small stat tile (StatCard 189:360's label/value pair, without the chart). */
function Stat({ label, value, note }: { label: string; value: string; note?: string }) {
    return (
        <div className="flex flex-col gap-1 rounded-md border border-line-default bg-surface px-4 py-3 shadow-e1">
            <dt className="t-admin-overline text-fg-secondary">{label}</dt>
            <dd className="t-admin-num-lg tabular text-fg-primary">{value}</dd>
            {note && <dd className="t-admin-caption text-fg-tertiary">{note}</dd>}
        </div>
    );
}
