import { useMemo, useState, type ReactNode } from 'react';
import { useSearchParams } from 'react-router';
import { XIcon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { can, useMe } from '@/features/auth/api';
import { Button } from '@/components/ui/Button';
import { ContentHeader } from '@/components/shell/ContentHeader';
import { DataTableFooter, dataTable } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { inputClass } from '@/components/ui/Field';
import { QueryError } from '@/components/ui/QueryError';
import { Select } from '@/components/ui/Select';
import {
    destinations,
    dispatchStatuses,
    eventNames,
    eventSources,
    useTrackingEvents,
    useTrackingSummary,
    type DestinationHealth,
    type EventFilters,
    type TrackingEvent,
} from './api';
import { EventDetailDrawer } from './EventDetailDrawer';
import { DispatchChip, Stat, TRACKING_PATH, dhakaToday, dhakaWhen, formatMoney, timeAgo } from './parts';

const PERMISSION = 'settings.view';
const DATE = /^\d{4}-\d{2}-\d{2}$/;
const ORDER = /^[A-Za-z0-9-]{1,20}$/;

/** Read the allowlisted filters from the URL; anything malformed is dropped, never sent (a 400). */
function readFilters(params: URLSearchParams): EventFilters {
    const pick = <T extends string>(key: string, allowed: readonly T[]): T | '' => {
        const value = params.get(key);
        return value !== null && (allowed as readonly string[]).includes(value) ? (value as T) : '';
    };
    const date = (key: string) => {
        const value = params.get(key) ?? '';
        return DATE.test(value) ? value : '';
    };
    const order = params.get('order_number') ?? '';
    return {
        event_name: pick('event_name', eventNames),
        source: pick('source', eventSources),
        ga4_status: pick('ga4_status', dispatchStatuses),
        meta_status: pick('meta_status', dispatchStatuses),
        date_from: date('date_from'),
        date_to: date('date_to'),
        order_number: ORDER.test(order) ? order : '',
    };
}

/**
 * Integrations → Tracking → Diagnostics (PRD K-03). No frame of its own: the "Diagnostics"
 * button of B · 25 (241:31097) opens it, built from StatCard 189:360 (health tiles per
 * destination), DataTable 192:532 (the event log, newest first, filters in the URL) and the
 * CourierBookingPanel drawer (event detail). Every number is computed by the server.
 */
export function TrackingDiagnosticsPage() {
    const { t } = useI18n();
    const { data: me } = useMe();
    const allowed = can(me, PERMISSION);
    const [params, setParams] = useSearchParams();
    const filters = useMemo(() => readFilters(params), [params]);
    const summary = useTrackingSummary(allowed);
    const events = useTrackingEvents(filters, allowed);
    const rows = useMemo(() => events.data?.pages.flatMap((page) => page.data) ?? [], [events.data]);
    const [selected, setSelected] = useState<number | null>(null);
    const [orderDraft, setOrderDraft] = useState(filters.order_number);

    if (me && !allowed) {
        return <p className="t-admin-body text-fg-secondary">{t('error.forbidden')}</p>;
    }

    const setParam = (key: keyof EventFilters, value: string) =>
        setParams(
            (current) => {
                const next = new URLSearchParams(current);
                if (value) next.set(key, value);
                else next.delete(key);
                return next;
            },
            { replace: true },
        );
    const filtered = Object.values(filters).some((value) => value !== '');

    return (
        <section className="flex flex-col gap-6">
            <ContentHeader
                breadcrumb={[{ label: t('tracking.crumb.settings') }, { label: t('tracking.crumb.integrations'), to: '/integrations' }, { label: t('tracking.crumb.tracking'), to: TRACKING_PATH }, { label: t('tracking.diag.title') }]}
                title={t('tracking.diag.title')}
                subtitle={t('tracking.diag.subtitle')}
            />

            {summary.isError ? (
                <QueryError error={summary.error} onRetry={() => void summary.refetch()} />
            ) : summary.isPending ? (
                <p className="t-admin-body text-fg-tertiary" aria-busy="true">
                    {t('common.loading')}
                </p>
            ) : (
                destinations.map((key) => {
                    const health = summary.data.destinations.find((d) => d.destination === key);
                    return health ? <HealthRow key={key} health={health} /> : null;
                })
            )}

            <section className="flex flex-col gap-3" aria-labelledby="tracking-log">
                <h2 id="tracking-log" className="t-admin-section text-fg-primary">
                    {t('tracking.log.title')}
                </h2>
                <div className="flex flex-wrap items-center gap-2">
                    <FilterSelect label={t('tracking.log.filter.event')} all={t('tracking.log.filter.allEvents')} value={filters.event_name} onChange={(value) => setParam('event_name', value)}>
                        {eventNames.map((name) => (
                            <option key={name} value={name}>
                                {name}
                            </option>
                        ))}
                    </FilterSelect>
                    <FilterSelect label={t('tracking.log.filter.source')} all={t('tracking.log.filter.allSources')} value={filters.source} onChange={(value) => setParam('source', value)}>
                        {eventSources.map((source) => (
                            <option key={source} value={source}>
                                {t(`tracking.source.${source}` as MessageKey)}
                            </option>
                        ))}
                    </FilterSelect>
                    {destinations.map((key) => (
                        <FilterSelect
                            key={key}
                            label={t(`tracking.log.filter.${key}Status` as MessageKey)}
                            all={t(`tracking.log.filter.${key}All` as MessageKey)}
                            value={filters[`${key}_status`]}
                            onChange={(value) => setParam(`${key}_status`, value)}
                        >
                            {dispatchStatuses.map((status) => (
                                <option key={status} value={status}>
                                    {t(`tracking.status.${status}` as MessageKey)}
                                </option>
                            ))}
                        </FilterSelect>
                    ))}
                    <label className="flex items-center gap-1.5">
                        <span className="t-admin-caption text-fg-secondary">{t('tracking.log.filter.from')}</span>
                        <input type="date" value={filters.date_from} max={filters.date_to || dhakaToday()} onChange={(e) => setParam('date_from', e.target.value)} className={`h-8 w-38 ${inputClass}`} />
                    </label>
                    <label className="flex items-center gap-1.5">
                        <span className="t-admin-caption text-fg-secondary">{t('tracking.log.filter.to')}</span>
                        <input type="date" value={filters.date_to} min={filters.date_from || undefined} max={dhakaToday()} onChange={(e) => setParam('date_to', e.target.value)} className={`h-8 w-38 ${inputClass}`} />
                    </label>
                    <form
                        className="flex"
                        onSubmit={(e) => {
                            e.preventDefault();
                            const value = orderDraft.trim().toUpperCase();
                            setParam('order_number', ORDER.test(value) ? value : '');
                        }}
                    >
                        <label className="flex">
                            <span className="sr-only">{t('tracking.log.filter.order')}</span>
                            <input
                                type="search"
                                value={orderDraft}
                                placeholder={t('tracking.log.filter.order')}
                                onChange={(e) => {
                                    setOrderDraft(e.target.value);
                                    if (e.target.value === '') setParam('order_number', '');
                                }}
                                className={`h-8 w-44 t-admin-mono ${inputClass}`}
                            />
                        </label>
                    </form>
                    {filtered && (
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => {
                                setOrderDraft('');
                                setParams(new URLSearchParams(), { replace: true });
                            }}
                        >
                            <XIcon size={14} aria-hidden />
                            {t('tracking.log.filter.clear')}
                        </Button>
                    )}
                </div>

                {events.isError ? (
                    <QueryError error={events.error} onRetry={() => void events.refetch()} />
                ) : events.isPending ? (
                    <p className="t-admin-body text-fg-tertiary" aria-busy="true">
                        {t('common.loading')}
                    </p>
                ) : rows.length === 0 ? (
                    filtered ? (
                        <EmptyState
                            reason="noDataYet"
                            title={t('tracking.log.noResults')}
                            body={t('tracking.log.noResultsBody')}
                            actions={
                                <Button variant="outline" onClick={() => setParams(new URLSearchParams(), { replace: true })}>
                                    {t('tracking.log.filter.clear')}
                                </Button>
                            }
                        />
                    ) : (
                        <EmptyState reason="notConnected" title={t('tracking.log.empty')} body={t('tracking.log.emptyBody')} />
                    )
                ) : (
                    <div className={dataTable.card}>
                        <table className={`${dataTable.table} lg:table-fixed`}>
                            <thead className={`hidden lg:table-header-group ${dataTable.head}`}>
                                <tr className="border-b border-line-strong">
                                    <th className={`${dataTable.th} w-28`}>{t('tracking.log.col.when')}</th>
                                    <th className={`${dataTable.th} w-40`}>{t('tracking.log.col.event')}</th>
                                    <th className={`${dataTable.th} w-24`}>{t('tracking.log.col.source')}</th>
                                    <th className={`${dataTable.th} w-36`}>{t('tracking.log.col.order')}</th>
                                    <th className={`${dataTable.th} w-28 text-end`}>{t('tracking.log.col.value')}</th>
                                    <th className={dataTable.th}>{t('tracking.log.col.ga4')}</th>
                                    <th className={dataTable.th}>{t('tracking.log.col.meta')}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {rows.map((row) => (
                                    <EventRow key={row.id} row={row} onOpen={() => setSelected(row.id)} />
                                ))}
                            </tbody>
                        </table>
                        <DataTableFooter summary={t('tracking.log.count', { count: rows.length })}>
                            {events.hasNextPage && (
                                <Button variant="outline" size="sm" onClick={() => void events.fetchNextPage()} disabled={events.isFetchingNextPage}>
                                    {events.isFetchingNextPage ? t('common.loading') : t('common.loadMore')}
                                </Button>
                            )}
                        </DataTableFooter>
                    </div>
                )}
            </section>

            <EventDetailDrawer id={selected} onClose={() => setSelected(null)} />
        </section>
    );
}

