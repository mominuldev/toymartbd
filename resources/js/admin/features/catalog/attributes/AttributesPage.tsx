import { Link } from 'react-router';
import { PlusIcon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import { can, useMe } from '@/features/auth/api';
import { QueryError } from '@/components/ui/QueryError';
import { useAttributes } from './api';
import { Swatch } from './Swatch';

/** Attribute list (P4-CAT-09) — few rows, unpaginated, in display order. */
export function AttributesPage() {
    const { t } = useI18n();
    const { data: me } = useMe();
    const query = useAttributes();

    return (
        <section className="space-y-5">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <h1 className="t-admin-page-title text-fg-primary">{t('attributes.title')}</h1>
                {can(me, 'catalog.create') && (
                    <Link to="/products/attributes/new" className="inline-flex h-control-md items-center gap-2 rounded-sm bg-brand px-4 t-admin-button text-fg-on-brand hover:bg-brand-shelf">
                        <PlusIcon size={18} aria-hidden />
                        {t('attributes.new')}
                    </Link>
                )}
            </div>

            {query.isError ? (
                <QueryError error={query.error} onRetry={() => void query.refetch()} />
            ) : query.isPending ? (
                <p className="t-admin-body text-fg-tertiary" aria-busy="true">
                    {t('common.loading')}
                </p>
            ) : query.data.length === 0 ? (
                <div className="rounded-md border border-line-default bg-surface p-8 text-center shadow-e1">
                    <p className="t-admin-section text-fg-primary">{t('attributes.empty')}</p>
                    <p className="t-admin-body text-fg-secondary">{t('attributes.emptyBody')}</p>
                </div>
            ) : (
                <div className="overflow-hidden rounded-md border border-line-default bg-surface shadow-e1">
                    <table className="w-full border-collapse">
                        <thead className="hidden bg-subtle md:table-header-group">
                            <tr className="text-left">
                                <th scope="col" className="px-4 py-2.5 t-admin-overline text-fg-tertiary">{t('attributes.col.name')}</th>
                                <th scope="col" className="px-4 py-2.5 t-admin-overline text-fg-tertiary">{t('attributes.col.type')}</th>
                                <th scope="col" className="px-4 py-2.5 t-admin-overline text-fg-tertiary">{t('attributes.col.values')}</th>
                                <th scope="col" className="px-4 py-2.5 t-admin-overline text-fg-tertiary">{t('attributes.col.usage')}</th>
                                <th scope="col" className="px-4 py-2.5"><span className="sr-only">{t('common.actions')}</span></th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-line-default">
                            {query.data.map((attribute) => (
                                <tr key={attribute.id} className="flex flex-wrap items-center gap-x-3 gap-y-1 px-4 py-3 hover:bg-subtle md:table-row md:p-0">
                                    <td className="min-w-0 flex-1 basis-full md:px-4 md:py-3">
                                        <Link to={`/products/attributes/${attribute.id}`} className="t-admin-body-strong text-fg-primary hover:underline">
                                            {attribute.name}
                                        </Link>
                                        {attribute.name_bn && (
                                            <p lang="bn" className="t-admin-caption text-fg-tertiary">
                                                {attribute.name_bn}
                                            </p>
                                        )}
                                    </td>
                                    <td className="t-admin-body text-fg-secondary md:px-4 md:py-3">{t(`attributes.type.${attribute.type}`)}</td>
                                    <td className="md:px-4 md:py-3">
                                        <div className="flex flex-wrap items-center gap-1.5">
                                            {attribute.type === 'color' &&
                                                attribute.values.slice(0, 8).map((value) => <Swatch key={value.id} hex={value.color_hex} label={t('attributes.values.swatch', { name: value.value })} />)}
                                            <span className="t-admin-caption text-fg-tertiary">{t('attributes.valueCount', { count: attribute.values.length })}</span>
                                        </div>
                                    </td>
                                    <td className="t-admin-caption text-fg-secondary md:px-4 md:py-3">
                                        {[attribute.is_variant_defining ? t('attributes.variantDefining') : null, attribute.is_filterable ? t('attributes.filterable') : null]
                                            .filter(Boolean)
                                            .join(' · ')}
                                    </td>
                                    <td className="ml-auto md:px-4 md:py-3 md:text-right">
                                        {can(me, 'catalog.update') && (
                                            <Link to={`/products/attributes/${attribute.id}`} className="t-admin-button-sm text-fg-link hover:underline">
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
