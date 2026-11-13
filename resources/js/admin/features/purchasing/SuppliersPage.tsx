import { useMemo, useState } from 'react';
import { Link, useSearchParams } from 'react-router';
import { PlusIcon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import { can, useMe } from '@/features/auth/api';
import { Button, buttonClass } from '@/components/ui/Button';
import { ContentHeader } from '@/components/shell/ContentHeader';
import { DataTableFooter, dataTable } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { QueryError } from '@/components/ui/QueryError';
import { SearchField } from '@/components/ui/SearchField';
import { StatusChip } from '@/components/ui/StatusChip';
import { TabButton } from '@/components/ui/Tabs';
import { useSuppliers, type SupplierFilters } from './api';
import { CellLabel, Taka, Th, formatUnits, supplierTerms } from './parts';

/**
 * Supplier list (06-admin-feature-checklist §2.7 / I-03; "supplier detail" is listed as not
 * drawn — follows the Theme B Products list 241:37251: ContentHeader, status tabs, search,
 * DataTable 192:532). The payable balance column appears only for finance.view.
 */
export function SuppliersPage() {
    const { t } = useI18n();
    const { data: me } = useMe();
    const [params, setParams] = useSearchParams();
    const status: SupplierFilters['status'] = params.get('status') === 'archived' ? 'archived' : 'active';
    const [search, setSearch] = useState('');
    const list = useSuppliers({ status, search: search.trim() });
    const rows = useMemo(() => list.data?.pages.flatMap((p) => p.data) ?? [], [list.data]);
    const showBalance = can(me, 'finance.view');
    const cell = `${dataTable.td} max-lg:h-auto max-lg:p-0`;

    return (
        <section className="flex flex-col gap-6">
            <ContentHeader
                breadcrumb={[{ label: t('catalog.crumb') }, { label: t('purchasing.crumb'), to: '/purchasing' }, { label: t('suppliers.title') }]}
                title={t('suppliers.title')}
                subtitle={t('suppliers.subtitle')}
                actions={
                    can(me, 'purchasing.manage_suppliers') && (
                        <Link to="/purchasing/suppliers/new" className={buttonClass('primary')}>
                            <PlusIcon size={16} aria-hidden />
                            {t('suppliers.new')}
                        </Link>
                    )
                }
            />

            <div className="flex flex-wrap items-end justify-between gap-3 border-b border-line-default">
                <div role="group" aria-label={t('suppliers.col.status')} className="flex">
                    <TabButton active={status === 'active'} label={t('common.active')} onClick={() => setParams({}, { replace: true })} />
                    <TabButton active={status === 'archived'} label={t('common.archived')} onClick={() => setParams({ status: 'archived' }, { replace: true })} />
                </div>
                <div className="w-full pb-2 sm:w-72">
                    <SearchField label={t('suppliers.search')} placeholder={t('suppliers.search')} value={search} onChange={(e) => setSearch(e.target.value)} />
                </div>
            </div>

            {list.isError ? (
                <QueryError error={list.error} onRetry={() => void list.refetch()} />
            ) : list.isPending ? (
                <p className="t-admin-body text-fg-tertiary" aria-busy="true">
                    {t('common.loading')}
                </p>
            ) : rows.length === 0 ? (
                <EmptyState title={t('suppliers.empty')} body={t('suppliers.emptyBody')} />
            ) : (
                <div className={dataTable.card}>
                    <table className={dataTable.table}>
                        <thead className={`hidden lg:table-header-group ${dataTable.head}`}>
                            <tr className="border-b border-line-strong">
                                <Th>{t('suppliers.col.name')}</Th>
                                <Th>{t('suppliers.col.phone')}</Th>
                                <Th>{t('suppliers.col.terms')}</Th>
                                {showBalance && <Th end>{t('suppliers.col.balance')}</Th>}
                                <Th>{t('suppliers.col.status')}</Th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map((s) => (
                                <tr key={s.id} className={`flex flex-wrap items-center gap-x-4 gap-y-1 px-4 py-3 first:border-t-0 lg:table-row lg:p-0 lg:first:border-t ${dataTable.row}`}>
                                    <td className={`${cell} basis-full lg:basis-auto`}>
                                        <Link to={`/purchasing/suppliers/${s.id}`} className="t-admin-body-strong text-fg-primary hover:underline">
                                            {s.name}
                                        </Link>
                                        {s.contact_person && <p className="t-admin-caption text-fg-tertiary">{s.contact_person}</p>}
                                    </td>
                                    <td className={`${cell} t-admin-mono-sm whitespace-nowrap text-fg-secondary`}>
                                        <CellLabel text={t('suppliers.col.phone')} />
                                        {s.phone ?? '—'}
                                    </td>
                                    <td className={`${cell} t-admin-body text-fg-secondary`}>{supplierTerms(s.payment_terms_days, t)}</td>
                                    {showBalance && (
                                        <td className={`${cell} t-admin-num tabular whitespace-nowrap lg:text-end`}>
                                            <CellLabel text={t('suppliers.col.balance')} />
                                            {s.balance ? <Taka amount={s.balance.balance} /> : '—'}
                                        </td>
                                    )}
                                    <td className={cell}>
                                        <StatusChip label={t(s.status === 'active' ? 'common.active' : 'common.archived')} active={s.status === 'active'} />
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                    {list.hasNextPage && (
                        <DataTableFooter summary={t('suppliers.count', { count: formatUnits(rows.length) })}>
                            <Button variant="outline" size="sm" onClick={() => void list.fetchNextPage()} disabled={list.isFetchingNextPage}>
                                {list.isFetchingNextPage ? t('common.loading') : t('common.loadMore')}
                            </Button>
                        </DataTableFooter>
                    )}
                </div>
            )}
        </section>
    );
}
