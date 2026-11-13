import { useMemo, type ReactNode } from 'react';
import { useSearchParams } from 'react-router';
import { CalendarBlankIcon, CheckCircleIcon, WarningCircleIcon, XIcon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { can, useMe } from '@/features/auth/api';
import { Button } from '@/components/ui/Button';
import { ContentHeader } from '@/components/shell/ContentHeader';
import { DataTableFooter, dataTable } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { QueryError } from '@/components/ui/QueryError';
import { Select } from '@/components/ui/Select';
import { adjustmentReasons, movementTypes, useMovements, useReconciliation, type AdjustmentReason, type Movement, type MovementFilters, type MovementType } from './api';
import { MovementTypeChip, Taka, VariantCell, formatUnits, movementTypeLabel, signed } from './parts';

const ranges = ['7', '30', '90', 'all'] as const;
type Range = (typeof ranges)[number];

/** A Dhaka calendar date (YYYY-MM-DD) `daysBack` days before today — the API buckets by Dhaka day (rule 11). */
function dhakaDate(daysBack: number): string {
    const parts = new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Dhaka', year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date());
    const day = new Date(`${parts}T00:00:00Z`);
    day.setUTCDate(day.getUTCDate() - daysBack);
    return day.toISOString().slice(0, 10);
}

/** "02–08 Nov" for a range label. */
function rangeLabel(from: string, to: string): string {
    const fmt = (iso: string, opts: Intl.DateTimeFormatOptions) => new Intl.DateTimeFormat('en-GB', { timeZone: 'UTC', numberingSystem: 'latn', ...opts }).format(new Date(`${iso}T00:00:00Z`));
    return `${fmt(from, { day: '2-digit' })}–${fmt(to, { day: '2-digit', month: 'short' })}`;
}

function when(iso: string, locale: string): { day: string; time: string } {
    const date = new Date(iso);
    const tag = locale === 'bn' ? 'bn-BD' : 'en-GB';
    return {
        day: new Intl.DateTimeFormat(tag, { timeZone: 'Asia/Dhaka', day: '2-digit', month: 'short', numberingSystem: 'latn' }).format(date),
        time: new Intl.DateTimeFormat(tag, { timeZone: 'Asia/Dhaka', hour: 'numeric', minute: '2-digit', hour12: true, numberingSystem: 'latn' }).format(date).toLowerCase(),
    };
}

/**
 * Stock movement ledger (Figma "Stock ledger" 213:7516, Theme B colours): ContentHeader with the
 * date range, the reconciliation strip (opening / in / out / closing and the §7.1 ledger =
 * levels check from the server), and DataTable 192:532 of append-only movements, newest first.
 * Filters live in the URL (`?range=30&type=damage_out&variant=12`).
 */
export function MovementsPage() {
    const { t, locale } = useI18n();
    const { data: me } = useMe();
    const viewCost = can(me, 'catalog.view_cost');
    const [params, setParams] = useSearchParams();
    const rangeParam = params.get('range');
    const range: Range = ranges.includes(rangeParam as Range) ? (rangeParam as Range) : '7';
    const typeParam = params.get('type');
    const type: MovementType | '' = movementTypes.includes(typeParam as MovementType) ? (typeParam as MovementType) : '';
    const reasonParam = params.get('reason');
    const reason: AdjustmentReason | '' = adjustmentReasons.includes(reasonParam as AdjustmentReason) ? (reasonParam as AdjustmentReason) : '';
    const id = (key: string) => (/^\d+$/.test(params.get(key) ?? '') ? params.get(key)! : '');
    const today = dhakaDate(0);

    const filters: MovementFilters = useMemo(
        () => ({
            variant: id('variant'),
            product: id('product'),
            location: id('location'),
            type,
            reason,
            from: range === 'all' ? '' : dhakaDate(Number(range) - 1),
            to: range === 'all' ? '' : today,
        }),
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [params, today],
    );
    const movements = useMovements(filters);
    const reconciliation = useReconciliation(filters);
    const rows = useMemo(() => movements.data?.pages.flatMap((page) => page.data) ?? [], [movements.data]);
    const r = reconciliation.data;

    const setParam = (key: string, value: string, also: string[] = []) =>
        setParams(
            (current) => {
                const next = new URLSearchParams(current);
                if (value) next.set(key, value);
                else next.delete(key);
                for (const extra of also) next.delete(extra);
                return next;
            },
            { replace: true },
        );

    const rangeText = (value: Range): string =>
        value === 'all' ? t('movements.range.all') : t(`movements.range.${value}` as MessageKey, { range: rangeLabel(dhakaDate(Number(value) - 1), today) });

    const cell = `${dataTable.td.replace("h-15 px-3 py-2", "h-11 px-3 py-1.5")} max-lg:h-auto max-lg:p-0`;
    const num = `${cell} t-admin-num tabular lg:text-end`;

    return (
        <section className="flex flex-col gap-6">
            <ContentHeader
                breadcrumb={[{ label: t('catalog.crumb') }, { label: t('inventory.crumb'), to: '/inventory' }, { label: t('movements.crumb') }]}
                title={t('movements.title')}
                subtitle={t('movements.subtitle')}
                actions={
                    <label className="relative flex w-full sm:w-65">
                        <span className="sr-only">{t('movements.range')}</span>
                        <CalendarBlankIcon size={16} aria-hidden className="pointer-events-none absolute start-3 top-1/2 z-10 -translate-y-1/2 text-fg-secondary" />
                        <Select className="w-full [&_select]:h-9 [&_select]:ps-9" value={range} onChange={(e) => setParam('range', e.target.value === '7' ? '' : e.target.value)}>
                            {ranges.map((value) => (
                                <option key={value} value={value}>
                                    {rangeText(value)}
                                </option>
                            ))}
                        </Select>
                    </label>
                }
            />

            {reconciliation.isError ? (
                <QueryError error={reconciliation.error} onRetry={() => void reconciliation.refetch()} />
            ) : (
                r && (
                    <div className="flex flex-wrap items-center gap-x-4 gap-y-2 rounded-md bg-subtle px-4 py-3.5" aria-live="polite">
                        <p className="min-w-0 flex-1 basis-72 t-admin-body tabular text-fg-primary">
                            {t('movements.reconciliation', { opening: formatUnits(r.opening), in: signed(r.in), out: signed(r.out), closing: formatUnits(r.closing) })}
                        </p>
                        {viewCost && r.stock_value !== undefined && (
                            <p className="t-admin-body-strong tabular whitespace-nowrap text-fg-primary">
                                {t('movements.stockValue')}
                                <Taka amount={r.stock_value} signClass="t-admin-currency-num" />
                            </p>
                        )}
                        <p className="flex items-center gap-2 t-admin-caption-strong text-fg-secondary">
                            {r.balanced ? (
                                <CheckCircleIcon size={16} aria-hidden className="shrink-0 text-feedback-success" />
                            ) : (
                                <WarningCircleIcon size={16} aria-hidden className="shrink-0 text-feedback-danger" />
                            )}
                            <span className={r.balanced ? 't-admin-caption' : 't-admin-caption-strong text-fg-primary'}>
                                {r.balanced ? t('movements.balanced', { count: r.variants_checked }) : t('movements.drift', { count: r.drift_count })}
                            </span>
                        </p>
                    </div>
                )
            )}

            <div className="flex flex-wrap items-center gap-2">
                <label className="flex">
                    <span className="sr-only">{t('movements.typeFilter')}</span>
                    <Select size="sm" value={type} onChange={(e) => setParam('type', e.target.value)} className="w-48">
                        <option value="">{t('movements.typeAll')}</option>
                        {movementTypes.map((value) => (
                            <option key={value} value={value}>
                                {t(movementTypeLabel(value))}
                            </option>
                        ))}
                    </Select>
                </label>
                <label className="flex">
                    <span className="sr-only">{t('movements.reason')}</span>
                    <Select size="sm" value={reason} onChange={(e) => setParam('reason', e.target.value)} className="w-48">
                        <option value="">{t('movements.reason.all')}</option>
                        {adjustmentReasons.map((value) => (
                            <option key={value} value={value}>
                                {t(`adjust.reason.${value}` as MessageKey)}
                            </option>
                        ))}
                    </Select>
                </label>
                {filters.variant && (
                    <span className="inline-flex h-8 items-center gap-1 rounded-sm border border-line-default bg-surface ps-3 pe-1 t-admin-caption-strong text-fg-primary">
                        <span className="t-admin-mono-sm">{t('movements.variantFilter', { sku: params.get('sku') ?? `#${filters.variant}` })}</span>
                        <button type="button" aria-label={t('movements.clearVariant')} onClick={() => setParam('variant', '', ['sku'])} className="grid size-6 place-items-center rounded-xs text-fg-secondary hover:bg-subtle">
                            <XIcon size={14} aria-hidden />
                        </button>
                    </span>
                )}
            </div>

            {movements.isError ? (
                <QueryError error={movements.error} onRetry={() => void movements.refetch()} />
            ) : movements.isPending ? (
                <p className="t-admin-body text-fg-tertiary" aria-busy="true">
                    {t('common.loading')}
                </p>
            ) : rows.length === 0 ? (
                <EmptyState reason="allClear" title={t('movements.empty')} body={t('movements.emptyBody')} />
            ) : (
                <div className={dataTable.card}>
                    <table className={`${dataTable.table} lg:table-fixed`}>
                        <thead className={`hidden lg:table-header-group ${dataTable.head}`}>
                            {/* Figma DataTable 192:532 header: a border/strong rule under the column names. */}
                            <tr className="border-b border-line-strong">
                                <Th className="w-25">{t('movements.col.when')}</Th>
                                <Th className="w-60">{t('movements.col.product')}</Th>
                                <Th className="w-40">{t('movements.col.type')}</Th>
                                <Th end className="w-16">
                                    {t('movements.col.qty')}
                                </Th>
                                {viewCost && (
                                    <Th end className="w-22.5">
                                        {t('movements.col.unitCost')}
                                    </Th>
                                )}
                                <Th end className="w-20">
                                    {t('movements.col.balance')}
                                </Th>
                                <Th className="w-37.5">{t('movements.col.reference')}</Th>
                                <Th>{t('movements.col.by')}</Th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map((m) => (
                                <MovementRow key={m.id} m={m} locale={locale} viewCost={viewCost} cell={cell} num={num} />
                            ))}
                        </tbody>
                    </table>
                    <DataTableFooter summary={t('movements.count', { count: rows.length })}>
                        {movements.hasNextPage && (
                            <Button variant="outline" size="sm" onClick={() => void movements.fetchNextPage()} disabled={movements.isFetchingNextPage}>
                                {movements.isFetchingNextPage ? t('common.loading') : t('common.loadMore')}
                            </Button>
                        )}
                    </DataTableFooter>
                </div>
            )}
        </section>
    );
}

