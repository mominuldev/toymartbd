import { useEffect, useMemo, useState, type ReactNode } from 'react';
import { Link, useSearchParams } from 'react-router';
import { ExportIcon, ImageIcon, PlusIcon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { can, useMe } from '@/features/auth/api';
import { Button, buttonClass } from '@/components/ui/Button';
import { ContentHeader } from '@/components/shell/ContentHeader';
import { DataTableFooter, dataTable } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { QueryError } from '@/components/ui/QueryError';
import { SearchField } from '@/components/ui/SearchField';
import { Select } from '@/components/ui/Select';
import { TabButton } from '@/components/ui/Tabs';
import { formatDhakaDate } from '@/lib/format';
import { flatten, useCategoryTree } from '../categories/api';
import { productSorts, productTypes, useBrandOptions, useProducts, type ProductFilters, type ProductListItem, type ProductSort, type ProductStatus, type ProductType } from './api';
import { formatMoney } from './document';
import { ProductStatusChip, Taka } from './parts';
import { ImportExportDialog } from './importExport/ImportExportDialog';

const statusTabs: { value: ProductStatus | ''; label: MessageKey }[] = [
    { value: '', label: 'common.all' },
    { value: 'active', label: 'products.status.active' },
    { value: 'draft', label: 'products.status.drafts' },
    { value: 'archived', label: 'products.status.archived' },
];
const typeLabel: Record<ProductType, MessageKey> = { simple: 'products.type.simple', variable: 'products.type.variable' };
const sortLabel: Record<ProductSort, MessageKey> = {
    '-updated_at': 'products.sort.updatedDesc',
    updated_at: 'products.sort.updatedAsc',
    name: 'products.sort.nameAsc',
    '-name': 'products.sort.nameDesc',
    '-created_at': 'products.sort.createdDesc',
    created_at: 'products.sort.createdAsc',
};

function useDebounced<T>(value: T, ms = 250): T {
    const [debounced, setDebounced] = useState(value);
    useEffect(() => {
        const id = window.setTimeout(() => setDebounced(value), ms);
        return () => window.clearTimeout(id);
    }, [value, ms]);
    return debounced;
}

function readFilters(params: URLSearchParams): ProductFilters {
    const status = params.get('status');
    const type = params.get('type');
    const sort = params.get('sort');
    const id = (key: string) => (/^\d+$/.test(params.get(key) ?? '') ? params.get(key)! : '');
    return {
        search: params.get('q') ?? '',
        status: status === 'active' || status === 'draft' || status === 'archived' ? status : '',
        type: type === 'simple' || type === 'variable' ? type : '',
        brand: id('brand'),
        category: id('category'),
        sort: productSorts.includes(sort as ProductSort) ? (sort as ProductSort) : '-updated_at',
    };
}

/** "৳1,250" or "৳1,250 – ৳2,400" from the server's price_min / price_max — shown as given, never computed. */
function priceRange(item: ProductListItem): ReactNode {
    if (item.price_min === null || item.price_max === null) return null;
    const min = <Taka amount={formatMoney(item.price_min)} />;
    return item.price_min === item.price_max ? (
        min
    ) : (
        <>
            {min} – <Taka amount={formatMoney(item.price_max)} />
        </>
    );
}

/**
 * Products list (Figma "B · 11 Products" 241:37251): ContentHeader, status AdminTabs,
 * SearchField + AdminSelect filters, DataTable 192:532. Every filter lives in the URL
 * (`?status=draft&brand=3&q=lego`); the API does the filtering, sorting and cursor paging.
 */
export function ProductsPage() {
    const { t, locale } = useI18n();
    const bn = locale === 'bn';
    const { data: me } = useMe();
    const [params, setParams] = useSearchParams();
    const filters = readFilters(params);
    const [search, setSearch] = useState(filters.search);
    const debounced = useDebounced(search.trim());
    const query = useProducts({ ...filters, search: debounced });
    const products = useMemo(() => query.data?.pages.flatMap((page) => page.data) ?? [], [query.data]);
    const categories = useCategoryTree();
    const brands = useBrandOptions();
    const flatCategories = useMemo(() => flatten(categories.data ?? []), [categories.data]);
    const categoryName = (id: number) => {
        const node = flatCategories.find((c) => c.node.id === id)?.node;
        return node ? (bn ? (node.name_bn ?? node.name) : node.name) : '';
    };

    const setFilter = (key: string, value: string) =>
        setParams(
            (current) => {
                const next = new URLSearchParams(current);
                if (value) next.set(key, value);
                else next.delete(key);
                return next;
            },
            { replace: true },
        );

    useEffect(() => {
        if (debounced !== filters.search) setFilter('q', debounced);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [debounced]);

    const filtered = Boolean(filters.search || filters.type || filters.brand || filters.category || filters.status);
    const addProduct = can(me, 'catalog.create') && (
        <Link to="/products/new" className={buttonClass('primary')}>
            <PlusIcon size={16} aria-hidden />
            {t('products.new')}
        </Link>
    );
    const cell = `${dataTable.td} max-lg:h-auto max-lg:p-0`;
    const [ioOpen, setIoOpen] = useState(false);
    // Figma 241:37251: the outline "Import / export" AdminButton sits before "Add product".
    const headerActions = (
        <>
            <Button variant="outline" onClick={() => setIoOpen(true)}>
                <ExportIcon size={16} aria-hidden />
                {t('productIo.button')}
            </Button>
            {addProduct}
        </>
    );

    return (
        <section className="flex flex-col gap-6">
            <ContentHeader
                breadcrumb={[{ label: t('catalog.crumb') }, { label: t('catalog.tab.products') }]}
                title={t('catalog.tab.products')}
                subtitle={t('products.subtitle')}
                actions={headerActions}
            />
            <ImportExportDialog open={ioOpen} onClose={() => setIoOpen(false)} filters={{ ...filters, search: debounced }} canImport={can(me, 'catalog.import')} />

            <div role="group" aria-label={t('products.statusFilter')} className="scrollbar-none flex overflow-x-auto border-b border-line-default">
                {statusTabs.map((tab) => (
                    <TabButton key={tab.value || 'all'} active={filters.status === tab.value} label={t(tab.label)} onClick={() => setFilter('status', tab.value)} />
                ))}
            </div>

            <div className="flex flex-wrap items-center gap-2">
                <SearchField
                    label={t('products.searchLabel')}
                    value={search}
                    onChange={(e) => setSearch(e.target.value)}
                    placeholder={t('products.searchPlaceholder')}
                    className="w-full md:w-80"
                />
                <FilterSelect label={t('products.filter.category')} value={filters.category} onChange={(v) => setFilter('category', v)}>
                    <option value="">{t('products.filter.categoryAll')}</option>
                    {flatCategories.map(({ node, depth }) => (
                        <option key={node.id} value={node.id}>
                            {`${'— '.repeat(depth)}${bn ? (node.name_bn ?? node.name) : node.name}`}
                        </option>
                    ))}
                </FilterSelect>
                <FilterSelect label={t('products.filter.brand')} value={filters.brand} onChange={(v) => setFilter('brand', v)}>
                    <option value="">{t('products.filter.brandAll')}</option>
                    {(brands.data ?? []).map((brand) => (
                        <option key={brand.id} value={brand.id}>
                            {bn ? (brand.name_bn ?? brand.name) : brand.name}
                        </option>
                    ))}
                </FilterSelect>
                <FilterSelect label={t('products.filter.type')} value={filters.type} onChange={(v) => setFilter('type', v)}>
                    <option value="">{t('products.filter.typeAll')}</option>
                    {productTypes.map((type) => (
                        <option key={type} value={type}>
                            {t(typeLabel[type])}
                        </option>
                    ))}
                </FilterSelect>
                <FilterSelect label={t('products.filter.sort')} value={filters.sort} onChange={(v) => setFilter('sort', v === '-updated_at' ? '' : v)}>
                    {productSorts.map((sort) => (
                        <option key={sort} value={sort}>
                            {t(sortLabel[sort])}
                        </option>
                    ))}
                </FilterSelect>
            </div>

            {query.isError ? (
                <QueryError error={query.error} onRetry={() => void query.refetch()} />
            ) : query.isPending ? (
                <p className="t-admin-body text-fg-tertiary" aria-busy="true">
                    {t('common.loading')}
                </p>
            ) : products.length === 0 ? (
                filtered ? (
                    <EmptyState reason="allClear" title={t('products.emptyFiltered')} body={t('products.emptyFilteredBody')} />
                ) : (
                    <EmptyState title={t('products.empty')} body={t('products.emptyBody')} actions={addProduct} />
                )
            ) : (
                <div className={dataTable.card}>
                    <table className={dataTable.table}>
                        <thead className={`hidden lg:table-header-group ${dataTable.head}`}>
                            <tr>
                                <Th>{t('products.col.product')}</Th>
                                <Th>{t('products.col.category')}</Th>
                                <Th>{t('products.col.brand')}</Th>
                                <Th>{t('products.col.type')}</Th>
                                <Th end>{t('products.col.variants')}</Th>
                                <Th end>{t('products.col.price')}</Th>
                                <Th>{t('products.col.status')}</Th>
                                <Th>{t('products.col.updated')}</Th>
                            </tr>
                        </thead>
                        <tbody>
                            {products.map((item) => {
                                const price = priceRange(item);
                                return (
                                    <tr key={item.id} className={`flex flex-wrap items-center gap-x-3 gap-y-1 px-4 py-3 first:border-t-0 lg:table-row lg:p-0 lg:first:border-t ${dataTable.row}`}>
                                        <td className={`min-w-0 basis-full lg:basis-auto ${cell}`}>
                                            <div className="flex items-center gap-2.5">
                                                {item.image_url ? (
                                                    <img src={item.image_url} alt="" className="size-11 shrink-0 rounded-xs-plus object-cover" />
                                                ) : (
                                                    <span aria-hidden className="grid size-11 shrink-0 place-items-center rounded-xs-plus bg-subtle text-fg-tertiary">
                                                        <ImageIcon size={18} />
                                                    </span>
                                                )}
                                                <div className="flex min-w-0 flex-col gap-0.5">
                                                    <Link to={`/products/${item.id}`} className="t-admin-body-strong text-fg-primary hover:underline">
                                                        {item.name}
                                                    </Link>
                                                    {item.name_bn && (
                                                        <p lang="bn" className="t-admin-caption text-fg-secondary">
                                                            {item.name_bn}
                                                        </p>
                                                    )}
                                                    {item.sku && <p className="t-admin-mono-sm text-fg-tertiary">{item.sku}</p>}
                                                </div>
                                            </div>
                                        </td>
                                        <td className={`${cell} t-admin-body`}>{categoryName(item.primary_category_id)}</td>
                                        <td className={`${cell} t-admin-body text-fg-secondary`}>{item.brand?.name ?? '—'}</td>
                                        <td className={`${cell} t-admin-body text-fg-secondary`}>{t(typeLabel[item.type])}</td>
                                        <td className={`${cell} t-admin-body tabular lg:text-end`}>{item.variants_count}</td>
                                        <td className={`${cell} t-admin-num tabular whitespace-nowrap lg:text-end`}>{price ?? <span className="text-fg-tertiary">—</span>}</td>
                                        <td className={cell}>
                                            <ProductStatusChip status={item.status} />
                                        </td>
                                        <td className={`${cell} t-admin-caption tabular whitespace-nowrap text-fg-secondary`}>{formatDhakaDate(item.updated_at, locale)}</td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                    <DataTableFooter summary={t('products.count', { count: products.length })}>
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

function Th({ children, end }: { children: ReactNode; end?: boolean }) {
    return (
        <th scope="col" className={end ? dataTable.th.replace('text-start', 'text-end') : dataTable.th}>
            {children}
        </th>
    );
}

function FilterSelect({ label, value, onChange, children }: { label: string; value: string; onChange: (value: string) => void; children: ReactNode }) {
    return (
        <label className="flex">
            <span className="sr-only">{label}</span>
            <Select size="sm" value={value} onChange={(e) => onChange(e.target.value)} className="w-40">
                {children}
            </Select>
        </label>
    );
}
