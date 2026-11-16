import { useEffect, useLayoutEffect, useRef, useState, type FormEvent, type KeyboardEvent } from 'react';
import { Link } from 'react-router';
import {
    ArrowCounterClockwiseIcon,
    ArrowLeftIcon,
    CheckIcon,
    ClockIcon,
    EnvelopeSimpleIcon,
    EyeSlashIcon,
    InfoIcon,
    PaperPlaneRightIcon,
    UserPlusIcon,
    UsersThreeIcon,
    WarningCircleIcon,
} from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { can, useMe } from '@/features/auth/api';
import { ApiError } from '@/lib/api';
import { Button } from '@/components/ui/Button';
import { IconButton } from '@/components/ui/IconButton';
import { QueryError } from '@/components/ui/QueryError';
import { useToast } from '@/components/ui/Toast';
import { canReplyFreely, isWebForm, useAddNote, useAssign, useAssignees, useMessages, useResolve, useSendReply, type ConversationDetail, type InboxMessage } from './api';
import { TemplatePanel } from './TemplatePanel';
import { ChannelIcon, ContactAvatar, channelLabel, dhakaDayTime, dhakaTime, displayPhone, langProps, useContactName } from './parts';

type Mode = 'reply' | 'note';

/**
 * Figma B · 22 centre column: the contact header (channel, phone masked unless
 * customers.view_pii, Assign to me, Resolve), the thread — customer bubbles, our replies with
 * their delivery status, internal notes (dashed, never sent), system lines — and the composer
 * with Reply / Internal note and the WhatsApp 24-hour window notice.
 *
 * Left out of the frame on purpose (owner has not chosen an AI provider): the AI handover
 * banner, AI-assistant bubbles, "Suggested reply" and the composer's quick-reply/attachment
 * icons, which have nothing behind them yet.
 */
export function Thread({ detail }: { detail: ConversationDetail }) {
    const { t } = useI18n();
    const { data: me } = useMe();
    const canReply = can(me, 'inbox.reply');
    const name = useContactName()(detail);
    const phone = displayPhone(detail.contact);
    const assign = useAssign(detail.id);
    const resolve = useResolve(detail.id);
    const { notify } = useToast();
    const [picking, setPicking] = useState(false);
    const assignees = useAssignees(canReply && picking);
    const mine = me !== undefined && me !== null && detail.assignee?.id === me.id;

    const doAssign = (userId: number | null) =>
        assign.mutate({ user_id: userId }, { onSuccess: () => notify(t('inbox.toast.assigned')), onError: () => notify(t('inbox.toast.failed'), 'danger') });
    const doResolve = (value: boolean) =>
        resolve.mutate(value, {
            onSuccess: () => notify(t(value ? 'inbox.toast.resolved' : 'inbox.toast.reopened')),
            onError: () => notify(t('inbox.toast.failed'), 'danger'),
        });

    return (
        <section aria-label={t('inbox.threadLabel', { name })} className="flex min-h-0 min-w-0 flex-1 flex-col bg-surface">
            <header className="flex items-center gap-3 border-b border-line-default px-4 py-3 lg:px-5">
                <Link to="/inbox" aria-label={t('inbox.back')} className="grid size-8 place-items-center rounded-sm text-fg-primary hover:bg-subtle lg:hidden">
                    <ArrowLeftIcon size={18} aria-hidden />
                </Link>
                <ContactAvatar name={name} />
                <div className="flex min-w-0 flex-1 flex-col">
                    <h2 lang={langProps(name).lang} className={`truncate t-admin-body-strong text-fg-primary ${langProps(name).className}`}>
                        {name}
                    </h2>
                    <p className="flex min-w-0 items-center gap-1 overflow-hidden t-admin-caption whitespace-nowrap text-fg-secondary">
                        <ChannelIcon channel={detail.channel} size={13} />
                        <span>{t(channelLabel(detail.channel))}</span>
                        {phone && <span className="tabular">· {phone}</span>}
                        {detail.assignee && <span className="min-w-0 truncate">· {mine ? t('inbox.assignedToMe') : t('inbox.assignedTo', { name: detail.assignee.name })}</span>}
                    </p>
                </div>
                {canReply && (
                    <div className="flex shrink-0 items-center gap-1.5">
                        {!mine && me && (
                            <Button variant="outline" size="sm" disabled={assign.isPending} onClick={() => doAssign(me.id)}>
                                <UserPlusIcon size={16} aria-hidden />
                                {t('inbox.assignToMe')}
                            </Button>
                        )}
                        {picking ? (
                            <select
                                aria-label={t('inbox.assignTo')}
                                className="h-8 rounded-sm border border-line-default bg-surface px-2 t-admin-caption text-fg-primary"
                                value={detail.assignee?.id ?? ''}
                                onChange={(e) => {
                                    setPicking(false);
                                    doAssign(e.target.value === '' ? null : Number(e.target.value));
                                }}
                                onBlur={() => setPicking(false)}
                            >
                                <option value="">{t('inbox.unassign')}</option>
                                {(assignees.data ?? []).map((a) => (
                                    <option key={a.id} value={a.id}>
                                        {a.name}
                                    </option>
                                ))}
                            </select>
                        ) : (
                            <IconButton variant="ghost" size="sm" aria-label={t('inbox.assignTo')} onClick={() => setPicking(true)}>
                                <UsersThreeIcon size={16} aria-hidden />
                            </IconButton>
                        )}
                        {detail.status === 'open' ? (
                            <Button variant="ghost" size="sm" disabled={resolve.isPending} onClick={() => doResolve(true)}>
                                <CheckIcon size={16} aria-hidden />
                                {t('inbox.resolve')}
                            </Button>
                        ) : (
                            <Button variant="ghost" size="sm" disabled={resolve.isPending} onClick={() => doResolve(false)}>
                                <ArrowCounterClockwiseIcon size={16} aria-hidden />
                                {t('inbox.reopen')}
                            </Button>
                        )}
                    </div>
                )}
            </header>

            <Messages conversationId={detail.id} detail={detail} />

            {canReply ? (
                <Composer detail={detail} />
            ) : (
                <p className="border-t border-line-default px-5 py-4 t-admin-caption text-fg-secondary">{t('inbox.composer.noPermission')}</p>
            )}
        </section>
    );
}