function MovementRow({ m, locale, viewCost, cell, num }: { m: Movement; locale: string; viewCost: boolean; cell: string; num: string }) {
    const { t } = useI18n();
    const at = when(m.created_at, locale);
    const reference = m.reference_number ?? (m.reference_type === 'product' ? t('movements.ref.opening') : m.reference_type ? `${m.reference_type} #${m.reference_id ?? ''}` : '—');

    return (
        <tr className={`flex flex-wrap items-center gap-x-4 gap-y-1 px-4 py-3 first:border-t-0 lg:table-row lg:p-0 lg:first:border-t ${dataTable.row}`}>
            <td className={cell}>
                <p className="t-admin-body-strong tabular whitespace-nowrap text-fg-primary">{at.day}</p>
                <p className="t-admin-caption tabular whitespace-nowrap text-fg-tertiary">{at.time}</p>
            </td>
            <td className={`min-w-0 basis-full lg:basis-auto ${cell}`}>
                <VariantCell row={m} />
            </td>
            <td className={cell}>
                <MovementTypeChip type={m.type} />
            </td>
            <td className={num}>
                <StackLabel text={t('movements.col.qty')} />
                {signed(m.quantity)}
            </td>
            {viewCost && (
                <td className={`${num} whitespace-nowrap text-fg-secondary`}>
                    <StackLabel text={t('movements.col.unitCost')} />
                    {m.unit_cost ? <Taka amount={m.unit_cost} /> : '—'}
                </td>
            )}
            <td className={num}>
                <StackLabel text={t('movements.col.balance')} />
                {formatUnits(m.balance_after)}
            </td>
            <td className={`${cell} truncate t-admin-mono ${m.reference_number ? 'text-fg-link' : 'text-fg-secondary'}`}>{reference}</td>
            <td className={`${cell} truncate t-admin-body text-fg-primary`}>{m.user_name ?? t('movements.system')}</td>
        </tr>
    );
}

/** Column name in front of a value on the stacked (below lg) row; hidden where the header shows. */
function StackLabel({ text }: { text: string }) {
    return <span className="me-1.5 t-admin-caption text-fg-tertiary lg:hidden">{text}</span>;
}

function Th({ children, end, className = '' }: { children: ReactNode; end?: boolean; className?: string }) {
    return (
        <th scope="col" className={`${end ? dataTable.th.replace('text-start', 'text-end') : dataTable.th} ${className}`}>
            {children}
        </th>
    );
}
