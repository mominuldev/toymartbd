import { useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router';
import { ArchiveIcon, BrowserIcon, EyeIcon, HouseIcon, PencilSimpleIcon, PlusIcon, SquaresFourIcon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import { can, useMe } from '@/features/auth/api';
import { Button, buttonClass } from '@/components/ui/Button';
import { ActionMenu, type ActionMenuItem } from '@/components/ui/ActionMenu';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { useToast } from '@/components/ui/Toast';
import { SearchField } from '@/components/ui/SearchField';
import { SegmentedControl } from '@/components/ui/SegmentedControl';
import { EmptyState } from '@/components/ui/EmptyState';
import { QueryError } from '@/components/ui/QueryError';
import { StoreHeader } from '../shared/StoreHeader';
import { PageStateChip } from '../shared/PageStateChip';
import { homeLayout, pageLayout, useIssuePreview, useLayoutDraft, type LayoutBase } from '../home/api';
import { useArchivePage, usePages, type CmsPage, type PageStatusFilter } from './api';

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
 *
 * Home is pinned first (owner decision 2026-11-16: every page, Home included, is built from
 * sections). Its chip comes from the home draft — never published is Draft, otherwise Live with
 * the date of the live version — and it follows the filter (Published when live, Drafts when not)
 * and a search for "home". The tab count still counts pages only.
 *
 * Each row's ⋯ menu: Edit (page settings), Edit sections (the builder), Preview (the draft on the
 * storefront, in a new tab, through a 10-minute preview token) and Archive (content.manage, not
 * on an archived page). Home shows the same menu: Edit opens its settings (SEO), and Archive is
 * disabled because the homepage is never archived.
 */
export function PagesPage() {
    const { t, locale } = useI18n();
    const { notify } = useToast();
    const issuePreview = useIssuePreview();
    const [archiving, setArchiving] = useState<CmsPage | null>(null);
    const archive = useArchivePage(archiving?.id ?? 0);
    const { data: me } = useMe();
    const [search, setSearch] = useState('');
    const [status, setStatus] = useState<PageStatusFilter>('all');
    const query = usePages(useDebounced(search.trim()), status);
    const pages = useMemo(() => query.data?.pages.flatMap((p) => p.data) ?? [], [query.data]);
    const manage = can(me, 'content.manage');
    const home = useLayoutDraft(homeLayout);
    const homeLive = home.data ? home.data.live : undefined;
    const term = search.trim().toLowerCase();
    const homeMatches = term === '' || 'home'.includes(term) || t('content.pages.home').toLowerCase().includes(term);
    const homeInFilter = status === 'all' || (status === 'published' && homeLive != null) || (status === 'draft' && homeLive === null);
    const showHome = homeMatches && homeInFilter;

    /*
     * The tab opens on the click itself, before the token request, so a popup blocker lets it
     * through; it is pointed at the storefront once the token arrives, or closed on a failure.
     */
    const openPreview = (base: LayoutBase) => {
        const tab = window.open('', '_blank');
        if (tab) tab.opener = null;
        issuePreview.mutate(
            { base, locale: locale === 'bn' ? 'bn' : 'en' },
            {
                onSuccess: ({ url }) => {
                    if (!url) {
                        tab?.close();
                        notify(t('content.pages.previewUnavailable'), 'danger');
                    } else if (tab) tab.location.href = url;
                    else window.open(url, '_blank', 'noopener');
                },
                onError: () => {
                    tab?.close();
                    notify(t('content.pages.previewFailed'), 'danger');
                },
            },
        );
    };

    const pageMenu = (page: CmsPage): ActionMenuItem[] => [
        { key: 'edit', label: t('content.pages.menu.edit'), icon: <PencilSimpleIcon size={16} aria-hidden />, to: `/store/pages/${page.id}` },
        { key: 'sections', label: t('content.pages.editSections'), icon: <SquaresFourIcon size={16} aria-hidden />, to: `/store/pages/${page.id}/build` },
        { key: 'preview', label: t('content.pages.menu.preview'), icon: <EyeIcon size={16} aria-hidden />, onSelect: () => openPreview(pageLayout(page.id)) },
        ...(manage && page.state !== 'archived'
            ? [{ key: 'archive', label: t('content.pages.menu.archive'), icon: <ArchiveIcon size={16} aria-hidden />, onSelect: () => setArchiving(page) }]
            : []),
    ];

    // The same four items as a page. Edit opens Home's settings (its SEO); the homepage is never archived, so Archive is disabled.
    const homeMenu: ActionMenuItem[] = [
        { key: 'edit', label: t('content.pages.menu.edit'), icon: <PencilSimpleIcon size={16} aria-hidden />, to: '/store/home/settings' },
        { key: 'sections', label: t('content.pages.editSections'), icon: <SquaresFourIcon size={16} aria-hidden />, to: '/store/home' },
        { key: 'preview', label: t('content.pages.menu.preview'), icon: <EyeIcon size={16} aria-hidden />, onSelect: () => openPreview(homeLayout) },
        ...(manage ? [{ key: 'archive', label: t('content.pages.menu.archive'), icon: <ArchiveIcon size={16} aria-hidden />, disabled: true as const, hint: t('content.pages.homeNoArchive') }] : []),
    ];

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

                {(showHome || pages.length > 0) && (
                    <ul aria-label={t('content.pages.card')}>
                        {showHome && (
                            <li className="flex items-center gap-3 border-b border-line-default py-3">
                                <HouseIcon size={18} aria-hidden className="shrink-0 text-fg-secondary" />
                                <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                                    <Link to="/store/home" className="t-admin-body-strong truncate text-fg-primary hover:underline">
                                        {t('content.pages.home')}
                                    </Link>
                                    <span className="t-admin-mono-sm truncate text-fg-tertiary">{`${SITE_HOST}/`}</span>
                                </div>
                                {home.data?.has_unpublished_changes && <span className="t-admin-caption text-fg-tertiary max-sm:hidden">{t('content.pages.homeUnpublished')}</span>}
                                {homeLive !== undefined && <PageStateChip page={homeLive ? { state: 'live', published_at: homeLive.published_at, updated_at: homeLive.published_at } : { state: 'draft', published_at: null, updated_at: null }} />}
                                <ActionMenu label={t('content.pages.homeActions')} items={homeMenu} />
                            </li>
                        )}
                        {query.isSuccess &&
                            pages.map((page) => (
                                <li key={page.id} className="flex items-center gap-3 border-b border-line-default py-3">
                                    <BrowserIcon size={18} aria-hidden className="shrink-0 text-fg-secondary" />
                                    <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                                        <Link to={`/store/pages/${page.id}/build`} className="t-admin-body-strong truncate text-fg-primary hover:underline">
                                            {page.title}
                                        </Link>
                                        <span className="t-admin-mono-sm truncate text-fg-tertiary">{`${SITE_HOST}${page.path}`}</span>
                                    </div>
                                    <PageStateChip page={page} />
                                    <ActionMenu label={t('content.pages.rowActions', { title: page.title })} items={pageMenu(page)} />
                                </li>
                            ))}
                    </ul>
                )}

                {query.isError ? (
                    <QueryError error={query.error} onRetry={() => void query.refetch()} />
                ) : query.isPending ? (
                    <p aria-busy="true" className="py-3 t-admin-body text-fg-tertiary">
                        {t('common.loading')}
                    </p>
                ) : pages.length === 0 ? (
                    search.trim() !== '' || status !== 'all' ? (
                        showHome ? null : <p className="py-3 t-admin-body text-fg-secondary">{t('content.pages.noMatch')}</p>
                    ) : (
                        <EmptyState title={t('content.pages.empty')} body={t('content.pages.emptyBody')} actions={newPage || undefined} />
                    )
                ) : null}
                {query.hasNextPage && (
                    <Button variant="outline" size="sm" className="mt-3 self-start" onClick={() => void query.fetchNextPage()} disabled={query.isFetchingNextPage}>
                        {query.isFetchingNextPage ? t('common.loading') : t('common.loadMore')}
                    </Button>
                )}
            </div>

            {archiving && (
                <ConfirmDialog
                    open
                    title={t('content.pages.archiveTitle', { title: archiving.title })}
                    body={t('content.pages.archiveBody')}
                    confirmLabel={t('content.pages.archive')}
                    pending={archive.isPending}
                    onClose={() => setArchiving(null)}
                    onConfirm={() =>
                        archive.mutate(undefined, {
                            onSuccess: () => {
                                setArchiving(null);
                                notify(t('content.pages.archived'));
                            },
                        })
                    }
                />
            )}
        </section>
    );
}
