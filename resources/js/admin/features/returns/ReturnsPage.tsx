import { useEffect, useState, type ComponentType } from 'react';
import { Link, Navigate, useNavigate, useSearchParams } from 'react-router';
import {
    ArrowLeftIcon,
    ArrowRightIcon,
    CheckIcon,
    ClockCounterClockwiseIcon,
    HandCoinsIcon,
    ImageSquareIcon,
    InfoIcon,
    ListChecksIcon,
    PlusIcon,
    XCircleIcon,
    type IconProps,
} from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { ContentHeader } from '@/components/shell/ContentHeader';
import { buttonClass, Button } from '@/components/ui/Button';
import { DataTableFooter, dataTable } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { QueryError } from '@/components/ui/QueryError';
import { SearchField } from '@/components/ui/SearchField';
import { StatusChip } from '@/components/ui/StatusChip';
import { PaymentChip } from '@/features/orders';
import { returnTabs, useReturns, type OrderReturn, type ReturnStatus } from './api';
import { LONG_WAIT_DAYS, Taka, ageParts, daysOpen, nextAction, reasonLabel } from './parts';

/** The three working steps, in order; closed and rejected sit in the "Finished" card. */
const workSteps: { status: ReturnStatus; icon: ComponentType<IconProps> }[] = [
    { status: 'requested', icon: ClockCounterClockwiseIcon },
    { status: 'received', icon: ListChecksIcon },
    { status: 'inspected', icon: HandCoinsIcon },
];
const finished: { status: ReturnStatus; icon: ComponentType<IconProps>; tint: string }[] = [
    { status: 'closed', icon: CheckIcon, tint: 'text-feedback-success' },
    { status: 'rejected', icon: XCircleIcon, tint: 'text-fg-tertiary' },
];

/**
 * Returns & refunds list (Figma "B · 10 Returns · Premium · List" 582:50341, Theme B), one
 * column: the three working steps as cards with server counts and what each step waits on,
 * a Finished card (closed · rejected), then the full-width queue. Each row says the next
 * thing to do and opens the return's own page (/returns/:id). Step, search and cursor live
 * in the URL. Exchanges and store credit are not built yet, so every return is a refund.
 */
