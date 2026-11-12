import { useEffect, useMemo, useState, type ReactNode } from 'react';
import { Link, useSearchParams } from 'react-router';
import { ArrowLeftIcon, ArrowRightIcon, CalendarBlankIcon, CheckCircleIcon, CheckIcon, ShieldWarningIcon, WarningCircleIcon, WarningIcon, XIcon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { can, useMe } from '@/features/auth/api';
import { Button } from '@/components/ui/Button';
import { ContentHeader } from '@/components/shell/ContentHeader';
import { CountBadge } from '@/components/ui/CountBadge';
import { DataTableFooter, dataTable } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { IconButton } from '@/components/ui/IconButton';
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
import { Taka, ageLabel, dhakaDate, dhakaParts, isZeroAmount, rangeLabel } from './format';
import { OrderStatusBadge, PaymentChip, RiskPill, paymentLabel, sourceLabel } from './parts';

/** Status tabs in Figma 205:681 order, with On hold and Processing kept so every pre-ship order has a home. */
const tabs: BoardTab[] = ['all', 'pending', 'verify', 'on_hold', 'confirmed', 'processing', 'ready_to_ship', 'shipped', 'delivered', 'returned', 'cancelled'];
const ranges = ['all', '1', '7', '30', '90'] as const;
type Range = (typeof ranges)[number];
const preShip = new Set(['pending', 'on_hold', 'confirmed', 'processing', 'ready_to_ship']);

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

/**
 * Order board (Figma "02 Orders board" 205:681, Theme B colours): ContentHeader with the date
 * range, status AdminTabs with server counts and the pinned Anomalies tab, URL-driven filters,
 * DataTable 192:532 with the OrderStatusBadge triad, RiskPill and PaymentChip, cursor
 * pagination, and the floating BulkActionBar 193:740 for bulk confirm. Every filter is a
 * server-side allowlisted parameter; the URL carries all of it (`?tab=pending&risk=high`).
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

    const rangeText = (value: Range): string =>
        value === 'all' ? t('orders.range.all') : value === '1' ? t('orders.range.today', { range: rangeLabel(today, today) }) : t(`orders.range.${value}` as MessageKey, { range: rangeLabel(dhakaDate(Number(value) - 1), today) });

    const subtitleCount = counts.all ?? 0;
    const highRisk = rows.filter((row) => selected.has(row.id) && row.risk_level === 'high').length;

    return (
        <section className="flex flex-col gap-4 pb-24">
            <ContentHeader
                breadcrumb={[{ label: t('nav.group.sell') }, { label: t('orders.crumb') }]}
                title={t('orders.title')}
                subtitle={orders.data && t('orders.subtitle', { count: subtitleCount, range: rangeText(range) })}
                actions={
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
                }
            />

            <div className="flex items-end gap-2 border-b border-line-default">
                <TabList label={t('orders.tabs')} className="min-w-0 flex-1 border-b-0">
                    {tabs.map((value) => (
                        <button key={value} type="button" aria-pressed={tab === value} onClick={() => setParam({ tab: value === 'all' ? '' : value })} className={tabClass(tab === value)}>
                            {t(tabLabel(value))}
                            <CountBadge count={counts[value] ?? 0} />
                        </button>
                    ))}
                </TabList>
                {/* Pinned Anomalies tab: a warning-edged chip, the exception the eye should land on. */}
                <button
                    type="button"
                    aria-pressed={tab === 'anomalies'}
                    onClick={() => setParam({ tab: tab === 'anomalies' ? '' : 'anomalies' })}
                    className={`mb-1 inline-flex h-8.25 shrink-0 items-center gap-1.5 rounded-xs border-l-2 border-feedback-warning px-3 t-admin-body-strong whitespace-nowrap text-fg-primary ${tab === 'anomalies' ? 'bg-warning-subtle' : 'bg-subtle hover:bg-warning-subtle'}`}
                >
                    <WarningIcon size={14} aria-hidden className="text-feedback-warning" />
                    {t('orders.tab.anomalies')}
                    <span className="t-admin-caption-strong tabular">{counts.anomalies ?? 0}</span>
                </button>
            </div>

            <div className="flex flex-wrap items-center gap-2">
                <SearchField
                    label={t('orders.searchLabel')}
                    placeholder={t('orders.searchPlaceholder')}
                    value={search}
                    onChange={(e) => setSearch(e.target.value)}
                    className="w-full sm:w-85"
                />
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
                <label className="ms-2 inline-flex items-center gap-2 t-admin-body text-fg-primary">
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
            </div>

            {orders.isError ? (
                <QueryError error={orders.error} onRetry={() => void orders.refetch()} />
            ) : orders.isPending ? (
                <p className="t-admin-body text-fg-tertiary" aria-busy="true">
                    {t('common.loading')}
                </p>
            ) : rows.length === 0 ? (
                <EmptyState reason="allClear" title={t('orders.empty')} body={t('orders.emptyBody')} />
            ) : (
                <div className={dataTable.card}>
                    <table className={`${dataTable.table} lg:table-fixed`}>
                        <thead className={`hidden lg:table-header-group ${dataTable.head}`}>
                            <tr className="border-b border-line-strong">
                                <th scope="col" className={`${dataTable.th} w-10 px-0 text-center`}>
                                    {canConfirm && <input type="checkbox" checked={allOnPage} onChange={toggleAll} aria-label={t('orders.selectPage')} className="size-4 accent-brand" />}
                                </th>
                                <Th className="w-30">{t('orders.col.order')}</Th>
                                <Th className="w-23">{t('orders.col.placed')}</Th>
                                <Th className="w-42">{t('orders.col.customer')}</Th>
                                <Th end className="w-13">
                                    {t('orders.col.items')}
                                </Th>
                                <Th end className="w-24">
                                    {t('orders.col.total')}
                                </Th>
                                <Th className="w-28">{t('orders.col.payment')}</Th>
                                <Th className="w-54">{t('orders.col.status')}</Th>
                                <Th className="w-25">{t('orders.col.risk')}</Th>
                                <Th>{t('orders.col.courier')}</Th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map((row) => (
                                <OrderRowView key={row.id} row={row} selectable={canConfirm} selected={selected.has(row.id)} onToggle={() => toggle(row.id)} />
                            ))}
                        </tbody>
                    </table>
                    <DataTableFooter summary={t(selected.size > 0 ? 'orders.footer.selected' : 'orders.footer', { count: rows.length, selected: selected.size })}>
                        <Button variant="outline" size="sm" disabled={!prev} onClick={() => setParam({ cursor: prev ?? '' }, true)}>
                            <ArrowLeftIcon size={16} aria-hidden />
                            {t('orders.newer')}
                        </Button>
                        <Button variant="outline" size="sm" disabled={!next} onClick={() => setParam({ cursor: next ?? '' }, true)}>
                            {t('orders.older')}
                            <ArrowRightIcon size={16} aria-hidden />
                        </Button>
                    </DataTableFooter>
                </div>
            )}

            {(selected.size > 0 || result) && (
                <BulkActionBar
                    count={selected.size}
                    highRisk={highRisk}
                    pending={bulk.isPending}
                    result={result}
                    onConfirm={confirmSelected}
                    onClear={() => {
                        setSelected(new Set());
                        setResult(null);
                    }}
                />
            )}
        </section>
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

