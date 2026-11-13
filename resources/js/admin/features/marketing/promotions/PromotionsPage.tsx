import { useMemo } from 'react';
import { Link, useSearchParams } from 'react-router';
import { PlusIcon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { can, useMe } from '@/features/auth/api';
import { ContentHeader } from '@/components/shell/ContentHeader';
import { Button, buttonClass } from '@/components/ui/Button';
import { DataTableFooter, dataTable } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { QueryError } from '@/components/ui/QueryError';
import { SearchField } from '@/components/ui/SearchField';
import { Select } from '@/components/ui/Select';
import { promotionStatuses, promotionTypes, usePromotions } from '../api';
import { MarketingTabs, PromotionStatusChip, crumbs, dhakaDateTime } from '../parts';

/**
 * Automatic promotions, or (`flash`) flash sales only. No Figma frame: the B · 20 list
 * pattern. Order is the engine's: highest priority first.
 */
export function PromotionsPage({ flash = false }: { flash?: boolean }) {
    const { t, locale } = useI18n();
    const me = useMe();
    const manage = can(me.data, 'marketing.manage');
    const [params, setParams] = useSearchParams();
    const filters = { search: params.get('q') ?? '', status: params.get('status') ?? '', type: flash ? 'flash_sale' : (params.get('type') ?? '') };
    const promotions = usePromotions(filters);
    const rows = useMemo(() => promotions.data?.pages.flatMap((p) => p.data) ?? [], [promotions.data]);
    const base = flash ? '/marketing/flash-sales' : '/marketing/promotions';

    const set = (key: string, value: string) =>
        setParams((current) => {
            const next = new URLSearchParams(current);
            if (value) next.set(key, value);
            else next.delete(key);
            return next;
        });

    return (
        <div className="flex flex-col gap-6">
            <ContentHeader
                title={t(flash ? 'mkt.flash.title' : 'mkt.promos.title')}
                breadcrumb={crumbs(t, flash ? 'mkt.tab.flash' : 'mkt.tab.promotions')}
                subtitle={t(flash ? 'mkt.flash.subtitle' : 'mkt.promos.subtitle')}
                actions={
                    manage && (
                        <Link to={`${base}/new`} className={buttonClass('primary')}>
                            <PlusIcon size={18} aria-hidden />
                            {t(flash ? 'mkt.flash.new' : 'mkt.promos.new')}
                        </Link>
                    )
                }
            />
            <MarketingTabs />
            <div className="flex flex-wrap items-end gap-3">
                <SearchField label={t('mkt.search')} placeholder={t('mkt.promos.searchPlaceholder')} value={filters.search} onChange={(e) => set('q', e.target.value)} className="w-full max-w-xs" />
                {!flash && (
                    <label className="flex items-center gap-2 t-admin-caption text-fg-secondary">
                        {t('mkt.promos.typeFilter')}
                        <Select size="sm" value={filters.type} onChange={(e) => set('type', e.target.value)}>
                            <option value="">{t('mkt.all')}</option>
                            {promotionTypes.map((ty) => (
                                <option key={ty} value={ty}>
                                    {t(`mkt.ptype.${ty}` as MessageKey)}
                                </option>
                            ))}
                        </Select>
                    </label>
                )}
                <label className="flex items-center gap-2 t-admin-caption text-fg-secondary">
                    {t('mkt.promos.statusFilter')}
                    <Select size="sm" value={filters.status} onChange={(e) => set('status', e.target.value)}>
                        <option value="">{t('mkt.all')}</option>
                        {promotionStatuses.map((s) => (
                            <option key={s} value={s}>
                                {t(`mkt.status.${s}` as MessageKey)}
                            </option>
                        ))}
                    </Select>
                </label>
            </div>
            {promotions.isError ? (
                <QueryError error={promotions.error} onRetry={() => void promotions.refetch()} />
            ) : promotions.isSuccess && rows.length === 0 ? (
                <EmptyState title={t(flash ? 'mkt.flash.empty.title' : 'mkt.promos.empty.title')} body={t(flash ? 'mkt.flash.empty.body' : 'mkt.promos.empty.body')} />
            ) : (
                <div className={dataTable.card}>
                    <div className="overflow-x-auto">
                        <table className={dataTable.table}>
                            <thead className={dataTable.head}>
                                <tr>
                                    <th className={dataTable.th}>{t('mkt.promos.col.name')}</th>
                                    <th className={dataTable.th}>{t('mkt.promos.col.type')}</th>
                                    <th className={`${dataTable.th} text-end`}>{t('mkt.promos.col.priority')}</th>
                                    <th className={dataTable.th}>{t('mkt.promos.col.window')}</th>
                                    <th className={`${dataTable.th} text-end`}>{t('mkt.promos.col.used')}</th>
                                    <th className={dataTable.th}>{t('mkt.promos.col.status')}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {rows.map((p) => (
                                    <tr key={p.id} className={dataTable.row}>
                                        <td className={dataTable.td}>
                                            <Link to={`${p.type === 'flash_sale' ? '/marketing/flash-sales' : '/marketing/promotions'}/${p.id}`} className="t-admin-body-strong text-fg-link hover:underline">
                                                {locale === 'bn' ? (p.name_bn ?? p.name) : p.name}
                                            </Link>
                                            <span className="block t-admin-caption text-fg-tertiary">
                                                {[p.is_stackable ? t('mkt.promos.stackable') : t('mkt.promos.exclusive'), p.allows_coupons ? null : t('mkt.promos.noCoupons')].filter(Boolean).join(' · ')}
                                            </span>
                                        </td>
                                        <td className={`${dataTable.td} t-admin-body`}>{t(`mkt.ptype.${p.type}` as MessageKey)}</td>
                                        <td className={`${dataTable.td} text-end t-admin-body tabular`}>{p.priority}</td>
                                        <td className={`${dataTable.td} t-admin-caption tabular text-fg-secondary`}>
                                            {p.starts_at || p.ends_at ? `${dhakaDateTime(p.starts_at, locale)} – ${dhakaDateTime(p.ends_at, locale)}` : t('mkt.summary.noWindow')}
                                        </td>
                                        <td className={`${dataTable.td} text-end t-admin-body tabular`}>{p.usage_limit_total === null ? p.usage_count : t('mkt.coupons.usedOf', { used: p.usage_count, limit: p.usage_limit_total })}</td>
                                        <td className={dataTable.td}>
                                            <PromotionStatusChip status={p.status} />
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    <DataTableFooter summary={t('mkt.shown', { count: rows.length })}>
                        {promotions.hasNextPage && (
                            <Button variant="outline" size="sm" onClick={() => void promotions.fetchNextPage()} disabled={promotions.isFetchingNextPage}>
                                {t('mkt.loadMore')}
                            </Button>
                        )}
                    </DataTableFooter>
                </div>
            )}
        </div>
    );
}