function FilterSelect({ label, all, value, onChange, children }: { label: string; all: string; value: string; onChange: (value: string) => void; children: ReactNode }) {
    return (
        <label className="flex">
            <span className="sr-only">{label}</span>
            <Select size="sm" value={value} onChange={(e) => onChange(e.target.value)} className="w-44">
                <option value="">{all}</option>
                {children}
            </Select>
        </label>
    );
}

/** One destination's health: StatCard 189:360 ×4, a danger edge on open failures. */
function HealthRow({ health }: { health: DestinationHealth }) {
    const { t, locale } = useI18n();
    const name = t(`tracking.${health.destination}.title` as MessageKey);
    const d = health.window_24h;
    const w = health.window_7d;
    const percent = (value: string | null) => (value === null ? '—' : `${value}%`);

    return (
        <section className="flex flex-col gap-3" aria-label={name}>
            <div className="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                <h2 className="t-admin-section text-fg-primary">{name}</h2>
                <p className="t-admin-caption text-fg-tertiary">
                    {health.last_success_at ? t('tracking.diag.lastSuccess', { ago: timeAgo(health.last_success_at, locale) }) : t('tracking.diag.noSuccess')}
                </p>
            </div>
            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <Stat label={t('tracking.diag.sent')} hint={t('tracking.diag.sentHint', { week: w.sent, pending: d.pending })}>
                    {d.sent}
                </Stat>
                <Stat
                    label={t('tracking.diag.failed')}
                    edge={d.failed > 0}
                    hint={health.last_error ? t('tracking.diag.lastError', { ago: health.last_error_at ? timeAgo(health.last_error_at, locale) : '—', error: health.last_error }) : t('tracking.diag.failedHint', { week: w.failed })}
                >
                    {d.failed}
                </Stat>
                <Stat label={t('tracking.diag.consent')} hint={t('tracking.diag.consentHint', { skipped: w.skipped_consent, other: w.skipped_other })}>
                    {percent(health.consent_skip_rate)}
                </Stat>
                <Stat label={t('tracking.diag.pairing')} hint={t(health.destination === 'meta' ? 'tracking.diag.pairingHint' : 'tracking.diag.pairingNa')}>
                    {percent(health.pairing_rate)}
                </Stat>
            </div>
        </section>
    );
}