function OrderRowView({ row, selectable, selected, onToggle }: { row: OrderRow; selectable: boolean; selected: boolean; onToggle: () => void }) {
    const { t, locale } = useI18n();
    const placed = row.placed_at ?? row.created_at;
    const at = placed ? dhakaParts(placed, locale) : null;
    const age = placed ? ageLabel(placed) : null;
    const verify = row.verification_status === 'pending';
    const partlyPaid = !isZeroAmount(row.paid_total) && !isZeroAmount(row.due_total);
    const cell = `${dataTable.td.replace('h-15', 'h-auto')} py-3 max-lg:h-auto max-lg:p-0`;
    // verification_status = pending: the only row-level emphasis on the board (a 2px danger edge).
    const edge = verify ? 'max-lg:border-s-2 max-lg:border-s-feedback-danger' : '';

    return (
        <tr
            className={`relative flex flex-wrap items-center gap-x-4 gap-y-2 px-4 py-3 first:border-t-0 lg:table-row lg:p-0 lg:first:border-t ${dataTable.row} ${selected ? 'bg-brand-subtle hover:bg-brand-subtle' : ''} ${edge}`}
            aria-selected={selectable ? selected : undefined}
        >
            <td className={`${cell} w-10 lg:px-0 lg:text-center ${verify ? 'lg:border-s-2 lg:border-s-feedback-danger' : ''}`}>
                {selectable && <input type="checkbox" checked={selected} onChange={onToggle} aria-label={t('orders.select', { number: row.number })} className="size-4 accent-brand" />}
            </td>
            <td className={cell}>
                <Link to={`/orders/${row.id}`} className="block t-admin-mono whitespace-nowrap text-fg-primary hover:text-fg-link hover:underline">
                    {row.number}
                </Link>
                <p className="t-admin-caption text-fg-tertiary">{t(sourceLabel(row.source))}</p>
            </td>
            <td className={cell}>
                {at && (
                    <>
                        <p className="t-admin-body-strong tabular whitespace-nowrap text-fg-primary">{at.day}</p>
                        <p className="t-admin-caption tabular whitespace-nowrap text-fg-tertiary">{age ? t(age.key, age.vars) : at.time}</p>
                    </>
                )}
            </td>
            <td className={`min-w-0 basis-full lg:basis-auto ${cell}`}>
                <p className="truncate t-admin-body-strong text-fg-primary">{row.customer_name}</p>
                <p className="truncate t-admin-caption tabular text-fg-tertiary">{[row.customer_phone_masked, row.district].filter(Boolean).join(' · ')}</p>
            </td>
            <td className={`${cell} t-admin-num tabular lg:text-end`}>
                <span className="me-1.5 t-admin-caption text-fg-tertiary lg:hidden">{t('orders.col.items')}</span>
                {row.items_count}
            </td>
            <td className={`${cell} lg:text-end`}>
                <p className="t-admin-num tabular whitespace-nowrap text-fg-primary">
                    <Taka amount={row.grand_total} />
                </p>
                {partlyPaid && <p className="t-admin-caption tabular whitespace-nowrap text-fg-tertiary">{t('orders.duePrefix')}
                        <Taka amount={row.due_total} signClass="t-admin-currency-caption" /></p>}
            </td>
            <td className={cell}>
                <PaymentChip method={row.payment_method} />
            </td>
            <td className={cell}>
                <span className="hidden lg:block">
                    <OrderStatusBadge order={{ ...row, paid: row.paid_total, refunded: row.refunded_total }} />
                </span>
                <span className="lg:hidden">
                    <OrderStatusBadge form="compact" order={{ ...row, paid: row.paid_total, refunded: row.refunded_total }} />
                </span>
            </td>
            <td className={cell}>
                <RiskPill level={row.risk_level} score={row.risk_score} />
            </td>
            <td className={cell}>
                {/* Couriers arrive in Phase 6: nothing is ever booked yet, so say so, never invent a consignment. */}
                <p className="t-admin-body text-fg-primary">{preShip.has(row.status) ? t('orders.courier.notBooked') : t('orders.courier.noData')}</p>
                {verify && <p className="t-admin-caption text-fg-tertiary">{t('orders.courier.verifyFirst')}</p>}
            </td>
        </tr>
    );
}