function Messages({ conversationId, detail }: { conversationId: number; detail: ConversationDetail }) {
    const { t } = useI18n();
    const messages = useMessages(conversationId);
    const scroller = useRef<HTMLDivElement>(null);
    const items = [...(messages.data?.pages ?? [])].reverse().flatMap((p) => [...p.data].reverse());
    const newest = items[items.length - 1]?.id;

    useLayoutEffect(() => {
        const el = scroller.current;
        if (el) el.scrollTop = el.scrollHeight;
    }, [newest]);

    return (
        <div ref={scroller} className="min-h-0 flex-1 overflow-y-auto px-4 py-4 lg:px-5" aria-live="polite">
            {messages.isError ? (
                <QueryError error={messages.error} onRetry={() => void messages.refetch()} />
            ) : (
                <ol className="flex flex-col gap-4">
                    {messages.hasNextPage && (
                        <li className="self-center">
                            <Button variant="ghost" size="sm" disabled={messages.isFetchingNextPage} onClick={() => void messages.fetchNextPage()}>
                                {t('inbox.olderMessages')}
                            </Button>
                        </li>
                    )}
                    {items.map((m) => (
                        <MessageItem key={m.id} message={m} channel={detail.channel} />
                    ))}
                </ol>
            )}
        </div>
    );
}

const failureKey = (code: string | null): MessageKey => {
    if (code === 'outside_window' || code === 'not_configured' || code === 'transport' || code === 'template_unavailable' || code === 'template_not_allowed') return `inbox.failure.${code}` as MessageKey;
    return 'inbox.failure.other';
};

