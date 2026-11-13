import { useState } from 'react';
import { Link } from 'react-router';
import { ArrowDownIcon, ArrowUpIcon, DotsSixVerticalIcon, PencilSimpleIcon, PlusIcon, StarIcon, UserIcon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import { can, useMe } from '@/features/auth/api';
import { buttonClass } from '@/components/ui/Button';
import { IconButton, iconButtonClass } from '@/components/ui/IconButton';
import { EmptyState } from '@/components/ui/EmptyState';
import { QueryError } from '@/components/ui/QueryError';
import { StatusChip } from '@/components/ui/StatusChip';
import { useToast } from '@/components/ui/Toast';
import { StoreHeader } from '../shared/StoreHeader';
import { moveIndex } from '../shared/links';
import { useReorderTestimonials, useTestimonials, type Testimonial } from './api';

/**
 * Online store → Testimonials. No Figma frame: rows in the B · 27 menu-row language (grip, name +
 * বাংলা, caption), drag to reorder with up/down buttons as the keyboard path.
 */
export function TestimonialsPage() {
    const { t, locale } = useI18n();
    const { data: me } = useMe();
    const { notify } = useToast();
    const query = useTestimonials();
    const reorder = useReorderTestimonials();
    const [dragId, setDragId] = useState<number | null>(null);
    const manage = can(me, 'content.manage');
    const list = query.data ?? [];

    const move = (from: number, to: number) => {
        if (to < 0 || to >= list.length || from === to) return;
        reorder.mutate(moveIndex(list, from, to).map((x) => x.id), { onSuccess: () => notify(t('content.testimonials.reordered')) });
    };

    const name = (x: Testimonial) => (locale === 'bn' && x.name_bn ? x.name_bn : x.name);

    const add = manage && (
        <Link to="/store/testimonials/new" className={buttonClass('primary')}>
            <PlusIcon size={16} aria-hidden />
            {t('content.testimonials.new')}
        </Link>
    );

    return (
        <section className="flex flex-col gap-6">
            <StoreHeader section="testimonials" action={add} />

            {query.isError ? (
                <QueryError error={query.error} onRetry={() => void query.refetch()} />
            ) : query.isPending ? (
                <p aria-busy="true" className="t-admin-body text-fg-tertiary">
                    {t('common.loading')}
                </p>
            ) : list.length === 0 ? (
                <EmptyState title={t('content.testimonials.empty')} body={t('content.testimonials.emptyBody')} actions={add || undefined} />
            ) : (
                <div className="flex flex-col gap-1.5 rounded-md border border-line-default bg-surface p-5 shadow-e1">
                    <h2 className="pb-1 t-admin-section text-fg-primary">{t('content.testimonials.order')}</h2>
                    <ol aria-label={t('content.testimonials.order')} className="flex flex-col gap-1.5">
                        {list.map((x, index) => (
                            <li
                                key={x.id}
                                draggable={manage}
                                onDragStart={(e) => {
                                    setDragId(x.id);
                                    e.dataTransfer.setData('text/plain', String(x.id));
                                }}
                                onDragEnd={() => setDragId(null)}
                                onDragOver={(e) => e.preventDefault()}
                                onDrop={(e) => {
                                    e.preventDefault();
                                    const from = list.findIndex((item) => item.id === (dragId ?? Number(e.dataTransfer.getData('text/plain'))));
                                    if (from >= 0) move(from, index);
                                    setDragId(null);
                                }}
                                className={`flex items-center gap-2.5 rounded-sm border border-line-default bg-subtle p-2.5 ${dragId === x.id ? 'opacity-60' : ''}`}
                            >
                                <DotsSixVerticalIcon size={14} aria-label={t('content.testimonials.drag', { name: x.name })} className="shrink-0 text-fg-tertiary" />
                                <span className="grid size-10 shrink-0 place-items-center overflow-hidden rounded-full border border-line-default bg-surface">
                                    {x.photo ? <img src={x.photo.url} alt="" className="size-full object-cover" /> : <UserIcon size={18} aria-hidden className="text-fg-tertiary" />}
                                </span>
                                <div className="flex min-w-0 flex-1 flex-col">
                                    <div className="flex min-w-0 flex-wrap items-baseline gap-x-2">
                                        <span className="t-admin-body-strong text-fg-primary">{name(x)}</span>
                                        {(locale === 'bn' ? x.location_bn ?? x.location : x.location) && (
                                            <span className="t-admin-caption text-fg-tertiary">{locale === 'bn' ? (x.location_bn ?? x.location) : x.location}</span>
                                        )}
                                    </div>
                                    <p className="t-admin-caption truncate text-fg-secondary">{x.quote}</p>
                                    {x.quote_bn && (
                                        <p lang="bn" className="t-admin-caption truncate text-fg-secondary">
                                            {x.quote_bn}
                                        </p>
                                    )}
                                </div>
                                {x.rating !== null && (
                                    <span className="flex items-center gap-1 t-admin-caption-strong tabular text-fg-primary" aria-label={t('content.testimonials.rating', { rating: x.rating })}>
                                        <StarIcon size={14} weight="fill" aria-hidden className="text-feedback-warning" />
                                        {x.rating}
                                    </span>
                                )}
                                <StatusChip active={x.is_published} label={t(x.is_published ? 'content.testimonials.published' : 'content.testimonials.hidden')} />
                                {manage && (
                                    <>
                                        <IconButton size="sm" aria-label={t('content.testimonials.moveUp', { name: x.name })} disabled={index === 0 || reorder.isPending} onClick={() => move(index, index - 1)}>
                                            <ArrowUpIcon size={16} aria-hidden />
                                        </IconButton>
                                        <IconButton size="sm" aria-label={t('content.testimonials.moveDown', { name: x.name })} disabled={index === list.length - 1 || reorder.isPending} onClick={() => move(index, index + 1)}>
                                            <ArrowDownIcon size={16} aria-hidden />
                                        </IconButton>
                                    </>
                                )}
                                <Link to={`/store/testimonials/${x.id}`} className={iconButtonClass('ghost', 'md')} aria-label={`${t('common.edit')} ${x.name}`}>
                                    <PencilSimpleIcon size={16} aria-hidden />
                                </Link>
                            </li>
                        ))}
                    </ol>
                </div>
            )}
        </section>
    );
}
