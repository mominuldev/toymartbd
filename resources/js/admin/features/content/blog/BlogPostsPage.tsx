import { useEffect, useState } from 'react';
import { Link } from 'react-router';
import { CaretLeftIcon, CaretRightIcon, PlusIcon, StarIcon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import { can, useMe } from '@/features/auth/api';
import { buttonClass } from '@/components/ui/Button';
import { IconButton } from '@/components/ui/IconButton';
import { SearchField } from '@/components/ui/SearchField';
import { SegmentedControl } from '@/components/ui/SegmentedControl';
import { EmptyState } from '@/components/ui/EmptyState';
import { QueryError } from '@/components/ui/QueryError';
import { formatDhakaDateTime } from '../shared/dhaka';
import { usePosts, type StatusFilter, type TypeFilter } from './api';
import { BlogHeader, PostStatusChip, cardClass } from './parts';

function useDebounced<T>(value: T, ms = 250): T {
    const [debounced, setDebounced] = useState(value);
    useEffect(() => {
        const id = window.setTimeout(() => setDebounced(value), ms);
        return () => window.clearTimeout(id);
    }, [value, ms]);
    return debounced;
}

/**
 * Online store → Blog → Posts. No Figma frame for the list: the Pages card language of B · 27
 * (241:45525) with a table of title, type, status, category and Dhaka publish date.
 */
export function BlogPostsPage() {
    const { t, locale } = useI18n();
    const { data: me } = useMe();
    const [search, setSearch] = useState('');
    const [status, setStatus] = useState<StatusFilter>('all');
    const [type, setType] = useState<TypeFilter>('all');
    const [page, setPage] = useState(1);
    const term = useDebounced(search.trim());
    const query = usePosts(term, status, type, page);
    const rows = query.data?.data ?? [];
    const manage = can(me, 'content.manage');

    useEffect(() => setPage(1), [term, status, type]);

    const newPost = manage && (
        <Link to="/store/blog/new" className={buttonClass('primary')}>
            <PlusIcon size={16} aria-hidden />
            {t('blog.newPost')}
        </Link>
    );

    return (
        <section className="flex flex-col gap-6">
            <BlogHeader action={newPost} postCount={query.data && status === 'all' && type === 'all' && term === '' ? query.data.meta.total : undefined} />

            <div className={`${cardClass} gap-3`}>
                <div className="flex flex-wrap items-center gap-3">
                    <h2 className="min-w-0 flex-1 t-admin-section text-fg-primary">{t('blog.list.card')}</h2>
                    <SegmentedControl
                        label={t('blog.list.typeFilter')}
                        value={type}
                        onChange={setType}
                        options={[
                            { value: 'all', label: t('blog.type.all') },
                            { value: 'article', label: t('blog.type.article') },
                            { value: 'buying_guide', label: t('blog.type.buying_guide') },
                        ]}
                    />
                    <SegmentedControl
                        label={t('blog.list.statusFilter')}
                        value={status}
                        onChange={setStatus}
                        options={[
                            { value: 'all', label: t('blog.status.all') },
                            { value: 'draft', label: t('blog.status.draft') },
                            { value: 'scheduled', label: t('blog.status.scheduled') },
                            { value: 'published', label: t('blog.status.published') },
                        ]}
                    />
                </div>
                <SearchField label={t('blog.list.search')} placeholder={t('blog.list.searchPlaceholder')} value={search} onChange={(e) => setSearch(e.target.value)} className="w-full" />

                {query.isError ? (
                    <QueryError error={query.error} onRetry={() => void query.refetch()} />
                ) : query.isPending ? (
                    <p aria-busy="true" className="py-3 t-admin-body text-fg-tertiary">
                        {t('common.loading')}
                    </p>
                ) : rows.length === 0 ? (
                    term !== '' || status !== 'all' || type !== 'all' ? (
                        <p className="py-3 t-admin-body text-fg-secondary">{t('blog.list.noMatch')}</p>
                    ) : (
                        <EmptyState title={t('blog.list.empty')} body={t('blog.list.emptyBody')} actions={newPost || undefined} />
                    )
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-160 border-collapse">
                            <thead>
                                <tr className="border-b border-line-default text-start t-admin-caption-strong text-fg-secondary">
                                    <th scope="col" className="py-2 pe-3 text-start">
                                        {t('blog.list.col.title')}
                                    </th>
                                    <th scope="col" className="py-2 pe-3 text-start">
                                        {t('blog.list.col.status')}
                                    </th>
                                    <th scope="col" className="py-2 pe-3 text-start">
                                        {t('blog.list.col.category')}
                                    </th>
                                    <th scope="col" className="py-2 text-start">
                                        {t('blog.list.col.date')}
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {rows.map((post) => {
                                    const title = locale === 'bn' && post.title_bn ? post.title_bn : post.title;
                                    const category = post.category ? (locale === 'bn' && post.category.name_bn ? post.category.name_bn : post.category.name) : null;
                                    return (
                                        <tr key={post.id} className="border-b border-line-default align-middle">
                                            <td className="py-3 pe-3">
                                                <div className="flex min-w-0 items-center gap-3">
                                                    <span className="size-10 shrink-0 overflow-hidden rounded-xs bg-subtle">
                                                        {post.cover && <img src={post.cover.url} alt="" loading="lazy" className="size-full object-cover" />}
                                                    </span>
                                                    <div className="flex min-w-0 flex-col gap-0.5">
                                                        <Link to={`/store/blog/${post.id}`} className="t-admin-body-strong truncate text-fg-primary hover:underline" lang={locale === 'bn' && post.title_bn ? 'bn' : undefined}>
                                                            {title}
                                                        </Link>
                                                        <span className="flex items-center gap-1.5 t-admin-caption text-fg-tertiary">
                                                            {t(`blog.type.${post.type}`)}
                                                            {post.is_featured && (
                                                                <>
                                                                    {' · '}
                                                                    <StarIcon size={12} aria-hidden />
                                                                    {t('blog.list.featured')}
                                                                </>
                                                            )}
                                                        </span>
                                                    </div>
                                                </div>
                                            </td>
                                            <td className="py-3 pe-3">
                                                <PostStatusChip status={post.status} />
                                            </td>
                                            <td className="py-3 pe-3 t-admin-body text-fg-secondary">{category ?? '—'}</td>
                                            <td className="py-3 t-admin-body tabular-nums text-fg-secondary">{post.published_at ? formatDhakaDateTime(post.published_at, locale) : '—'}</td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                )}

                {query.data && query.data.meta.last_page > 1 && (
                    <nav aria-label={t('blog.list.page', { page: page, last: query.data.meta.last_page })} className="flex items-center justify-end gap-2">
                        <IconButton size="sm" aria-label={t('blog.list.prev')} disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>
                            <CaretLeftIcon size={16} aria-hidden />
                        </IconButton>
                        <span className="t-admin-caption tabular-nums text-fg-secondary">{t('blog.list.page', { page: page, last: query.data.meta.last_page })}</span>
                        <IconButton size="sm" aria-label={t('blog.list.next')} disabled={page >= query.data.meta.last_page} onClick={() => setPage((p) => p + 1)}>
                            <CaretRightIcon size={16} aria-hidden />
                        </IconButton>
                    </nav>
                )}
            </div>
        </section>
    );
}
