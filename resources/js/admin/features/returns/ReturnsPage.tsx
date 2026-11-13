import { useEffect, useState } from 'react';
import { Link, useSearchParams } from 'react-router';
import { ArrowLeftIcon, ArrowRightIcon, PlusIcon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { ContentHeader } from '@/components/shell/ContentHeader';
import { buttonClass, Button } from '@/components/ui/Button';
import { CountBadge } from '@/components/ui/CountBadge';
import { DataTableFooter, dataTable } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { QueryError } from '@/components/ui/QueryError';
import { SearchField } from '@/components/ui/SearchField';
import { TabList, tabClass } from '@/components/ui/Tabs';
import { returnTabs, useReturns, type OrderReturn, type ReturnStatus } from './api';
import { ReturnDetail } from './ReturnDetail';
import { Taka, WantsChip, ageParts, reasonLabel } from './parts';

/**
 * Returns, refunds & exchanges (Figma "B · 10" 241:35772, Theme B): ContentHeader, status
 * AdminTabs with server counts, then a split — the 740-wide queue (RMA · order and
 * customer · reason · wants · value · age; the selected row bg/brand-subtle) and the
 * detail panel (inspection, resolution, actions). Tab, search, cursor and the selected RMA
 * live in the URL. Exchanges and store credit are not built yet, so every row wants a refund.
 */
export function ReturnsPage() {
    const { t } = useI18n();
    const [params, setParams] = useSearchParams();
    const tab = (returnTabs.includes(params.get('tab') as ReturnStatus) ? params.get('tab') : 'requested') as ReturnStatus;
    const cursor = params.get('cursor');
    const selected = Number(params.get('rma')) || null;
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

    const rows = returns.data?.data ?? [];
    const counts = returns.data?.meta.counts;

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

            <TabList label={t('returns.tabs')}>
                {returnTabs.map((value) => (
                    <button key={value} type="button" aria-pressed={tab === value} onClick={() => set({ tab: value === 'requested' ? '' : value, cursor: '', rma: '' })} className={tabClass(tab === value)}>
                        {t(`returns.tab.${value}` as MessageKey)}
                        <CountBadge count={counts?.[value] ?? 0} />
                    </button>
                ))}
            </TabList>

            <SearchField label={t('returns.searchLabel')} placeholder={t('returns.searchPlaceholder')} value={search} onChange={(e) => setSearch(e.target.value)} className="w-full sm:w-85" />

            <div className="flex flex-col items-start gap-6 xl:flex-row">
                <div className="w-full min-w-0 xl:w-185 xl:shrink-0">
                    {returns.isError ? (
                        <QueryError error={returns.error} onRetry={() => void returns.refetch()} />
                    ) : returns.isPending ? (
                        <p className="t-admin-body text-fg-tertiary" aria-busy="true">
                            {t('common.loading')}
                        </p>
                    ) : rows.length === 0 ? (
                        <EmptyState reason="allClear" title={t('returns.empty')} body={t('returns.emptyBody')} />
                    ) : (
                        <div className={dataTable.card}>
                            <table className={`${dataTable.table} lg:table-fixed`}>
                                <thead className={`hidden lg:table-header-group ${dataTable.head}`}>
                                    <tr className="border-b border-line-strong">
                                        <th scope="col" className={`${dataTable.th} w-27.5`}>{t('returns.col.rma')}</th>
                                        <th scope="col" className={`${dataTable.th} w-45`}>{t('returns.col.order')}</th>
                                        <th scope="col" className={`${dataTable.th} w-42.5`}>{t('returns.col.reason')}</th>
                                        <th scope="col" className={`${dataTable.th} w-30`}>{t('returns.col.wants')}</th>
                                        <th scope="col" className={`${dataTable.th} w-22 text-end`}>{t('returns.col.value')}</th>
                                        <th scope="col" className={`${dataTable.th} w-18`}>{t('returns.col.age')}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {rows.map((row) => (
                                        <QueueRow key={row.id} row={row} selected={row.id === selected} onSelect={() => set({ rma: String(row.id) })} />
                                    ))}
                                </tbody>
                            </table>
                            {/* Figma's queue has no footer; it appears only when there is another page to reach. */}
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
                </div>
                <div className="w-full min-w-0 flex-1">
                    {selected ? (
                        <ReturnDetail id={selected} />
                    ) : (
                        <EmptyState reason="noDataYet" title={t('returns.pick')} className="border-dashed bg-subtle" />
                    )}
                </div>
            </div>
        </section>
    );
}

function QueueRow({ row, selected, onSelect }: { row: OrderReturn; selected: boolean; onSelect: () => void }) {
    const { t, locale } = useI18n();
    const age = ageParts(row.created_at);
    const first = row.items[0];
    const sub = row.reason_note ?? (first ? (locale === 'bn' && first.product_name_bn ? first.product_name_bn : first.product_name) : '');
    const cell = `${dataTable.td.replace('h-15', 'h-auto')} min-h-14 py-2.5 max-lg:h-auto max-lg:p-0`;

    return (
        <tr
            className={`relative flex cursor-pointer flex-wrap items-center gap-x-4 gap-y-2 px-4 py-3 first:border-t-0 lg:table-row lg:p-0 lg:first:border-t ${dataTable.row} ${selected ? 'bg-brand-subtle hover:bg-brand-subtle' : ''}`}
            aria-selected={selected}
            onClick={onSelect}
        >
            <td className={cell}>
                <button type="button" onClick={onSelect} aria-label={t('returns.select', { number: row.number })} className="t-admin-mono whitespace-nowrap text-fg-primary hover:text-fg-link hover:underline">
                    {row.number}
                </button>
            </td>
            <td className={`min-w-0 ${cell}`}>
                <p className="truncate t-admin-body-strong text-fg-primary">{row.order.number}</p>
                <p className="truncate t-admin-caption text-fg-tertiary">{row.order.customer_name}</p>
            </td>
            <td className={`min-w-0 ${cell}`}>
                <p className="truncate t-admin-body-strong text-fg-primary">{t(reasonLabel(row.reason))}</p>
                {sub && <p className="truncate t-admin-caption text-fg-tertiary">{sub}</p>}
            </td>
            <td className={cell}>
                <WantsChip label={t('returns.wants.refund')} />
            </td>
            <td className={`${cell} t-admin-num tabular whitespace-nowrap lg:text-end`}>
                <Taka amount={row.value} />
            </td>
            <td className={`${cell} t-admin-body tabular`}>{age ? t(age.key, age.vars) : '—'}</td>
        </tr>
    );
}
