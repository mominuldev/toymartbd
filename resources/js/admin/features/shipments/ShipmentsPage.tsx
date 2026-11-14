import { useEffect, useId, useState, type ReactNode } from 'react';
import { useSearchParams } from 'react-router';
import { ArrowLeftIcon, ArrowRightIcon, WarningIcon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { ContentHeader } from '@/components/shell/ContentHeader';
import { Button } from '@/components/ui/Button';
import { CountBadge } from '@/components/ui/CountBadge';
import { DataTableFooter, dataTable } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { QueryError } from '@/components/ui/QueryError';
import { SearchField } from '@/components/ui/SearchField';
import { Select } from '@/components/ui/Select';
import { TabList, tabClass } from '@/components/ui/Tabs';
import { boardTabs, useBoard, useBoardStats, type BoardFilters, type BoardRow, type BoardStats, type BoardTab } from './boardApi';
import { BoardDetail } from './BoardDetail';
import { BoardIssues } from './BoardIssues';
import { BoardStatusChip, SourceChip, Taka, dhaka, dhakaToday, hoursMinutes, presetRange, rangePresets, shortDay, statusKey, type RangePreset } from './boardParts';

/**
 * Shipments & tracking (Figma B · 08 241:34682, Theme B): ContentHeader with the date range,
 * four stat tiles, status tabs with server counts, the shipments table, then the selected
 * shipment's tracking card beside the Delivery issues panel. Every figure is the server's
 * (rule 1); the tab, courier, range, search, cursor and selection live in the URL.
 *
 * Figma's "Sync now" is left out: no endpoint re-queries courier statuses on demand (the
 * reconciliation poll runs on the scheduler) — reported to the lead.
 */
export function ShipmentsPage() {
    const { t, locale } = useI18n();
    const ids = useId();
    const [params, setParams] = useSearchParams();
    const tab = (boardTabs.includes(params.get('tab') as BoardTab) ? params.get('tab') : 'all') as BoardTab;
    const preset = (rangePresets.includes(params.get('range') as RangePreset) ? params.get('range') : '7d') as RangePreset;
    const today = dhakaToday();
    const { from, to } = presetRange(preset, today);
    const provider = /^[a-z0-9_-]{1,32}$/.test(params.get('courier') ?? '') ? params.get('courier')! : '';
    const query = params.get('q') ?? '';
    const [search, setSearch] = useState(query);
    const filters: BoardFilters = {
        tab,
        provider,
        from,
        to,
        search: query,
        cursor: params.get('cursor'),
    };
    const board = useBoard(filters);
    const stats = useBoardStats(from, to, provider);
    const rows = board.data?.data ?? [];
    const selected = Number(params.get('shipment')) || rows[0]?.id || null;

    const set = (changes: Record<string, string>) =>
        setParams(
            (current) => {
                const next = new URLSearchParams(current);
                for (const [key, value] of Object.entries(changes)) {
                    if (value) next.set(key, value);
                    else next.delete(key);
                }
                return next;
            },
            { replace: true }
        );

    useEffect(() => {
        const id = window.setTimeout(() => {
            if (search.trim() !== query) set({ q: search.trim(), cursor: '', shipment: '' });
        }, 250);
        return () => window.clearTimeout(id);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    const name = (n: string, bn: string | null | undefined) => (locale === 'bn' && bn ? bn : n);
    const counts = board.data?.meta.counts;

    return (
        <section className="flex flex-col gap-6">
            <ContentHeader
                breadcrumb={[{ label: t('nav.group.deliver') }, { label: t('shipmentsBoard.crumb') }]}
                title={t('shipmentsBoard.title')}
                subtitle={t('shipmentsBoard.subtitle')}
                actions={
                    <>
                        <label htmlFor={`${ids}-range`} className="sr-only">
                            {t('shipmentsBoard.range')}
                        </label>
                        <Select
                            id={`${ids}-range`}
                            value={preset}
                            onChange={(e) =>
                                set({
                                    range: e.target.value === '7d' ? '' : e.target.value,
                                    cursor: '',
                                    shipment: '',
                                })
                            }
                            className="w-66 tabular"
                        >
                            {rangePresets.map((p) => {
                                const r = presetRange(p, today);
                                const span = r.from === r.to ? shortDay(r.to, locale) : `${shortDay(r.from, locale)} – ${shortDay(r.to, locale)}`;
                                return (
                                    <option key={p} value={p}>
                                        {t(`shipmentsBoard.range.${p}` as MessageKey, { span })}
                                    </option>
                                );
                            })}
                        </Select>
                    </>
                }
            />

            <StatTiles stats={stats.data} error={stats.isError ? stats.error : null} onRetry={() => void stats.refetch()} />

            <TabList label={t('shipmentsBoard.tabs')}>
                {boardTabs.map((value) => (
                    <button
                        key={value}
                        type="button"
                        aria-pressed={tab === value}
                        onClick={() =>
                            set({
                                tab: value === 'all' ? '' : value,
                                cursor: '',
                                shipment: '',
                            })
                        }
                        className={tabClass(tab === value)}
                    >
                        {t(`shipmentsBoard.tab.${value}` as MessageKey)}
                        <CountBadge count={counts?.[value] ?? 0} />
                    </button>
                ))}
            </TabList>

            <div className="flex flex-wrap items-center gap-3">
                <SearchField
                    label={t('shipmentsBoard.searchLabel')}
                    placeholder={t('shipmentsBoard.searchPlaceholder')}
                    value={search}
                    onChange={(e) => setSearch(e.target.value)}
                    className="w-full sm:w-85"
                />
                <label htmlFor={`${ids}-courier`} className="sr-only">
                    {t('shipmentsBoard.courier')}
                </label>
                <Select
                    id={`${ids}-courier`}
                    value={provider}
                    onChange={(e) =>
                        set({
                            courier: e.target.value,
                            cursor: '',
                            shipment: '',
                        })
                    }
                    className="w-44"
                >
                    <option value="">{t('shipmentsBoard.allCouriers')}</option>
                    {(board.data?.meta.providers ?? []).map((p) => (
                        <option key={p.code} value={p.code}>
                            {name(p.name, p.name_bn)}
                        </option>
                    ))}
                </Select>
            </div>

            {board.isError ? (
                <QueryError error={board.error} onRetry={() => void board.refetch()} />
            ) : board.isPending ? (
                <p className="t-admin-body text-fg-tertiary" aria-busy="true">
                    {t('common.loading')}
                </p>
            ) : rows.length === 0 ? (
                <EmptyState reason="noDataYet" title={t('shipmentsBoard.empty')} body={t('shipmentsBoard.emptyBody')} />
            ) : (
                <div className={dataTable.card}>
                    <table className={`${dataTable.table} lg:table-fixed`}>
                        <thead className={`hidden lg:table-header-group ${dataTable.head}`}>
                            <tr className="border-b border-line-strong">
                                <th scope="col" className={`${dataTable.th} w-32`}>
                                    {t('shipmentsBoard.col.shipment')}
                                </th>
                                <th scope="col" className={`${dataTable.th} w-44`}>
                                    {t('shipmentsBoard.col.order')}
                                </th>
                                <th scope="col" className={`${dataTable.th} w-44`}>
                                    {t('shipmentsBoard.col.courier')}
                                </th>
                                <th scope="col" className={`${dataTable.th} w-48`}>
                                    {t('shipmentsBoard.col.status')}
                                </th>
                                <th scope="col" className={dataTable.th}>
                                    {t('shipmentsBoard.col.lastEvent')}
                                </th>
                                <th scope="col" className={`${dataTable.th} w-22 text-end`}>
                                    {t('shipmentsBoard.col.cod')}
                                </th>
                                <th scope="col" className={`${dataTable.th} w-24 text-end`}>
                                    {t('shipmentsBoard.col.cost')}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map((row) => (
                                <BoardTableRow key={row.id} row={row} selected={row.id === selected} onSelect={() => set({ shipment: String(row.id) })} />
                            ))}
                        </tbody>
                    </table>
                    <DataTableFooter summary={t('shipmentsBoard.costNote')}>
                        {(board.data?.meta.prev_cursor || board.data?.meta.next_cursor) && (
                            <>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    disabled={!board.data?.meta.prev_cursor}
                                    onClick={() =>
                                        set({
                                            cursor: board.data?.meta.prev_cursor ?? '',
                                            shipment: '',
                                        })
                                    }
                                >
                                    <ArrowLeftIcon size={16} aria-hidden />
                                    {t('shipmentsBoard.newer')}
                                </Button>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    disabled={!board.data?.meta.next_cursor}
                                    onClick={() =>
                                        set({
                                            cursor: board.data?.meta.next_cursor ?? '',
                                            shipment: '',
                                        })
                                    }
                                >
                                    {t('shipmentsBoard.older')}
                                    <ArrowRightIcon size={16} aria-hidden />
                                </Button>
                            </>
                        )}
                    </DataTableFooter>
                </div>
            )}

            <div className="flex flex-col items-start gap-6 xl:flex-row">
                <div className="w-full min-w-0 flex-1">
                    {selected ? <BoardDetail id={selected} /> : <EmptyState reason="noDataYet" title={t('shipmentsBoard.pick')} className="border-dashed bg-subtle" />}
                </div>
                <div className="w-full min-w-0 xl:w-77 xl:shrink-0">
                    <BoardIssues />
                </div>
            </div>
        </section>
    );
}

/** Figma StatCard 189:360 as in B · 08: overline, large figure, caption; `warn` adds the 2px warning left edge. */
function Tile({ label, value, hint, warn = false }: { label: string; value: ReactNode; hint: ReactNode; warn?: boolean }) {
    return (
        <div className={`flex min-w-0 flex-col gap-1.5 rounded-md border border-line-default bg-surface p-4 shadow-e1 ${warn ? 'border-l-3 border-l-feedback-warning' : ''}`}>
            <p className="t-admin-overline text-fg-secondary">{label}</p>
            <p className="t-admin-num-lg text-fg-primary tabular">{value}</p>
            <p className="t-admin-caption text-fg-tertiary tabular">{hint}</p>
        </div>
    );
}

const dash = <span className="text-fg-tertiary">—</span>;

function StatTiles({ stats, error, onRetry }: { stats: BoardStats | undefined; error: Error | null; onRetry: () => void }) {
    const { t } = useI18n();
    if (error) return <QueryError error={error} onRetry={onRetry} />;
    if (!stats) {
        return (
            <p className="t-admin-body text-fg-tertiary" aria-busy="true">
                {t('common.loading')}
            </p>
        );
    }
    const age = stats.submitted.oldest_age_minutes === null ? null : hoursMinutes(stats.submitted.oldest_age_minutes);
    const chase = hoursMinutes(stats.submitted.chase_after_minutes).h;
    const days = stats.days_to_deliver;

    return (
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <Tile
                label={t('shipmentsBoard.stat.inTransit')}
                value={stats.in_transit.count}
                hint={
                    <>
                        <Taka amount={stats.in_transit.cod_on_road} /> {t('shipmentsBoard.stat.codOnRoad')}
                    </>
                }
            />
            <Tile
                label={t('shipmentsBoard.stat.submitted')}
                value={stats.submitted.count}
                warn={stats.submitted.count > 0}
                hint={
                    age === null ? (
                        t('shipmentsBoard.stat.submittedNone')
                    ) : (
                        <span className="inline-flex items-center gap-1">
                            {stats.submitted.overdue && <WarningIcon size={14} aria-hidden className="text-feedback-warning" />}
                            {t(stats.submitted.overdue ? 'shipmentsBoard.stat.submittedOverdue' : 'shipmentsBoard.stat.submittedAge', { h: age.h, m: age.m, chase })}
                        </span>
                    )
                }
            />
            <Tile
                label={t('shipmentsBoard.stat.success')}
                value={stats.success.rate === null ? dash : `${stats.success.rate}%`}
                hint={
                    stats.success.rate === null
                        ? t('shipmentsBoard.stat.successNone')
                        : t('shipmentsBoard.stat.successHint', {
                              delivered: stats.success.delivered,
                              total: stats.success.delivered + stats.success.returned,
                          })
                }
            />
            <Tile
                label={t('shipmentsBoard.stat.days')}
                value={days.average === null ? dash : days.average}
                hint={
                    days.average === null
                        ? t('shipmentsBoard.stat.daysNone')
                        : days.by_zone.length === 0
                        ? t('shipmentsBoard.stat.daysNoZone', {
                              count: days.timed,
                          })
                        : days.by_zone
                              .map((z) =>
                                  t('shipmentsBoard.stat.daysZone', {
                                      zone: t(`shipmentsBoard.zone.${z.zone}` as MessageKey),
                                      days: z.average,
                                  })
                              )
                              .join(' · ')
                }
            />
        </div>
    );
}

function BoardTableRow({ row, selected, onSelect }: { row: BoardRow; selected: boolean; onSelect: () => void }) {
    const { t, locale } = useI18n();
    const cell = `${dataTable.td.replace('h-15', 'h-auto')} min-h-14 py-2.5 max-lg:h-auto max-lg:p-0`;
    const event = row.last_event;
    const provider = locale === 'bn' && row.provider.name_bn ? row.provider.name_bn : row.provider.name;

    return (
        <tr
            className={`relative flex cursor-pointer flex-wrap items-center gap-x-4 gap-y-2 px-4 py-3 first:border-t-0 lg:table-row lg:p-0 lg:first:border-t ${dataTable.row} ${
                selected ? 'bg-brand-subtle hover:bg-brand-subtle' : ''
            }`}
            aria-selected={selected}
            onClick={onSelect}
        >
            <td className={cell}>
                <button
                    type="button"
                    onClick={onSelect}
                    aria-label={t('shipmentsBoard.select', {
                        number: row.number,
                    })}
                    className="t-admin-mono whitespace-nowrap text-fg-primary hover:text-fg-link hover:underline"
                >
                    {row.number}
                </button>
            </td>
            <td className={`min-w-0 ${cell}`}>
                <p className="truncate t-admin-body-strong text-fg-primary">{row.order?.number ?? '—'}</p>
                {row.order && (
                    <p className="truncate t-admin-caption text-fg-tertiary">
                        {row.order.customer_name}
                        <span className="tabular"> · {row.order.phone}</span>
                    </p>
                )}
            </td>
            <td className={`min-w-0 ${cell}`}>
                <p className="truncate t-admin-body text-fg-primary">{provider}</p>
                {row.consignment_id ? (
                    <p className="truncate t-admin-mono text-fg-tertiary">{row.consignment_id}</p>
                ) : (
                    <p className="truncate t-admin-caption text-fg-tertiary">{t('shipmentsBoard.notIssued')}</p>
                )}
            </td>
            <td className={cell}>
                <BoardStatusChip status={row.status} />
            </td>
            <td className={`min-w-0 ${cell}`}>
                {event ? (
                    <>
                        <p className="truncate t-admin-body text-fg-primary">{event.message ? `“${event.message}”` : t(statusKey(event.status))}</p>
                        <p className="flex flex-wrap items-center gap-1 t-admin-caption text-fg-tertiary tabular">
                            {dhaka(event.occurred_at, locale).day} {dhaka(event.occurred_at, locale).time}
                            <span aria-hidden>·</span>
                            <SourceChip source={event.source} />
                            {event.provider_status && <span className="truncate t-admin-mono">{event.provider_status}</span>}
                        </p>
                    </>
                ) : row.submitted_at ? (
                    <p className="truncate t-admin-body text-fg-primary tabular">
                        {t('shipmentsBoard.submittedAt', {
                            day: dhaka(row.submitted_at, locale).day,
                            time: dhaka(row.submitted_at, locale).time,
                        })}
                    </p>
                ) : (
                    <p className="t-admin-caption text-fg-tertiary">{t('shipmentsBoard.noEvents')}</p>
                )}
            </td>
            <td className={`${cell} t-admin-num tabular whitespace-nowrap lg:text-end`}>
                <Taka amount={row.cod_amount} />
            </td>
            <td className={`${cell} t-admin-num tabular whitespace-nowrap lg:text-end`}>
                {row.cost.amount === null ? (
                    <span className="text-fg-tertiary" title={t('shipmentsBoard.cost.none')}>
                        —<span className="sr-only">{t('shipmentsBoard.cost.none')}</span>
                    </span>
                ) : (
                    <>
                        <Taka amount={row.cost.amount} />
                        {row.cost.basis !== 'settled' && (
                            <span className="block t-admin-caption text-fg-tertiary">{t(row.cost.basis === 'estimate' ? 'shipmentsBoard.cost.est' : 'shipmentsBoard.cost.unsettled')}</span>
                        )}
                    </>
                )}
            </td>
        </tr>
    );
}
