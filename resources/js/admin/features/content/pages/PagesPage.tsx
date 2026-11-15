import { useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router';
import { BrowserIcon, DotsThreeIcon, PlusIcon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import { can, useMe } from '@/features/auth/api';
import { Button, buttonClass } from '@/components/ui/Button';
import { iconButtonClass } from '@/components/ui/IconButton';
import { SearchField } from '@/components/ui/SearchField';
import { SegmentedControl } from '@/components/ui/SegmentedControl';
import { EmptyState } from '@/components/ui/EmptyState';
import { QueryError } from '@/components/ui/QueryError';
import { StoreHeader } from '../shared/StoreHeader';
import { PageStateChip } from '../shared/PageStateChip';
import { usePages, type PageStatusFilter } from './api';

export const SITE_HOST = 'toymartbd.com';

function useDebounced<T>(value: T, ms = 250): T {
    const [debounced, setDebounced] = useState(value);
    useEffect(() => {
        const id = window.setTimeout(() => setDebounced(value), ms);
        return () => window.clearTimeout(id);
    }, [value, ms]);
    return debounced;
}

const cardClass = 'flex min-w-0 flex-col rounded-md border border-line-default bg-surface p-5 shadow-e1';

/**
 * Online store → Pages (Figma B · 27 Pages & navigation, 241:45525): the Pages card, full width
 * (rows with the page glyph, title, mono URL, state chip and a row action). The menus have their
 * own tabs, so the Header menu card the frame draws beside it is not repeated here.
 * Search and the status filter are additions the frame does not draw.
 */
export function PagesPage() {
    const { t } = useI18n();
    const { data: me } = useMe();
    const [search, setSearch] = useState('');
    const [status, setStatus] = useState<PageStatusFilter>('all');
    const query = usePages(useDebounced(search.trim()), status);
    const pages = useMemo(() => query.data?.pages.flatMap((p) => p.data) ?? [], [query.data]);
    const manage = can(me, 'content.manage');

    const newPage = manage && (
        <Link to="/store/pages/new" className={buttonClass('primary')}>
            <PlusIcon size={16} aria-hidden />
            {t('content.pages.new')}
        </Link>
    );

    return (
        <section className="flex flex-col gap-6">
            <StoreHeader action={newPage} pageCount={query.isSuccess && status === 'all' && search === '' && !query.hasNextPage ? pages.length : undefined} />

            <div className={cardClass}>
                <div className="flex flex-wrap items-center gap-3 pb-2">
                    <h2 className="min-w-0 flex-1 t-admin-section text-fg-primary">{t('content.pages.card')}</h2>
                    <SegmentedControl
                        label={t('content.pages.statusFilter')}
                        value={status}
                        onChange={setStatus}
                        options={[
                            { value: 'all', label: t('content.pages.status.all') },
                            { value: 'draft', label: t('content.pages.status.draft') },
                            { value: 'published', label: t('content.pages.status.published') },
                            { value: 'archived', label: t('content.pages.status.archived') },
                        ]}
                    />
                </div>
                <SearchField
                    label={t('content.pages.searchLabel')}
                    placeholder={t('content.pages.searchPlaceholder')}
                    value={search}
                    onChange={(e) => setSearch(e.target.value)}
                    className="mb-1 w-full"
                />

                {query.isError ? (
                    <QueryError error={query.error} onRetry={() => void query.refetch()} />
                ) : query.isPending ? (
                    <p aria-busy="true" className="py-3 t-admin-body text-fg-tertiary">
                        {t('common.loading')}
                    </p>
                ) : pages.length === 0 ? (
                    search.trim() !== '' || status !== 'all' ? (
                        <p className="py-3 t-admin-body text-fg-secondary">{t('content.pages.noMatch')}</p>
                    ) : (
                        <EmptyState title={t('content.pages.empty')} body={t('content.pages.emptyBody')} actions={newPage || undefined} />
                    )
                ) : (
                    <ul aria-label={t('content.pages.card')}>
                        {pages.map((page) => (
                            <li key={page.id} className="flex items-center gap-3 border-b border-line-default py-3">
                                <BrowserIcon size={18} aria-hidden className="shrink-0 text-fg-secondary" />
                                <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                                    <Link to={`/store/pages/${page.id}`} className="t-admin-body-strong truncate text-fg-primary hover:underline">
                                        {page.title}
                                    </Link>
                                    <span className="t-admin-mono-sm truncate text-fg-tertiary">{`${SITE_HOST}${page.path}`}</span>
                                </div>
                                <PageStateChip page={page} />
                                <Link to={`/store/pages/${page.id}`} className={iconButtonClass('ghost', 'sm')} aria-label={t('content.pages.rowActions', { title: page.title })}>
                                    <DotsThreeIcon size={16} aria-hidden />
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
                {query.hasNextPage && (
                    <Button variant="outline" size="sm" className="mt-3 self-start" onClick={() => void query.fetchNextPage()} disabled={query.isFetchingNextPage}>
                        {query.isFetchingNextPage ? t('common.loading') : t('common.loadMore')}
                    </Button>
                )}
            </div>
        </section>
    );
}
