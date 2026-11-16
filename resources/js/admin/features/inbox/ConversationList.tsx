import { useEffect, useState } from 'react';
import { Link } from 'react-router';
import { FunnelIcon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { Button } from '@/components/ui/Button';
import { CountBadge } from '@/components/ui/CountBadge';
import { EmptyState } from '@/components/ui/EmptyState';
import { IconButton } from '@/components/ui/IconButton';
import { QueryError } from '@/components/ui/QueryError';
import { SearchField } from '@/components/ui/SearchField';
import { Select } from '@/components/ui/Select';
import { channels, statuses, useConversations, views, type Channel, type Conversation, type ConversationStatus, type InboxFilters, type View } from './api';
import { ChannelChip, ContactAvatar, NeedsHumanChip, ResolvedChip, channelLabel, langProps, useAge, useContactName } from './parts';

function useDebounced<T>(value: T, ms = 250): T {
    const [debounced, setDebounced] = useState(value);
    useEffect(() => {
        const id = window.setTimeout(() => setDebounced(value), ms);
        return () => window.clearTimeout(id);
    }, [value, ms]);
    return debounced;
}

type Props = {
    filters: InboxFilters;
    onFilters: (next: Partial<InboxFilters>) => void;
    selectedId: number | null;
};

/**
 * Figma B · 22 left column: title + filter, search (name, phone or order no.), the
 * All / Mine / Needs human tabs with server counts, and the rows. The AI tab of the frame is
 * left out — no AI provider is chosen. Every filter is a server allowlist (rule 16).
 */
export function ConversationList({ filters, onFilters, selectedId }: Props) {
    const { t } = useI18n();
    const [search, setSearch] = useState(filters.search);
    const debounced = useDebounced(search.trim());
    const [filtersOpen, setFiltersOpen] = useState(Boolean(filters.channel || filters.status));
    const list = useConversations(filters);

    useEffect(() => {
        if (debounced !== filters.search) onFilters({ search: debounced });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [debounced]);

    const rows = list.data?.pages.flatMap((p) => p.data) ?? [];
    const counts = list.data?.pages[0]?.meta.counts;
    const filtered = Boolean(filters.search || filters.channel || filters.status || filters.view !== 'all');

    return (
        <section aria-label={t('inbox.listLabel')} className="flex min-h-0 flex-1 flex-col border-line-default bg-surface lg:border-e">
            <div className="flex flex-col gap-3 px-4 pt-4 pb-2">
                <div className="flex items-center justify-between gap-2">
                    <h1 className="t-admin-page-title text-fg-primary">{t('inbox.title')}</h1>
                    <IconButton
                        variant="outline"
                        size="sm"
                        aria-label={t('inbox.filters')}
                        aria-expanded={filtersOpen}
                        onClick={() => setFiltersOpen((open) => !open)}
                    >
                        <FunnelIcon size={16} aria-hidden />
                    </IconButton>
                </div>
                <SearchField label={t('inbox.search')} placeholder={t('inbox.search')} value={search} onChange={(e) => setSearch(e.target.value)} />
                {filtersOpen && (
                    <div className="grid grid-cols-2 gap-2">
                        <label className="flex flex-col gap-1">
                            <span className="t-admin-caption text-fg-secondary">{t('inbox.filter.channel')}</span>
                            <Select value={filters.channel} onChange={(e) => onFilters({ channel: e.target.value as Channel | '' })}>
                                <option value="">{t('inbox.filter.channelAll')}</option>
                                {channels.map((c) => (
                                    <option key={c} value={c}>
                                        {t(channelLabel(c))}
                                    </option>
                                ))}
                            </Select>
                        </label>
                        <label className="flex flex-col gap-1">
                            <span className="t-admin-caption text-fg-secondary">{t('inbox.filter.status')}</span>
                            <Select value={filters.status} onChange={(e) => onFilters({ status: e.target.value as ConversationStatus | '' })}>
                                <option value="">{t('inbox.filter.statusAll')}</option>
                                {statuses.map((s) => (
                                    <option key={s} value={s}>
                                        {t(`inbox.status.${s}` as MessageKey)}
                                    </option>
                                ))}
                            </Select>
                        </label>
                    </div>
                )}
                <div role="group" aria-label={t('inbox.tabs')} className="flex flex-wrap items-center gap-1">
                    {views.map((view) => (
                        <ViewTab key={view} view={view} active={filters.view === view} count={counts?.[view]} onClick={() => onFilters({ view })} />
                    ))}
                </div>
            </div>

            <div className="min-h-0 flex-1 overflow-y-auto border-t border-line-default">
                {list.isError ? (
                    <div className="p-4">
                        <QueryError error={list.error} onRetry={() => void list.refetch()} />
                    </div>
                ) : list.isPending ? (
                    <ul aria-busy className="flex flex-col">
                        {[0, 1, 2].map((i) => (
                            <li key={i} className="h-23 animate-pulse border-b border-line-default bg-skeleton" />
                        ))}
                    </ul>
                ) : rows.length === 0 ? (
                    <EmptyState reason={filtered ? 'notFound' : 'notConnected'} title={filtered ? t('inbox.emptyFiltered') : t('inbox.empty')} body={filtered ? undefined : t('inbox.emptyBody')} className="m-4" />
                ) : (
                    <ul className="flex flex-col">
                        {rows.map((row) => (
                            <ConversationRow key={row.id} row={row} selected={row.id === selectedId} />
                        ))}
                    </ul>
                )}
                {list.hasNextPage && (
                    <div className="p-3">
                        <Button variant="ghost" size="sm" className="w-full" disabled={list.isFetchingNextPage} onClick={() => void list.fetchNextPage()}>
                            {t('inbox.loadMore')}
                        </Button>
                    </div>
                )}
            </div>
        </section>
    );
}

function ViewTab({ view, active, count, onClick }: { view: View; active: boolean; count: number | undefined; onClick: () => void }) {
    const { t } = useI18n();
    return (
        <button
            type="button"
            aria-pressed={active}
            onClick={onClick}
            className={`inline-flex h-7 items-center gap-1 rounded-sm px-2 whitespace-nowrap transition-colors duration-150 ${
                active ? 'bg-brand-subtle t-admin-caption-strong text-fg-primary' : 't-admin-caption text-fg-secondary hover:bg-subtle hover:text-fg-primary'
            }`}
        >
            {t(`inbox.tab.${view}` as MessageKey)}
            {count !== undefined && <span className="tabular">{count}</span>}
        </button>
    );
}

function ConversationRow({ row, selected }: { row: Conversation; selected: boolean }) {
    const { t } = useI18n();
    const age = useAge();
    const name = useContactName()(row);
    const preview = langProps(row.last_message_preview);
    const unread = row.unread_count > 0;

    return (
        <li>
            <Link
                to={`/inbox/${row.id}`}
                aria-current={selected ? 'page' : undefined}
                className={`flex gap-3 border-b border-line-default px-4 py-3 transition-colors duration-150 ${selected ? 'bg-brand-subtle' : 'hover:bg-subtle'}`}
            >
                <ContactAvatar name={name} />
                <span className="flex min-w-0 flex-1 flex-col gap-1">
                    <span className="flex items-baseline justify-between gap-2">
                        <span lang={langProps(name).lang} className={`truncate t-admin-body-strong text-fg-primary ${langProps(name).className}`}>
                            {name}
                        </span>
                        <span className={`shrink-0 t-admin-caption tabular ${unread ? 'text-fg-brand' : 'text-fg-tertiary'}`}>{age(row.last_message_at)}</span>
                    </span>
                    {row.last_message_preview && (
                        <span lang={preview.lang} className={`truncate t-admin-body ${unread ? 'text-fg-primary' : 'text-fg-secondary'} ${preview.className}`}>
                            {row.last_message_preview}
                        </span>
                    )}
                    <span className="flex flex-wrap items-center gap-1.5">
                        <ChannelChip channel={row.channel} />
                        {row.status === 'resolved' ? <ResolvedChip /> : row.needs_human && <NeedsHumanChip />}
                        {unread && (
                            <span>
                                <span aria-hidden>
                                    <CountBadge count={row.unread_count} tone="brand" />
                                </span>
                                <span className="sr-only">{t('inbox.unread', { count: row.unread_count })}</span>
                            </span>
                        )}
                    </span>
                </span>
            </Link>
        </li>
    );
}
