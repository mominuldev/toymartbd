import { useState } from 'react';
import { Link } from 'react-router';
import { PlusIcon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import { can, useMe } from '@/features/auth/api';
import { buttonClass } from '@/components/ui/Button';
import { dataTable, DataTableFooter } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { QueryError } from '@/components/ui/QueryError';
import { StatusChip, type StatusTone } from '@/components/ui/StatusChip';
import { TabButton } from '@/components/ui/Tabs';
import { StoreHeader } from '../shared/StoreHeader';
import { formatDhakaDateTime } from '../shared/dhaka';
import { placements, useBanners, type Banner, type BannerPlacement } from './api';

const stateTone: Record<Banner['state'], StatusTone> = { live: 'success', scheduled: 'info', ended: 'neutral', inactive: 'neutral' };

/** Online store → Banners. No Figma frame: built in the B · 27 / Products list (241:37251) language. */
export function BannersPage() {
    const { t, locale } = useI18n();
    const { data: me } = useMe();
    const [placement, setPlacement] = useState<BannerPlacement | 'all'>('all');
    const query = useBanners(placement);
    const banners = query.data?.data ?? [];

    const schedule = (b: Banner) => {
        if (!b.starts_at && !b.ends_at) return t('content.banners.always');
        if (b.starts_at && b.ends_at) return t('content.banners.range', { from: formatDhakaDateTime(b.starts_at, locale), to: formatDhakaDateTime(b.ends_at, locale) });
        return b.starts_at ? t('content.banners.from', { date: formatDhakaDateTime(b.starts_at, locale) }) : t('content.banners.until', { date: formatDhakaDateTime(b.ends_at!, locale) });
    };

    const newBanner = can(me, 'content.manage') && (
        <Link to="/store/banners/new" className={buttonClass('primary')}>
            <PlusIcon size={16} aria-hidden />
            {t('content.banners.new')}
        </Link>
    );

    return (
        <section className="flex flex-col gap-6">
            <StoreHeader action={newBanner} />
            <p className="t-admin-body text-fg-secondary">{t('content.banners.subtitle')}</p>

            <div role="group" aria-label={t('content.banners.placementFilter')} className="scrollbar-none flex overflow-x-auto border-b border-line-default">
                <TabButton active={placement === 'all'} label={t('common.all')} onClick={() => setPlacement('all')} />
                {placements.map((p) => (
                    <TabButton key={p} active={placement === p} label={t(`content.banners.placement.${p}`)} onClick={() => setPlacement(p)} />
                ))}
            </div>

            {query.isError ? (
                <QueryError error={query.error} onRetry={() => void query.refetch()} />
            ) : query.isPending ? (
                <p aria-busy="true" className="t-admin-body text-fg-tertiary">
                    {t('common.loading')}
                </p>
            ) : banners.length === 0 ? (
                <EmptyState title={t('content.banners.empty')} body={t('content.banners.emptyBody')} actions={newBanner || undefined} />
            ) : (
                <div className={dataTable.card}>
                    <table className={dataTable.table}>
                        <thead className={`hidden md:table-header-group ${dataTable.head}`}>
                            <tr>
                                <th scope="col" className={dataTable.th}>{t('content.banners.col.name')}</th>
                                <th scope="col" className={dataTable.th}>{t('content.banners.col.placement')}</th>
                                <th scope="col" className={dataTable.th}>{t('content.banners.col.schedule')}</th>
                                <th scope="col" className={dataTable.th}>{t('content.banners.col.priority')}</th>
                                <th scope="col" className={dataTable.th}>{t('content.banners.col.status')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {banners.map((b) => (
                                <tr key={b.id} className={`flex flex-wrap items-center gap-x-3 gap-y-1 px-4 py-3 first:border-t-0 md:table-row md:p-0 md:first:border-t ${dataTable.row}`}>
                                    <td className={`min-w-0 flex-1 basis-full md:basis-auto ${dataTable.td} max-md:h-auto max-md:p-0`}>
                                        <Link to={`/store/banners/${b.id}`} className="t-admin-body-strong text-fg-primary hover:underline">
                                            {b.name}
                                        </Link>
                                        {(b.title || b.title_bn) && <p className="t-admin-caption truncate text-fg-tertiary">{b.title ?? b.title_bn}</p>}
                                    </td>
                                    <td className={`${dataTable.td} t-admin-body text-fg-secondary max-md:h-auto max-md:p-0`}>
                                        {t(`content.banners.placement.${b.placement}`)}
                                        {b.placement === 'category_page' && <p className="t-admin-caption text-fg-tertiary">{b.category?.name ?? t('content.banners.allCategories')}</p>}
                                    </td>
                                    <td className={`${dataTable.td} t-admin-caption tabular text-fg-secondary max-md:h-auto max-md:p-0`}>{schedule(b)}</td>
                                    <td className={`${dataTable.td} t-admin-body tabular text-fg-secondary max-md:h-auto max-md:p-0`}>{b.priority}</td>
                                    <td className={`${dataTable.td} max-md:h-auto max-md:p-0`}>
                                        <StatusChip tone={stateTone[b.state]} label={t(`content.banners.state.${b.state}`)} />
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                    <DataTableFooter summary={String(banners.length)} />
                </div>
            )}
        </section>
    );
}
