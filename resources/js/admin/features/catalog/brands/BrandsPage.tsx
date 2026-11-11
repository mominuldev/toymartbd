import { useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router';
import { MagnifyingGlassIcon, PlusIcon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import { can, useMe } from '@/features/auth/api';
import { Button } from '@/components/ui/Button';
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

/** Brand list — Figma DataTable 192:532 at ≥768px, OrderCard-style rows below. */
export function BrandsPage() {
    const { t, locale } = useI18n();
    const { data: me } = useMe();
    const [search, setSearch] = useState('');
    const [status, setStatus] = useState<BrandStatus>('active');
    const query = useBrands(useDebounced(search.trim()), status);
    const brands = useMemo(() => query.data?.pages.flatMap((page) => page.data) ?? [], [query.data]);

    return (
        <section className="space-y-5">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <h1 className="t-admin-page-title text-fg-primary">{t('brands.title')}</h1>
                {can(me, 'catalog.create') && (
                    <Link to="/products/brands/new" className="inline-flex h-control-md items-center gap-2 rounded-sm bg-brand px-4 t-admin-button text-fg-on-brand hover:bg-brand-shelf">
                        <PlusIcon size={18} aria-hidden />
                        {t('brands.new')}
                    </Link>
                )}
            </div>

            <div className="flex flex-wrap items-center gap-3">
                <label className="flex h-control-sm min-w-0 flex-1 basis-60 items-center gap-2 rounded-sm border border-line-strong bg-surface px-3 text-fg-tertiary focus-within:shadow-focus md:max-w-80">
                    <MagnifyingGlassIcon size={16} aria-hidden className="shrink-0" />
                    <span className="sr-only">{t('brands.searchLabel')}</span>
                    <input
                        type="search"
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        placeholder={t('brands.searchPlaceholder')}
                        className="min-w-0 flex-1 bg-transparent t-admin-body text-fg-primary outline-none placeholder:text-fg-tertiary"
                    />
                </label>
                <div role="group" aria-label={t('brands.statusFilter')} className="flex h-control-sm items-center rounded-sm bg-subtle p-0.5">
                    {statuses.map((option) => (
                        <button
                            key={option.value}
                            type="button"
                            aria-pressed={status === option.value}
                            onClick={() => setStatus(option.value)}
                            className="h-full rounded-xs px-3 t-admin-button-sm text-fg-secondary aria-pressed:bg-surface aria-pressed:text-fg-primary aria-pressed:shadow-e1"
                        >
                            {t(option.label)}
                        </button>
                    ))}
                </div>
            </div>

            {query.isError ? (
                <QueryError error={query.error} onRetry={() => void query.refetch()} />
            ) : query.isPending ? (
                <p className="t-admin-body text-fg-tertiary" aria-busy="true">
                    {t('common.loading')}
                </p>
            ) : brands.length === 0 ? (
                <div className="rounded-md border border-line-default bg-surface p-8 text-center shadow-e1">
                    <p className="t-admin-section text-fg-primary">{t('brands.empty')}</p>
                    <p className="t-admin-body text-fg-secondary">{t('brands.emptyBody')}</p>
                </div>
            ) : (
                <div className="overflow-hidden rounded-md border border-line-default bg-surface shadow-e1">
                    <table className="w-full border-collapse">
                        <thead className="hidden bg-subtle md:table-header-group">
                            <tr className="text-left">
                                <th scope="col" className="px-4 py-2.5 t-admin-overline text-fg-tertiary">{t('brands.col.name')}</th>
                                <th scope="col" className="px-4 py-2.5 t-admin-overline text-fg-tertiary">{t('brands.col.slug')}</th>
                                <th scope="col" className="px-4 py-2.5 t-admin-overline text-fg-tertiary">{t('brands.col.status')}</th>
                                <th scope="col" className="px-4 py-2.5 t-admin-overline text-fg-tertiary">{t('brands.col.updated')}</th>
                                <th scope="col" className="px-4 py-2.5"><span className="sr-only">{t('common.actions')}</span></th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-line-default">
                            {brands.map((brand) => (
                                <tr key={brand.id} className="flex flex-wrap items-center gap-x-3 gap-y-1 px-4 py-3 hover:bg-subtle md:table-row md:p-0">
                                    <td className="min-w-0 flex-1 basis-full md:px-4 md:py-3">
                                        <Link to={`/products/brands/${brand.id}`} className="t-admin-body-strong text-fg-primary hover:underline">
                                            {brand.name}
                                        </Link>
                                        {brand.name_bn && (
                                            <p lang="bn" className="t-admin-caption text-fg-tertiary">
                                                {brand.name_bn}
                                            </p>
                                        )}
                                    </td>
                                    <td className="t-admin-mono-sm text-fg-secondary md:px-4 md:py-3">{brand.slug}</td>
                                    <td className="md:px-4 md:py-3">
                                        <StatusChip active={brand.is_active} label={t(brand.is_active ? 'common.active' : 'common.archived')} />
                                    </td>
                                    <td className="t-admin-caption text-fg-tertiary tabular md:px-4 md:py-3">{formatDhakaDate(brand.updated_at, locale)}</td>
                                    <td className="ml-auto md:px-4 md:py-3 md:text-right">
                                        {can(me, 'catalog.update') && (
                                            <Link to={`/products/brands/${brand.id}`} className="t-admin-button-sm text-fg-link hover:underline">
                                                {t('common.edit')}
                                                <span className="sr-only"> {brand.name}</span>
                                            </Link>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}

            {brands.length > 0 && (
                <div className="flex items-center justify-between">
                    <p className="t-admin-caption text-fg-tertiary" aria-live="polite">
                        {t('brands.count', { count: brands.length })}
                    </p>
                    {query.hasNextPage && (
                        <Button variant="secondary" size="sm" onClick={() => void query.fetchNextPage()} disabled={query.isFetchingNextPage}>
                            {query.isFetchingNextPage ? t('common.loading') : t('common.loadMore')}
                        </Button>
                    )}
                </div>
            )}
        </section>
    );
}
