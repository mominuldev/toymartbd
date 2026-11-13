import { useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router';
import { BrowserIcon, DotsThreeIcon, PencilSimpleIcon, PlusIcon } from '@phosphor-icons/react';
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
import { useMenu } from '../menus/api';
import { MenuRow } from '../menus/MenuRow';
import { flatten, fromApi } from '../menus/tree';
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
 * Online store → Pages (Figma B · 27 Pages & navigation, 241:45525): the Pages card (rows with
 * the page glyph, title, mono URL, state chip and a row action) beside the Header menu card.
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

            <div className="grid items-start gap-6 xl:grid-cols-[minmax(0,620fr)_minmax(0,500fr)]">
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

                <HeaderMenuCard />
            </div>
        </section>
    );
}

/** The right-hand Header menu card (241:45612): a read view; editing happens on the Header menu tab. */
function HeaderMenuCard() {
    const { t } = useI18n();
    const menu = useMenu('header');
    const rows = menu.data ? flatten(fromApi(menu.data.items)) : [];

    return (
        <div className={`${cardClass} gap-1.5`}>
            <div className="flex items-center gap-2">
                <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                    <h2 className="t-admin-section text-fg-primary">{t('content.menus.title.header')}</h2>
                    <p className="t-admin-caption text-fg-tertiary">{t('content.menus.help', { depth: menu.data?.max_depth ?? 3 })}</p>
                </div>
                <Link to="/store/navigation/header" className={buttonClass('outline', 'sm')}>
                    <PlusIcon size={16} aria-hidden />
                    {t('content.menus.addItem')}
                </Link>
            </div>
            {menu.isError ? (
                <QueryError error={menu.error} onRetry={() => void menu.refetch()} />
            ) : rows.length === 0 ? (
                <p className="py-3 t-admin-body text-fg-secondary">{menu.isPending ? t('common.loading') : t('content.menus.emptyBody')}</p>
            ) : (
                <ul className="flex flex-col gap-1.5">
                    {rows.map(({ node, depth }) => (
                        <li key={node.key}>
                            <MenuRow
                                node={node}
                                depth={depth}
                                controls={
                                    <Link to="/store/navigation/header" className={iconButtonClass('ghost', 'md')} aria-label={t('content.menus.edit', { label: node.label })}>
                                        <PencilSimpleIcon size={16} aria-hidden />
                                    </Link>
                                }
                            />
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
