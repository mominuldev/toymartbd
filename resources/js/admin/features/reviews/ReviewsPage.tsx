import { useEffect, useId, useMemo, useRef, useState, type ReactNode } from 'react';
import { useSearchParams } from 'react-router';
import { ImageIcon, SealCheckIcon, StarIcon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { can, useMe } from '@/features/auth/api';
import { Button } from '@/components/ui/Button';
import { ContentHeader } from '@/components/shell/ContentHeader';
import { DataTableFooter, dataTable } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { inputClass } from '@/components/ui/Field';
import { QueryError } from '@/components/ui/QueryError';
import { SearchField } from '@/components/ui/SearchField';
import { Select } from '@/components/ui/Select';
import { StatusChip, type StatusTone } from '@/components/ui/StatusChip';
import { TabButton } from '@/components/ui/Tabs';
import { useToast } from '@/components/ui/Toast';
import { formatDhakaDate } from '@/lib/format';
import { reviewStatuses, useModerateReview, useReplyToReview, useReviews, type AdminReview, type ReviewFilters, type ReviewStatus } from './api';

const statusLabel: Record<ReviewStatus, MessageKey> = {
    pending: 'reviews.status.pending',
    approved: 'reviews.status.approved',
    rejected: 'reviews.status.rejected',
};
const statusTone: Record<ReviewStatus, StatusTone> = { pending: 'warning', approved: 'success', rejected: 'danger' };

function readFilters(params: URLSearchParams): ReviewFilters {
    const status = params.get('status');
    const rating = params.get('rating') ?? '';
    const verified = params.get('verified');
    return {
        // The queue opens on what needs a decision.
        status: status === null ? 'pending' : reviewStatuses.includes(status as ReviewStatus) ? (status as ReviewStatus) : '',
        search: params.get('q') ?? '',
        rating: /^[1-5]$/.test(rating) ? rating : '',
        verified: verified === '0' || verified === '1' ? verified : '',
    };
}

/**
 * Review moderation queue (P4-CAT-12). Not drawn in Figma (06-admin-feature-checklist §6:
 * "review moderation — pattern exists"), so it follows the Products list pattern
 * (241:37251): ContentHeader, AdminTab status filter with counts, SearchField + AdminSelect
 * filters, DataTable 192:532 rows, StatusChip 185:79, outline sm AdminButtons per row.
 * Filters live in the URL; the server filters, pages and recomputes ratings.
 */
export function ReviewsPage() {
    const { t, locale } = useI18n();
    const bn = locale === 'bn';
    const { data: me } = useMe();
    const toast = useToast();
    const [params, setParams] = useSearchParams();
    const filters = readFilters(params);
    const [search, setSearch] = useState(filters.search);
    const [debounced, setDebounced] = useState(filters.search);
    const query = useReviews({ ...filters, search: debounced });
    const reviews = useMemo(() => query.data?.pages.flatMap((page) => page.data) ?? [], [query.data]);
    const counts = query.data?.pages[0]?.meta.counts;
    const moderate = useModerateReview();
    const [replying, setReplying] = useState<AdminReview | null>(null);
    const canModerate = can(me, 'reviews.moderate');

    useEffect(() => {
        const id = window.setTimeout(() => setDebounced(search.trim()), 250);
        return () => window.clearTimeout(id);
    }, [search]);

    const setFilter = (key: string, value: string) =>
        setParams(
            (current) => {
                const next = new URLSearchParams(current);
                next.set(key, value);
                if (!value && key !== 'status') next.delete(key);
                return next;
            },
            { replace: true },
        );

    useEffect(() => {
        if (debounced !== filters.search) setFilter('q', debounced);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [debounced]);

    const act = (review: AdminReview, action: 'approve' | 'reject') =>
        moderate.mutate(
            { id: review.id, action },
            {
                onSuccess: () => toast.notify(t(action === 'approve' ? 'reviews.approved' : 'reviews.rejected'), 'success'),
                onError: () => toast.notify(t('reviews.error'), 'danger'),
            },
        );

    const tabs: { value: ReviewStatus | ''; label: MessageKey }[] = [
        { value: 'pending', label: 'reviews.status.pending' },
        { value: 'approved', label: 'reviews.status.approved' },
        { value: 'rejected', label: 'reviews.status.rejected' },
        { value: '', label: 'common.all' },
    ];
    const filtered = Boolean(filters.search || filters.rating || filters.verified);

    return (
        <section className="flex flex-col gap-6">
            <ContentHeader breadcrumb={[{ label: t('catalog.crumb') }, { label: t('catalog.tab.reviews') }]} title={t('catalog.tab.reviews')} subtitle={t('reviews.subtitle')} />

            <div role="group" aria-label={t('reviews.statusFilter')} className="scrollbar-none flex overflow-x-auto border-b border-line-default">
                {tabs.map((tab) => (
                    <TabButton
                        key={tab.value || 'all'}
                        active={filters.status === tab.value}
                        label={t(tab.label)}
                        count={tab.value && counts ? counts[tab.value] : undefined}
                        onClick={() => setFilter('status', tab.value)}
                    />
                ))}
            </div>

            <div className="flex flex-wrap items-center gap-2">
                <SearchField label={t('reviews.searchLabel')} value={search} onChange={(e) => setSearch(e.target.value)} placeholder={t('reviews.searchPlaceholder')} className="w-full md:w-80" />
                <FilterSelect label={t('reviews.filter.rating')} value={filters.rating} onChange={(v) => setFilter('rating', v)}>
                    <option value="">{t('reviews.filter.ratingAll')}</option>
                    {['5', '4', '3', '2', '1'].map((stars) => (
                        <option key={stars} value={stars}>
                            {t('reviews.filter.stars', { count: stars })}
                        </option>
                    ))}
                </FilterSelect>
                <FilterSelect label={t('reviews.filter.verified')} value={filters.verified} onChange={(v) => setFilter('verified', v)}>
                    <option value="">{t('reviews.filter.verifiedAll')}</option>
                    <option value="1">{t('reviews.filter.verifiedOnly')}</option>
                    <option value="0">{t('reviews.filter.unverifiedOnly')}</option>
                </FilterSelect>
            </div>

            {query.isError ? (
                <QueryError error={query.error} onRetry={() => void query.refetch()} />
            ) : query.isPending ? (
                <p className="t-admin-body text-fg-tertiary" aria-busy="true">
                    {t('common.loading')}
                </p>
            ) : reviews.length === 0 ? (
                filtered ? (
                    <EmptyState reason="allClear" title={t('reviews.emptyFiltered')} body={t('reviews.emptyFilteredBody')} />
                ) : (
                    <EmptyState reason="allClear" title={t('reviews.empty')} body={t('reviews.emptyBody')} />
                )
            ) : (
                <div className={dataTable.card}>
                    <table className={dataTable.table}>
                        <thead className={`hidden lg:table-header-group ${dataTable.head}`}>
                            <tr>
                                <th scope="col" className={dataTable.th}>
                                    {t('reviews.col.review')}
                                </th>
                                <th scope="col" className={dataTable.th}>
                                    {t('reviews.col.product')}
                                </th>
                                <th scope="col" className={dataTable.th}>
                                    {t('reviews.col.status')}
                                </th>
                                <th scope="col" className={dataTable.th}>
                                    {t('reviews.col.date')}
                                </th>
                                <th scope="col" className={dataTable.th.replace('text-start', 'text-end')}>
                                    {t('reviews.col.actions')}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {reviews.map((review) => (
                                <tr key={review.id} className={`flex flex-col gap-2 px-4 py-3 lg:table-row lg:p-0 ${dataTable.row}`}>
                                    <td className={`${dataTable.td} max-lg:h-auto max-lg:p-0 lg:max-w-md lg:py-3`}>
                                        <div className="flex flex-col gap-1">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <Stars rating={review.rating} label={t('reviews.stars', { count: review.rating })} />
                                                {review.is_verified_purchase ? (
                                                    <span className="t-admin-caption inline-flex items-center gap-1 text-fg-secondary">
                                                        <SealCheckIcon size={14} weight="fill" aria-hidden className="text-feedback-success" />
                                                        {t('reviews.verified')}
                                                    </span>
                                                ) : (
                                                    <span className="t-admin-caption text-fg-tertiary">{t('reviews.unverified')}</span>
                                                )}
                                            </div>
                                            {review.title && (
                                                <p lang={review.locale} className="t-admin-body-strong text-fg-primary">
                                                    {review.title}
                                                </p>
                                            )}
                                            {review.body && (
                                                <p lang={review.locale} className="t-admin-body whitespace-pre-line text-fg-primary">
                                                    {review.body}
                                                </p>
                                            )}
                                            <p className="t-admin-caption text-fg-secondary">
                                                {review.reviewer_name}
                                                {review.customer && <span className="tabular"> · {review.customer.phone}</span>}
                                            </p>
                                            {review.admin_reply && (
                                                <p className="t-admin-caption border-s-2 border-line-default ps-2 text-fg-secondary">
                                                    <span className="t-admin-caption-strong">{t('reviews.shopReply')}: </span>
                                                    {review.admin_reply}
                                                </p>
                                            )}
                                        </div>
                                    </td>
                                    <td className={`${dataTable.td} max-lg:h-auto max-lg:p-0`}>
                                        <div className="flex items-center gap-2.5">
                                            {review.product.image_url ? (
                                                <img src={review.product.image_url} alt="" className="size-11 shrink-0 rounded-xs-plus object-cover" />
                                            ) : (
                                                <span aria-hidden className="grid size-11 shrink-0 place-items-center rounded-xs-plus bg-subtle text-fg-tertiary">
                                                    <ImageIcon size={18} />
                                                </span>
                                            )}
                                            <span className="t-admin-body text-fg-primary">{bn ? (review.product.name_bn ?? review.product.name) : review.product.name}</span>
                                        </div>
                                    </td>
                                    <td className={`${dataTable.td} max-lg:h-auto max-lg:p-0`}>
                                        <StatusChip label={t(statusLabel[review.status])} tone={statusTone[review.status]} />
                                    </td>
                                    <td className={`${dataTable.td} t-admin-caption tabular whitespace-nowrap text-fg-secondary max-lg:h-auto max-lg:p-0`}>{formatDhakaDate(review.created_at, locale)}</td>
                                    <td className={`${dataTable.td} max-lg:h-auto max-lg:p-0`}>
                                        {canModerate && (
                                            <div className="flex flex-wrap justify-end gap-2">
                                                {review.status !== 'approved' && (
                                                    <Button size="sm" variant="secondary" disabled={moderate.isPending} onClick={() => act(review, 'approve')}>
                                                        {t('reviews.approve')}
                                                    </Button>
                                                )}
                                                {review.status !== 'rejected' && (
                                                    <Button size="sm" variant="outline" disabled={moderate.isPending} onClick={() => act(review, 'reject')}>
                                                        {t('reviews.reject')}
                                                    </Button>
                                                )}
                                                <Button size="sm" variant="ghost" onClick={() => setReplying(review)}>
                                                    {t('reviews.reply')}
                                                </Button>
                                            </div>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                    <DataTableFooter summary={t('reviews.count', { count: reviews.length })}>
                        {query.hasNextPage && (
                            <Button variant="outline" size="sm" onClick={() => void query.fetchNextPage()} disabled={query.isFetchingNextPage}>
                                {query.isFetchingNextPage ? t('common.loading') : t('common.loadMore')}
                            </Button>
                        )}
                    </DataTableFooter>
                </div>
            )}

            {replying && <ReplyDialog review={replying} onClose={() => setReplying(null)} />}
        </section>
    );
}

function Stars({ rating, label }: { rating: number; label: string }) {
    return (
        <span role="img" aria-label={label} className="inline-flex items-center gap-0.5">
            {[1, 2, 3, 4, 5].map((n) => (
                <StarIcon key={n} size={14} weight={n <= rating ? 'fill' : 'regular'} aria-hidden className={n <= rating ? 'text-feedback-warning' : 'text-fg-tertiary'} />
            ))}
        </span>
    );
}

/** The shop's public reply, in the A23 dialog frame (520, radius/lg, e4) on a native <dialog>. */
function ReplyDialog({ review, onClose }: { review: AdminReview; onClose: () => void }) {
    const { t } = useI18n();
    const toast = useToast();
    const ref = useRef<HTMLDialogElement>(null);
    const titleId = useId();
    const fieldId = useId();
    const [reply, setReply] = useState(review.admin_reply ?? '');
    const save = useReplyToReview();

    useEffect(() => {
        const dialog = ref.current;
        if (dialog && !dialog.open) {
            if (typeof dialog.showModal === 'function') dialog.showModal();
            else dialog.setAttribute('open', '');
        }
    }, []);

    return (
        <dialog
            ref={ref}
            aria-labelledby={titleId}
            onCancel={(event) => {
                event.preventDefault();
                onClose();
            }}
            className="m-auto w-[min(100%-2rem,32.5rem)] rounded-lg bg-surface p-0 text-fg-primary shadow-e4 backdrop:bg-overlay"
        >
            <div className="flex flex-col gap-3 px-6 pt-6 pb-4">
                <h2 id={titleId} className="t-admin-section text-fg-primary">
                    {t('reviews.replyTitle', { name: review.reviewer_name })}
                </h2>
                <label htmlFor={fieldId} className="t-admin-caption-strong text-fg-primary">
                    {t('reviews.replyLabel')}
                </label>
                <textarea id={fieldId} rows={4} maxLength={2000} value={reply} onChange={(e) => setReply(e.target.value)} className={`py-2 ${inputClass}`} />
                <p className="t-admin-caption text-fg-tertiary">{t('reviews.replyHint')}</p>
            </div>
            <div className="flex flex-wrap justify-end gap-3 border-t border-line-default px-6 py-4">
                <Button variant="outline" onClick={onClose}>
                    {t('productIo.close')}
                </Button>
                <Button
                    disabled={save.isPending}
                    onClick={() =>
                        save.mutate(
                            { id: review.id, reply: reply.trim() },
                            {
                                onSuccess: () => {
                                    toast.notify(t('reviews.replied'), 'success');
                                    onClose();
                                },
                                onError: () => toast.notify(t('reviews.error'), 'danger'),
                            },
                        )
                    }
                >
                    {t('reviews.replySave')}
                </Button>
            </div>
        </dialog>
    );
}

function FilterSelect({ label, value, onChange, children }: { label: string; value: string; onChange: (value: string) => void; children: ReactNode }) {
    return (
        <label className="flex">
            <span className="sr-only">{label}</span>
            <Select size="sm" value={value} onChange={(e) => onChange(e.target.value)} className="w-44">
                {children}
            </Select>
        </label>
    );
}
