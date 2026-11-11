import { Link } from 'react-router';
import { PlusIcon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import { can, useMe } from '@/features/auth/api';
import { QueryError } from '@/components/ui/QueryError';
import { buttonClass } from '@/components/ui/Button';
import { EmptyState } from '@/components/ui/EmptyState';
import { dataTable } from '@/components/ui/DataTable';
import { ContentHeader } from '@/components/shell/ContentHeader';

/** Cells stack as a card below 768 and become DataTable cells above. */
const cell = `${dataTable.td} max-md:h-auto max-md:p-0`;
import { useAttributes } from './api';
import { Swatch } from './Swatch';

/**
 * Attribute list (P4-CAT-09) — few rows, unpaginated, in display order. No dedicated Figma
 * screen: follows the Theme B Products list 241:37251 (ContentHeader + DataTable 192:532).
 */
export function AttributesPage() {
    const { t } = useI18n();
    const { data: me } = useMe();
    const query = useAttributes();
    const newAttribute = can(me, 'catalog.create') && (
        <Link to="/products/attributes/new" className={buttonClass('primary')}>
            <PlusIcon size={16} aria-hidden />
            {t('attributes.new')}
        </Link>
    );

    return (
        <section className="flex flex-col gap-6">
            <ContentHeader
                breadcrumb={[{ label: t('catalog.crumb') }, { label: t('nav.products'), to: '/products' }, { label: t('attributes.title') }]}
                title={t('attributes.title')}
                actions={newAttribute}
            />

            {query.isError ? (
                <QueryError error={query.error} onRetry={() => void query.refetch()} />
            ) : query.isPending ? (
                <p className="t-admin-body text-fg-tertiary" aria-busy="true">
                    {t('common.loading')}
                </p>
            ) : query.data.length === 0 ? (
                <EmptyState title={t('attributes.empty')} body={t('attributes.emptyBody')} actions={newAttribute} />
            ) : (
                <div className={dataTable.card}>
                    <table className={dataTable.table}>
                        <thead className={`hidden md:table-header-group ${dataTable.head}`}>
                            <tr>
                                <th scope="col" className={dataTable.th}>{t('attributes.col.name')}</th>
                                <th scope="col" className={dataTable.th}>{t('attributes.col.type')}</th>
                                <th scope="col" className={dataTable.th}>{t('attributes.col.values')}</th>
                                <th scope="col" className={dataTable.th}>{t('attributes.col.usage')}</th>
                                <th scope="col" className={dataTable.th}><span className="sr-only">{t('common.actions')}</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            {query.data.map((attribute) => (
                                <tr key={attribute.id} className={`flex flex-wrap items-center gap-x-3 gap-y-1 px-4 py-3 first:border-t-0 md:table-row md:p-0 md:first:border-t ${dataTable.row}`}>
                                    <td className={`min-w-0 flex-1 basis-full md:basis-auto ${cell}`}>
                                        <Link to={`/products/attributes/${attribute.id}`} className="t-admin-body-strong text-fg-primary hover:underline">
                                            {attribute.name}
                                        </Link>
                                        {attribute.name_bn && (
                                            <p lang="bn" className="t-admin-caption text-fg-tertiary">
                                                {attribute.name_bn}
                                            </p>
                                        )}
                                    </td>
                                    <td className={`${cell} t-admin-body text-fg-secondary`}>{t(`attributes.type.${attribute.type}`)}</td>
                                    <td className={cell}>
                                        <div className="flex flex-wrap items-center gap-1.5">
                                            {attribute.type === 'color' &&
                                                attribute.values.slice(0, 8).map((value) => <Swatch key={value.id} hex={value.color_hex} label={t('attributes.values.swatch', { name: value.value })} />)}
                                            <span className="t-admin-caption text-fg-tertiary">{t('attributes.valueCount', { count: attribute.values.length })}</span>
                                        </div>
                                    </td>
                                    <td className={`${cell} t-admin-caption text-fg-secondary`}>
                                        {[attribute.is_variant_defining ? t('attributes.variantDefining') : null, attribute.is_filterable ? t('attributes.filterable') : null]
                                            .filter(Boolean)
                                            .join(' · ')}
                                    </td>
                                    <td className={`ms-auto ${cell} md:text-end`}>
                                        {can(me, 'catalog.update') && (
                                            <Link to={`/products/attributes/${attribute.id}`} className={buttonClass('ghost', 'sm')}>
                                                {t('common.edit')}
                                                <span className="sr-only"> {attribute.name}</span>
                                            </Link>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </section>
    );
}
