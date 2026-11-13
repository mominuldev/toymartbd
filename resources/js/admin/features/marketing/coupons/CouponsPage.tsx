import { useMemo } from 'react';
import { Link, useSearchParams } from 'react-router';
import { PlusIcon, StackIcon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import { can, useMe } from '@/features/auth/api';
import { ContentHeader } from '@/components/shell/ContentHeader';
import { Button, buttonClass } from '@/components/ui/Button';
import { DataTableFooter, dataTable } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { QueryError } from '@/components/ui/QueryError';
import { SearchField } from '@/components/ui/SearchField';
import { Select } from '@/components/ui/Select';
import { useCoupons } from '../api';
import { MarketingTabs, PromotionStatusChip, couponStatus, crumbs, dhakaDay, offerText } from '../parts';

/**
 * Discounts & coupons list (Figma B · 20 241:42268 tabs + DataTable pattern). Filters live in
 * the URL: `q`, `status`, `batch`. Every figure is the server's.
 */
export function CouponsPage() {
    const { t, locale } = useI18n();
    const me = useMe();
    const manage = can(me.data, 'marketing.manage');
    const [params, setParams] = useSearchParams();
    const filters = { search: params.get('q') ?? '', status: params.get('status') ?? '', batch: params.get('batch') ?? '' };
    const coupons = useCoupons(filters);
    const rows = useMemo(() => coupons.data?.pages.flatMap((p) => p.data) ?? [], [coupons.data]);

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
                title={t('mkt.coupons.title')}
                breadcrumb={crumbs(t, 'mkt.tab.coupons')}
                subtitle={t('mkt.coupons.subtitle')}
                actions={
                    manage && (
                        <>
                            <Link to="/marketing/coupons/generate" className={buttonClass('outline')}>
                                <StackIcon size={18} aria-hidden />
                                {t('mkt.coupons.generate')}
                            </Link>
                            <Link to="/marketing/coupons/new" className={buttonClass('primary')}>
                                <PlusIcon size={18} aria-hidden />
                                {t('mkt.coupons.new')}
                            </Link>
                        </>
                    )
                }
            />
            <MarketingTabs />
            <div className="flex flex-wrap items-end gap-3">
                <SearchField label={t('mkt.search')} placeholder={t('mkt.coupons.searchPlaceholder')} value={filters.search} onChange={(e) => set('q', e.target.value)} className="w-full max-w-xs" />
                <label className="flex items-center gap-2 t-admin-caption text-fg-secondary">
                    {t('mkt.coupons.statusFilter')}
                    <Select size="sm" value={filters.status} onChange={(e) => set('status', e.target.value)}>
                        <option value="">{t('mkt.all')}</option>
                        <option value="active">{t('mkt.status.active')}</option>
                        <option value="inactive">{t('mkt.status.inactive')}</option>
                    </Select>
                </label>
                {filters.batch && (
                    <button type="button" onClick={() => set('batch', '')} className="inline-flex h-8 items-center gap-1 rounded-sm border border-line-default bg-subtle px-2 t-admin-caption text-fg-primary">
                        {t('mkt.coupons.batchTag', { batch: filters.batch })} ×
                    </button>
                )}
            </div>
            {coupons.isError ? (
                <QueryError error={coupons.error} onRetry={() => void coupons.refetch()} />
            ) : coupons.isSuccess && rows.length === 0 ? (
                <EmptyState title={t('mkt.coupons.empty.title')} body={t('mkt.coupons.empty.body')} />
            ) : (
                <div className={dataTable.card}>
                    <div className="overflow-x-auto">
                        <table className={dataTable.table}>
                            <thead className={dataTable.head}>
                                <tr>
                                    <th className={dataTable.th}>{t('mkt.coupons.col.code')}</th>
                                    <th className={dataTable.th}>{t('mkt.coupons.col.offer')}</th>
                                    <th className={`${dataTable.th} text-end`}>{t('mkt.coupons.col.used')}</th>
                                    <th className={dataTable.th}>{t('mkt.coupons.col.window')}</th>
                                    <th className={dataTable.th}>{t('mkt.coupons.col.status')}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {rows.map((c) => (
                                    <tr key={c.id} className={dataTable.row}>
                                        <td className={dataTable.td}>
                                            <Link to={`/marketing/coupons/${c.id}`} className="t-admin-mono text-fg-link hover:underline">
                                                {c.code}
                                            </Link>
                                            {c.batch && (
                                                <button type="button" onClick={() => set('batch', c.batch ?? '')} className="block t-admin-caption text-fg-tertiary hover:underline">
                                                    {t('mkt.coupons.batchTag', { batch: c.batch })}
                                                </button>
                                            )}
                                        </td>
                                        <td className={`${dataTable.td} t-admin-body`}>{offerText(t, c)}</td>
                                        <td className={`${dataTable.td} text-end t-admin-body tabular`}>
                                            {c.usage_limit_total === null ? c.used_count : t('mkt.coupons.usedOf', { used: c.used_count, limit: c.usage_limit_total })}
                                        </td>
                                        <td className={`${dataTable.td} t-admin-caption tabular text-fg-secondary`}>
                                            {c.starts_at || c.ends_at ? `${dhakaDay(c.starts_at, locale)} – ${dhakaDay(c.ends_at, locale)}` : t('mkt.summary.noWindow')}
                                        </td>
                                        <td className={dataTable.td}>
                                            <PromotionStatusChip status={couponStatus(c)} />
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    <DataTableFooter summary={t('mkt.shown', { count: rows.length })}>
                        {coupons.hasNextPage && (
                            <Button variant="outline" size="sm" onClick={() => void coupons.fetchNextPage()} disabled={coupons.isFetchingNextPage}>
                                {t('mkt.loadMore')}
                            </Button>
                        )}
                    </DataTableFooter>
                </div>
            )}
        </div>
    );
}