/**
 * Figma BulkActionBar 193:740 — floating over the content at 1440/1024, docked at 768.
 * Confirm only (the other bulk actions belong to later phases). The result is always two
 * numbers, never "done": the failures stay selected and are listed with the server's reason.
 */
function BulkActionBar({
    count,
    highRisk,
    pending,
    result,
    onConfirm,
    onClear,
}: {
    count: number;
    highRisk: number;
    pending: boolean;
    result: { done: number; failed: BulkResult[] } | null;
    onConfirm: () => void;
    onClear: () => void;
}) {
    const { t } = useI18n();

    return (
        <div
            role="region"
            aria-label={t('orders.bulk.label')}
            className="fixed inset-x-0 bottom-0 z-40 flex flex-col gap-3 rounded-t-xl border border-line-strong bg-surface-raised px-4 py-3 shadow-e4 md:inset-x-auto md:bottom-6 md:start-1/2 md:w-[min(60rem,calc(100%-3rem))] md:min-w-140 md:-translate-x-1/2 md:rounded-lg lg:start-[calc(50%+7.75rem)]"
        >
            <div className="flex flex-wrap items-center justify-between gap-4">
                <div className="flex flex-col gap-0.5">
                    <p className="t-admin-body-strong text-fg-primary" aria-live="polite">
                        {t('orders.bulk.selected', { count })}
                    </p>
                    <button type="button" onClick={onClear} className="self-start t-admin-caption-strong text-fg-secondary hover:text-fg-primary hover:underline">
                        {t('orders.bulk.clear')}
                    </button>
                </div>
                {highRisk > 0 && (
                    <p className="flex items-center gap-1 t-admin-caption text-fg-secondary">
                        <ShieldWarningIcon size={14} aria-hidden className="text-feedback-danger" />
                        {t('orders.bulk.highRisk', { count: highRisk })}
                    </p>
                )}
                <div className="ms-auto flex items-center gap-2">
                    <Button onClick={onConfirm} disabled={count === 0 || pending}>
                        <CheckIcon size={16} aria-hidden />
                        {pending ? t('orders.bulk.confirming', { count }) : t('orders.bulk.confirm', { count })}
                    </Button>
                    <span aria-hidden className="h-8 w-px bg-line-default" />
                    <IconButton aria-label={t('orders.bulk.clear')} onClick={onClear}>
                        <XIcon size={18} aria-hidden />
                    </IconButton>
                </div>
            </div>
            {result && (
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