export function ReturnsPage() {
    const { t } = useI18n();
    const [params, setParams] = useSearchParams();
    const tab = (returnTabs.includes(params.get('tab') as ReturnStatus) ? params.get('tab') : 'requested') as ReturnStatus;
    const cursor = params.get('cursor');
    const [search, setSearch] = useState(params.get('q') ?? '');
    const query = params.get('q') ?? '';
    const returns = useReturns(tab, query, cursor);

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
            { replace: true },
        );

    useEffect(() => {
        const id = window.setTimeout(() => {
            if (search.trim() !== query) set({ q: search.trim(), cursor: '' });
        }, 250);
        return () => window.clearTimeout(id);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    // Links from before the detail page had its own route: /returns?rma=42 → /returns/42.
    const legacy = Number(params.get('rma'));
    if (legacy) return <Navigate to={`/returns/${legacy}`} replace />;

    const rows = returns.data?.data ?? [];
    const counts = returns.data?.meta.counts;
    const pick = (value: ReturnStatus) => set({ tab: value === 'requested' ? '' : value, cursor: '' });

    return (
        <section className="flex flex-col gap-6">
            <ContentHeader
                breadcrumb={[{ label: t('nav.group.sell') }, { label: t('returns.crumb') }]}
                title={t('returns.title')}
                subtitle={t('returns.subtitle')}
                actions={
                    <Link to="/orders?tab=delivered" className={buttonClass('primary')} title={t('returns.newHint')}>
                        <PlusIcon size={16} aria-hidden />
                        {t('returns.new')}
                    </Link>
                }
            />

            <div role="group" aria-label={t('returns.tabs')} className="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-[1fr_1fr_1fr_13rem]">
                {workSteps.map(({ status, icon: Icon }, index) => {
                    const active = tab === status;
                    return (
                        <button
                            key={status}
                            type="button"
                            aria-pressed={active}
                            onClick={() => pick(status)}
                            className={`flex flex-col gap-2 rounded-md border p-4 text-start transition-colors duration-150 ease-standard ${active ? 'border-line-brand bg-brand-subtle' : 'border-line-default bg-surface shadow-e1 hover:bg-subtle'}`}
                        >
                            <span className="flex w-full items-center gap-2">
                                <span aria-hidden className={`grid size-6 shrink-0 place-items-center rounded-full t-admin-caption-strong ${active ? 'bg-brand text-fg-on-brand' : 'bg-subtle text-fg-secondary'}`}>
                                    {index + 1}
                                </span>
                                <span className="min-w-0 flex-1 t-admin-body-strong text-fg-primary">{t(`returns.tab.${status}` as MessageKey)}</span>
                                <span className="t-admin-num-md tabular text-fg-primary">{counts?.[status] ?? 0}</span>
                            </span>
                            <span className="flex items-center gap-1 t-admin-caption text-fg-secondary">
                                <Icon size={14} aria-hidden className={`shrink-0 ${active ? 'text-fg-brand' : 'text-fg-tertiary'}`} />
                                {t(`returns.step.hint.${status}` as MessageKey)}
                            </span>
                        </button>
                    );
                })}
                <div className="flex flex-col gap-1 rounded-md border border-line-default bg-surface p-4 shadow-e1">
                    <p className="t-admin-overline text-fg-tertiary uppercase">{t('returns.finished')}</p>
                    {finished.map(({ status, icon: Icon, tint }) => (
                        <button
                            key={status}
                            type="button"
                            aria-pressed={tab === status}
                            onClick={() => pick(status)}
                            className="-mx-2 flex items-center gap-2 rounded-sm px-2 py-1 text-start hover:bg-subtle aria-pressed:bg-brand-subtle"
                        >
                            <Icon size={16} aria-hidden className={`shrink-0 ${tint}`} />
                            <span className="min-w-0 flex-1 t-admin-body text-fg-primary">{t(`returns.tab.${status}` as MessageKey)}</span>
                            <span className="t-admin-num tabular text-fg-secondary">{counts?.[status] ?? 0}</span>
                        </button>
                    ))}
                </div>
            </div>

            <SearchField label={t('returns.searchLabel')} placeholder={t('returns.searchPlaceholder')} value={search} onChange={(e) => setSearch(e.target.value)} className="w-full sm:w-85" />

            {returns.isError ? (
                <QueryError error={returns.error} onRetry={() => void returns.refetch()} />
            ) : returns.isPending ? (
                <p className="t-admin-body text-fg-tertiary" aria-busy="true">
                    {t('common.loading')}
                </p>
            ) : rows.length === 0 ? (
                <EmptyState reason="allClear" title={t('returns.empty')} body={t(`returns.emptyBody.${tab}` as MessageKey)} />
            ) : (
                <div className={dataTable.card}>
                    <table className={`${dataTable.table} lg:table-fixed`}>
                        <thead className={`hidden lg:table-header-group ${dataTable.head}`}>
                            <tr className="border-b border-line-strong">
                                <th scope="col" className={`${dataTable.th} w-37.5`}>{t('returns.col.return')}</th>
                                <th scope="col" className={`${dataTable.th} w-49`}>{t('returns.col.order')}</th>
                                <th scope="col" className={dataTable.th}>{t('returns.col.why')}</th>
                                <th scope="col" className={`${dataTable.th} w-54`}>{t('returns.col.items')}</th>
                                <th scope="col" className={`${dataTable.th} w-36 text-end`}>{t('returns.col.value')}</th>
                                <th scope="col" className={`${dataTable.th} w-30`}>{t('returns.col.waiting')}</th>
                                <th scope="col" className={`${dataTable.th} w-40 text-end`}>{t('returns.col.next')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map((row) => (
                                <QueueRow key={row.id} row={row} />
                            ))}
                        </tbody>
                    </table>
                    {tab === 'requested' && (
                        <p className="flex items-center gap-2 border-t border-line-default px-4 py-3 t-admin-caption text-fg-secondary">
                            <InfoIcon size={16} aria-hidden className="shrink-0 text-fg-tertiary" />
                            {t('returns.requestedHint')}
                        </p>
                    )}
                    {(returns.data?.meta.prev_cursor || returns.data?.meta.next_cursor) && (
                        <DataTableFooter summary={t('returns.footer', { count: rows.length })}>
                            <Button variant="outline" size="sm" disabled={!returns.data?.meta.prev_cursor} onClick={() => set({ cursor: returns.data?.meta.prev_cursor ?? '' })}>
                                <ArrowLeftIcon size={16} aria-hidden />
                                {t('returns.newer')}
                            </Button>
                            <Button variant="outline" size="sm" disabled={!returns.data?.meta.next_cursor} onClick={() => set({ cursor: returns.data?.meta.next_cursor ?? '' })}>
                                {t('returns.older')}
                                <ArrowRightIcon size={16} aria-hidden />
                            </Button>
                        </DataTableFooter>
                    )}
                </div>
            )}
        </section>
    );
}

function QueueRow({ row }: { row: OrderReturn }) {
    const { t, locale } = useI18n();
    const navigate = useNavigate();
    const to = `/returns/${row.id}`;
    const age = ageParts(row.created_at);
    const long = row.status !== 'closed' && row.status !== 'rejected' && daysOpen(row.created_at) >= LONG_WAIT_DAYS;
    const name = (i: OrderReturn['items'][number]) => (locale === 'bn' && i.product_name_bn ? i.product_name_bn : i.product_name);
    const first = row.items[0];
    const action = nextAction(row);
    const cell = `${dataTable.td.replace('h-15', 'h-auto')} min-h-14 py-3 max-lg:h-auto max-lg:p-0`;

    return (
        <tr
            className={`relative flex cursor-pointer flex-wrap items-center gap-x-4 gap-y-2 px-4 py-3 first:border-t-0 lg:table-row lg:p-0 lg:first:border-t ${dataTable.row}`}
            onClick={(e) => {
                // A click on the row opens the return; links and buttons inside keep their own behaviour.
                if (!(e.target as HTMLElement).closest('a, button')) void navigate(to);
            }}
        >
            <td className={cell}>
                <Link to={to} className="t-admin-mono whitespace-nowrap text-fg-link hover:underline">
                    {row.number}
                </Link>
                {row.created_at && <p className="t-admin-caption text-fg-tertiary">{t('returns.opened', { date: formatDate(row.created_at, locale) })}</p>}
            </td>
            <td className={`min-w-0 ${cell}`}>
                <p className="truncate t-admin-body-strong text-fg-primary">{row.order.number}</p>
                <p className="truncate t-admin-caption text-fg-tertiary">{row.order.customer_name}</p>
            </td>
            <td className={`min-w-0 ${cell}`}>
                <p className="truncate t-admin-body-strong text-fg-primary">{t(reasonLabel(row.reason))}</p>
                {row.within_window === false ? (
                    <span className="mt-1 inline-flex">
                        <StatusChip label={t('returns.late', { days: row.window_days })} tone="warning" />
                    </span>
                ) : (
                    row.reason_note && <p className="truncate t-admin-caption text-fg-tertiary">{row.reason_note}</p>
                )}
            </td>
            <td className={`min-w-0 ${cell}`}>
                {first && (
                    <div className="flex min-w-0 items-center gap-2">
                        <span aria-hidden className="grid size-8 shrink-0 place-items-center rounded-xs bg-subtle text-fg-tertiary">
                            <ImageSquareIcon size={16} />
                        </span>
                        <div className="min-w-0">
                            <p className="line-clamp-2 t-admin-body text-fg-primary">{name(first)}</p>
                            <p className="t-admin-caption text-fg-tertiary">
                                {t('returns.units', { count: row.items.reduce((sum, i) => sum + i.quantity, 0) })}
                                {row.items.length > 1 && ` · ${t('returns.moreItems', { count: row.items.length - 1 })}`}
                            </p>
                        </div>
                    </div>
                )}
            </td>
            <td className={cell}>
                <div className="flex flex-col gap-1 lg:items-end">
                    <span className="t-admin-num tabular whitespace-nowrap text-fg-primary">
                        <Taka amount={row.value} />
                    </span>
                    <PaymentChip method={row.order.payment_method} />
                </div>
            </td>
            <td className={cell}>
                {long ? (
                    <>
                        <p className="flex items-center gap-1 t-admin-body-strong text-fg-primary">
                            <ClockCounterClockwiseIcon size={14} aria-hidden className="shrink-0 text-feedback-warning" />
                            {age ? t(age.key, age.vars) : '—'}
                        </p>
                        <p className="t-admin-caption text-fg-tertiary">{t('returns.longWait')}</p>
                    </>
                ) : (
                    <p className="t-admin-body tabular text-fg-primary">{age ? t(age.key, age.vars) : '—'}</p>
                )}
            </td>
            <td className={`${cell} lg:text-end`}>
                <Link to={to} className={buttonClass(action === 'returns.next.view' ? 'ghost' : 'outline', 'sm')} aria-label={`${t(action)} — ${row.number}`}>
                    {t(action)}
                </Link>
            </td>
        </tr>
    );
}

function formatDate(iso: string, locale: string): string {
    return new Intl.DateTimeFormat(locale === 'bn' ? 'bn-BD' : 'en-GB', { timeZone: 'Asia/Dhaka', day: '2-digit', month: 'short', numberingSystem: 'latn' }).format(new Date(iso));
}
