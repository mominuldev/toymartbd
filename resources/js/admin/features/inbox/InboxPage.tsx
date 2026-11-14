import { useEffect, useMemo, useRef } from 'react';
import { useParams, useSearchParams } from 'react-router';
import { useI18n } from '@/i18n/I18nProvider';
import { EmptyState } from '@/components/ui/EmptyState';
import { QueryError } from '@/components/ui/QueryError';
import { channels, statuses, useConversation, useMarkRead, views, type InboxFilters } from './api';
import { ConversationList } from './ConversationList';
import { CustomerPanel } from './CustomerPanel';
import { Thread } from './Thread';

const pick = <T extends string>(value: string | null, allowed: readonly T[]): T | '' => (allowed.includes(value as T) ? (value as T) : '');

/**
 * Unified inbox (Figma B · 22 241:43115, Theme B): conversation list · thread · customer
 * panel, full height beside the Shell's sidebar, under the topbar. Filters live in the URL;
 * `/inbox/:conversationId` selects a thread. Below 1024 the list and the thread take turns;
 * the customer panel joins at 1280.
 *
 * Every channel is UNVERIFIED (no WhatsApp Business account or Facebook Page): replies are
 * recorded and handed to the `log` driver until an owner connects one.
 */
export function InboxPage() {
    const { conversationId } = useParams();
    const [params, setParams] = useSearchParams();
    const selectedId = conversationId && /^\d+$/.test(conversationId) ? Number(conversationId) : null;

    const filters: InboxFilters = useMemo(
        () => ({
            view: pick(params.get('view'), views) || 'all',
            channel: pick(params.get('channel'), channels),
            status: pick(params.get('status'), statuses),
            search: params.get('q') ?? '',
        }),
        [params],
    );

    const setFilters = (next: Partial<InboxFilters>) =>
        setParams(
            (current) => {
                const out = new URLSearchParams(current);
                const map: Record<keyof InboxFilters, string> = { view: 'view', channel: 'channel', status: 'status', search: 'q' };
                for (const [key, value] of Object.entries(next) as [keyof InboxFilters, string][]) {
                    if (value && !(key === 'view' && value === 'all')) out.set(map[key], value);
                    else out.delete(map[key]);
                }
                return out;
            },
            { replace: true },
        );

    return (
        // Full-bleed: undo the Shell's main-area padding and fill the height under the 60px topbar.
        <div className="-m-4 grid h-[calc(100dvh-3.75rem)] grid-cols-1 overflow-hidden bg-surface md:-mx-5 md:-mt-5 md:-mb-10 lg:grid-cols-[21.5rem_minmax(0,1fr)] xl:-mx-6 xl:-mt-6 xl:grid-cols-[21.5rem_minmax(0,1fr)_19.25rem]">
            <div className={`min-h-0 ${selectedId ? 'hidden lg:flex' : 'flex'} flex-col`}>
                <ConversationList filters={filters} onFilters={setFilters} selectedId={selectedId} />
            </div>
            {selectedId ? <Selected id={selectedId} /> : <Placeholder />}
        </div>
    );
}

function Placeholder() {
    const { t } = useI18n();
    return (
        <div className="hidden min-h-0 items-center justify-center bg-canvas p-6 lg:col-span-1 lg:flex xl:col-span-2">
            <EmptyState reason="noDataYet" title={t('inbox.pick')} body={t('inbox.pickBody')} />
        </div>
    );
}

function Selected({ id }: { id: number }) {
    const conversation = useConversation(id);
    const markRead = useMarkRead(id);
    const marked = useRef<number | null>(null);
    const detail = conversation.data;

    useEffect(() => {
        if (detail && detail.id === id && detail.unread_count > 0 && marked.current !== id) {
            marked.current = id;
            markRead.mutate();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [detail, id]);

    if (conversation.isError) {
        return (
            <div className="p-6 xl:col-span-2">
                <QueryError error={conversation.error} onRetry={() => void conversation.refetch()} />
            </div>
        );
    }
    if (!detail) return <div aria-busy className="min-h-0 bg-surface xl:col-span-2" />;

    return (
        <>
            <div className="flex min-h-0 min-w-0 flex-col">
                <Thread key={detail.id} detail={detail} />
            </div>
            <div className="hidden min-h-0 flex-col xl:flex">
                <CustomerPanel detail={detail} />
            </div>
        </>
    );
}