export function MessageItem({ message: m, channel }: { message: InboxMessage; channel: ConversationDetail['channel'] }) {
    const { t, locale } = useI18n();
    const time = m.at ? dhakaTime(m.at, locale) : '';
    const body = langProps(m.body);

    if (m.kind === 'system') {
        const by = m.actor === 'customer' ? '' : (m.actor ?? '');
        const text =
            m.system_event === 'reopened' && m.actor === 'customer'
                ? t('inbox.system.reopenedByCustomer')
                : t(`inbox.system.${m.system_event ?? 'reopened'}` as MessageKey, { by, name: m.assignee_name ?? '' });
        return (
            <li className="flex justify-center">
                <span className="inline-flex max-w-full items-center gap-1.5 rounded-sm border border-line-default bg-canvas px-3 py-1.5 t-admin-caption text-fg-secondary">
                    <InfoIcon size={14} aria-hidden className="shrink-0" />
                    <span className="min-w-0 truncate">{text}</span>
                    <span className="shrink-0 tabular whitespace-nowrap text-fg-tertiary">· {time}</span>
                </span>
            </li>
        );
    }

    if (m.kind === 'note') {
        return (
            <li className="flex flex-col items-end gap-1" data-kind="note">
                <div className="max-w-[85%] rounded-md border border-dashed border-feedback-warning bg-warning-subtle px-4 py-3">
                    <p className="mb-1 flex items-center gap-1 t-admin-overline text-fg-secondary">
                        <EyeSlashIcon size={14} aria-hidden />
                        {t('inbox.msg.noteTitle')}
                    </p>
                    <p lang={body.lang} className={`t-admin-body whitespace-pre-wrap text-fg-primary ${body.className}`}>
                        {m.body}
                    </p>
                </div>
                <p className="t-admin-caption text-fg-secondary">{t('inbox.msg.noteMeta', { name: m.sender?.name ?? '' })}</p>
            </li>
        );
    }

    const inbound = m.direction === 'in';
    const content = m.body ?? t('inbox.msg.unsupported', { type: m.unsupported_type ?? '' });
    const failed = m.delivery_status === 'failed';

    return (
        <li className={`flex flex-col gap-1 ${inbound ? 'items-start' : 'items-end'}`} data-kind={inbound ? 'in' : 'out'}>
            <div className={`max-w-[85%] rounded-md px-4 py-3 ${inbound ? 'border border-line-default bg-surface' : 'bg-brand-subtle'} ${failed ? 'border border-line-danger' : ''}`}>
                {m.kind === 'template' && <p className="mb-1 t-admin-overline text-fg-secondary">{t('inbox.msg.template', { name: m.template?.name ?? '' })}</p>}
                <p lang={body.lang} className={`t-admin-body whitespace-pre-wrap break-words text-fg-primary ${m.body ? body.className : 'italic text-fg-secondary'}`}>
                    {content}
                </p>
            </div>
            <p className="flex items-center gap-1 t-admin-caption text-fg-secondary">
                {inbound ? (
                    <>
                        <span>{t(channelLabel(channel))}</span>
                        <span className="tabular">· {time}</span>
                    </>
                ) : (
                    <>
                        <span>{m.sender?.name ?? t('inbox.msg.you')}</span>
                        <span className="tabular">· {time}</span>
                        {m.tag === 'HUMAN_AGENT' && <span>· {t('inbox.msg.humanAgent')}</span>}
                        {m.delivery_status && (
                            <span className={failed ? 'inline-flex items-center gap-1 text-feedback-danger' : ''}>
                                {failed && <WarningCircleIcon size={14} aria-hidden />}· {t(`inbox.delivery.${m.delivery_status}` as MessageKey)}
                            </span>
                        )}
                    </>
                )}
            </p>
            {failed && <p className="t-admin-caption text-feedback-danger">{t(failureKey(m.failure_code))}</p>}
        </li>
    );
}

/** Why nothing can be sent (reply state `closed`). Messenger: 24 h passed with the human-agent tag off, or 7 days passed. */
function closedReason(detail: ConversationDetail): MessageKey {
    const closedAt = detail.reply.window_closes_at;
    if (detail.channel !== 'messenger' || !closedAt) return 'inbox.window.never';
    // The 24-hour window closed at `closedAt`; Meta's human-agent tag reaches 6 days further.
    return Date.now() < new Date(closedAt).getTime() + 6 * 86_400_000 ? 'inbox.closed.messenger' : 'inbox.closed.tooOld';
}

/**
 * Reply / Internal note. The server's reply state decides the reply side: `open` is a normal
 * reply; `human_agent` (Messenger 24 h – 7 days, switch on) is a normal reply with a notice that
 * it goes with Meta's human-agent tag; `template_only` (WhatsApp after the window) replaces the
 * reply box with {@see TemplatePanel}; `closed` disables it with the reason — on the web form
 * (inbound only) that is "reply by WhatsApp or call {phone}". A note is always allowed.
 */