function EventRow({ row, onOpen }: { row: TrackingEvent; onOpen: () => void }) {
    const { t, locale } = useI18n();
    const at = dhakaWhen(row.created_at ?? row.occurred_at ?? '', locale);
    const cell = `${dataTable.td} max-lg:h-auto max-lg:p-0`;

    return (
        <tr className={`flex flex-wrap items-center gap-x-4 gap-y-1 px-4 py-3 first:border-t-0 lg:table-row lg:p-0 lg:first:border-t ${dataTable.row}`}>
            <td className={cell}>
                <p className="t-admin-body-strong tabular whitespace-nowrap text-fg-primary">{at.day}</p>
                <p className="t-admin-caption tabular whitespace-nowrap text-fg-tertiary">{at.time}</p>
            </td>
            <td className={cell}>
                <button type="button" onClick={onOpen} className="max-w-full truncate text-start t-admin-mono text-fg-link hover:underline" aria-label={t('tracking.log.open', { name: row.event_name })}>
                    {row.event_name}
                </button>
                <p className="truncate t-admin-mono-sm text-fg-tertiary" title={row.event_id}>
                    {row.event_id.slice(0, 8)}
                </p>
            </td>
            <td className={`${cell} t-admin-body`}>{t(`tracking.source.${row.source}` as MessageKey)}</td>
            <td className={`${cell} truncate t-admin-mono`}>{row.order_number ?? '—'}</td>
            <td className={`${cell} t-admin-num tabular whitespace-nowrap lg:text-end`}>{row.value !== null ? formatMoney(row.value, row.currency) : '—'}</td>
            <td className={cell}>
                <DispatchChip status={row.ga4.status} />
                {row.ga4.error && <p className="mt-1 truncate t-admin-caption text-fg-tertiary">{row.ga4.error}</p>}
            </td>
            <td className={cell}>
                <DispatchChip status={row.meta.status} />
                {row.meta.error && <p className="mt-1 truncate t-admin-caption text-fg-tertiary">{row.meta.error}</p>}
            </td>
        </tr>
    );
}
