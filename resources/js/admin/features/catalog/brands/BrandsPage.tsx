import { useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router';
import { PlusIcon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import { can, useMe } from '@/features/auth/api';
import { Button, buttonClass } from '@/components/ui/Button';
import { SearchField } from '@/components/ui/SearchField';
import { TabButton } from '@/components/ui/Tabs';
import { EmptyState } from '@/components/ui/EmptyState';
import { DataTableFooter, dataTable } from '@/components/ui/DataTable';
import { ContentHeader } from '@/components/shell/ContentHeader';
import { StatusChip } from '@/components/ui/StatusChip';
import { QueryError } from '@/components/ui/QueryError';
import { formatDhakaDate } from '@/lib/format';
import { useBrands, type BrandStatus } from './api';

const statuses: { value: BrandStatus; label: 'common.all' | 'common.active' | 'common.archived' }[] = [
    { value: 'all', label: 'common.all' },
    { value: 'active', label: 'common.active' },
    { value: 'archived', label: 'common.archived' },
];

function useDebounced<T>(value: T, ms = 250): T {
    const [debounced, setDebounced] = useState(value);
    useEffect(() => {
        const id = window.setTimeout(() => setDebounced(value), ms);
        return () => window.clearTimeout(id);
    }, [value, ms]);
    return debounced;
}

/**
 * Brand list. No dedicated Figma screen: follows the Theme B Products list 241:37251 —
 * ContentHeader, status AdminTabs, SearchField, DataTable 192:532 (card rows below 768).
 */
export function BrandsPage() {
    const { t, locale } = useI18n();
    const { data: me } = useMe();
    const [search, setSearch] = useState('');
    const [status, setStatus] = useState<BrandStatus>('active');
    const query = useBrands(useDebounced(search.trim()), status);
    const brands = useMemo(() => query.data?.pages.flatMap((page) => page.data) ?? [], [query.data]);

    const newBrand = can(me, 'catalog.create') && (
        <Link to="/products/brands/new" className={buttonClass('primary')}>
            <PlusIcon size={16} aria-hidden />
            {t('brands.new')}
        </Link>
    );

    return (
        <section className="flex flex-col gap-6">
            <ContentHeader
                breadcrumb={[{ label: t('catalog.crumb') }, { label: t('nav.products'), to: '/products' }, { label: t('brands.title') }]}
                title={t('brands.title')}
                actions={newBrand}
            />

            <div role="group" aria-label={t('brands.statusFilter')} className="scrollbar-none flex overflow-x-auto border-b border-line-default">
                {statuses.map((option) => (
                    <TabButton key={option.value} active={status === option.value} label={t(option.label)} onClick={() => setStatus(option.value)} />
                ))}
            </div>

            <SearchField
                label={t('brands.searchLabel')}
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                placeholder={t('brands.searchPlaceholder')}
                className="w-full md:w-80"
            />

            {query.isError ? (
                <QueryError error={query.error} onRetry={() => void query.refetch()} />
            ) : query.isPending ? (
                <p className="t-admin-body text-fg-tertiary" aria-busy="true">
                    {t('common.loading')}
                </p>
            ) : brands.length === 0 ? (
                <EmptyState title={t('brands.empty')} body={t('brands.emptyBody')} actions={search.trim() === '' ? newBrand : undefined} />
            ) : (
                <div className={dataTable.card}>
                    <table className={dataTable.table}>
                        <thead className={`hidden md:table-header-group ${dataTable.head}`}>
                            <tr>
                                <th scope="col" className={dataTable.th}>{t('brands.col.name')}</th>
                                <th scope="col" className={dataTable.th}>{t('brands.col.slug')}</th>
                                <th scope="col" className={dataTable.th}>{t('brands.col.status')}</th>
                                <th scope="col" className={dataTable.th}>{t('brands.col.updated')}</th>
                                <th scope="col" className={dataTable.th}><span className="sr-only">{t('common.actions')}</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            {brands.map((brand) => (
                                <tr key={brand.id} className={`flex flex-wrap items-center gap-x-3 gap-y-1 px-4 py-3 first:border-t-0 md:table-row md:p-0 md:first:border-t ${dataTable.row}`}>
                                    <td className={`min-w-0 flex-1 basis-full md:basis-auto ${dataTable.td} max-md:h-auto max-md:p-0`}>
                                        <Link to={`/products/brands/${brand.id}`} className="t-admin-body-strong text-fg-primary hover:underline">
                                            {brand.name}
                                        </Link>
                                        {brand.name_bn && (
                                            <p lang="bn" className="t-admin-caption text-fg-tertiary">
                                                {brand.name_bn}
                                            </p>
                                        )}
                                    </td>
                                    <td className={`${dataTable.td} t-admin-mono-sm text-fg-tertiary max-md:h-auto max-md:p-0`}>{brand.slug}</td>
                                    <td className={`${dataTable.td} max-md:h-auto max-md:p-0`}>
                                        <StatusChip active={brand.is_active} label={t(brand.is_active ? 'common.active' : 'common.archived')} />
                                    </td>
                                    <td className={`${dataTable.td} t-admin-caption text-fg-secondary tabular max-md:h-auto max-md:p-0`}>{formatDhakaDate(brand.updated_at, locale)}</td>
                                    <td className={`ms-auto ${dataTable.td} max-md:h-auto max-md:p-0 md:text-end`}>
                                        {can(me, 'catalog.update') && (
                                            <Link to={`/products/brands/${brand.id}`} className={buttonClass('ghost', 'sm')}>
                                                {t('common.edit')}
                                                <span className="sr-only"> {brand.name}</span>
                                            </Link>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                    <DataTableFooter summary={t('brands.count', { count: brands.length })}>
                        {query.hasNextPage && (
                            <Button variant="outline" size="sm" onClick={() => void query.fetchNextPage()} disabled={query.isFetchingNextPage}>
                                {query.isFetchingNextPage ? t('common.loading') : t('common.loadMore')}
                            </Button>
                        )}
                    </DataTableFooter>
                </div>
            )}
        </section>
    );
}