export function Composer({ detail }: { detail: ConversationDetail }) {
    const { t, locale } = useI18n();
    const { notify } = useToast();
    const [mode, setMode] = useState<Mode>('reply');
    const [text, setText] = useState('');
    const [error, setError] = useState<string | null>(null);
    const reply = useSendReply(detail.id);
    const note = useAddNote(detail.id);
    const state = detail.reply.state;
    const replyBlocked = mode === 'reply' && !canReplyFreely(state);
    const pending = reply.isPending || note.isPending;

    useEffect(() => {
        setText('');
        setError(null);
    }, [detail.id]);

    const submit = (event?: FormEvent) => {
        event?.preventDefault();
        const body = text.trim();
        if (!body || replyBlocked || pending) return;
        const done = {
            onSuccess: () => {
                setText('');
                setError(null);
                notify(t(mode === 'reply' ? 'inbox.toast.sent' : 'inbox.toast.noted'));
            },
            onError: (e: unknown) => setError(e instanceof ApiError ? (e.problem.detail ?? e.fieldError('body') ?? t('inbox.toast.failed')) : t('inbox.toast.failed')),
        };
        if (mode === 'reply') reply.mutate({ body }, done);
        else note.mutate({ body }, done);
    };

    const onKeyDown = (e: KeyboardEvent<HTMLTextAreaElement>) => {
        if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) submit();
    };

    const closesAt = detail.reply.window_closes_at;
    const webFormPhone = displayPhone(detail.contact);
    const notice =
        mode === 'reply' && state === 'human_agent' && closesAt
            ? t('inbox.window.humanAgent', { time: dhakaDayTime(closesAt, locale) })
            : detail.window.applies && state === 'open' && closesAt
              ? t(detail.channel === 'messenger' ? 'inbox.window.openMessenger' : 'inbox.window.open', { time: dhakaDayTime(closesAt, locale) })
              : null;

    return (
        <div className="flex flex-col gap-2 border-t border-line-default px-4 py-3 lg:px-5">
            <div role="radiogroup" aria-label={t('inbox.composer.modes')} className="flex items-center gap-1">
                {(['reply', 'note'] as const).map((m) => (
                    <button
                        key={m}
                        type="button"
                        role="radio"
                        aria-checked={mode === m}
                        onClick={() => setMode(m)}
                        className={`h-7 rounded-sm px-2.5 ${mode === m ? 'bg-subtle t-admin-caption-strong text-fg-primary' : 't-admin-caption text-fg-secondary hover:text-fg-primary'}`}
                    >
                        {t(m === 'reply' ? 'inbox.composer.reply' : 'inbox.composer.note')}
                    </button>
                ))}
            </div>
            {mode === 'reply' && state === 'template_only' ? (
                <TemplatePanel detail={detail} />
            ) : (
                <form onSubmit={submit} className="flex flex-col gap-2">
                    {mode === 'reply' && state === 'closed' && isWebForm(detail.channel) && (
                        <p role="status" className="flex items-start gap-2 rounded-sm bg-info-subtle px-3 py-2 t-admin-caption text-fg-primary">
                            <EnvelopeSimpleIcon size={16} aria-hidden className="mt-px shrink-0 text-feedback-info" />
                            {webFormPhone ? t('inbox.closed.webForm', { phone: webFormPhone }) : t('inbox.closed.webFormNoPhone')}
                        </p>
                    )}
                    {mode === 'reply' && state === 'closed' && !isWebForm(detail.channel) && (
                        <p role="status" className="flex items-start gap-2 rounded-sm bg-warning-subtle px-3 py-2 t-admin-caption text-fg-primary">
                            <ClockIcon size={16} aria-hidden className="mt-px shrink-0 text-feedback-warning" />
                            {t(closedReason(detail))}
                        </p>
                    )}
                    {mode === 'reply' && state === 'human_agent' && (
                        <p role="status" className="flex items-start gap-2 rounded-sm bg-info-subtle px-3 py-2 t-admin-caption text-fg-primary">
                            <InfoIcon size={16} aria-hidden className="mt-px shrink-0 text-feedback-info" />
                            {t('inbox.humanAgent.notice')}
                        </p>
                    )}
                    <label className="sr-only" htmlFor={`composer-${detail.id}`}>
                        {t(mode === 'reply' ? 'inbox.composer.reply' : 'inbox.composer.note')}
                    </label>
                    <textarea
                        id={`composer-${detail.id}`}
                        rows={2}
                        value={text}
                        disabled={replyBlocked}
                        onChange={(e) => setText(e.target.value)}
                        onKeyDown={onKeyDown}
                        placeholder={t(mode === 'reply' ? 'inbox.composer.replyPlaceholder' : 'inbox.composer.notePlaceholder')}
                        aria-invalid={error ? true : undefined}
                        className={`min-h-15 w-full resize-y rounded-sm border-2 px-3 py-2 t-admin-body text-fg-primary outline-none placeholder:text-fg-tertiary focus:border-line-focus disabled:cursor-not-allowed disabled:bg-disabled ${
                            mode === 'note' ? 'border-dashed border-feedback-warning bg-warning-subtle' : 'border-line-brand bg-surface'
                        }`}
                    />
                    {error && <p className="t-admin-caption text-feedback-danger">{error}</p>}
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <p className="flex min-w-0 items-center gap-1.5 t-admin-caption text-fg-secondary">
                            {notice && (
                                <>
                                    <ChannelIcon channel={detail.channel} size={14} />
                                    <span className="truncate">{notice}</span>
                                </>
                            )}
                            {!notice && <span>{t('inbox.composer.sendHint')}</span>}
                        </p>
                        <Button type="submit" size="sm" variant={mode === 'note' ? 'outline' : 'primary'} disabled={!text.trim() || replyBlocked || pending}>
                            {mode === 'reply' && <PaperPlaneRightIcon size={16} aria-hidden />}
                            {t(mode === 'reply' ? 'inbox.composer.send' : 'inbox.composer.addNote')}
                        </Button>
                    </div>
                </form>
            )}
        </div>
    );
}
