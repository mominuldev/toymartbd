import { useState, type ReactNode } from 'react';
import { Link } from 'react-router';
import {
    ArrowsClockwiseIcon,
    CaretDownIcon,
    ChatsCircleIcon,
    CheckCircleIcon,
    CheckIcon,
    ClockIcon,
    CopyIcon,
    InfoIcon,
    KeyIcon,
    TrashIcon,
    WarningCircleIcon,
    XCircleIcon,
} from '@phosphor-icons/react';
import { ApiError } from '@/lib/api';
import { formatDhakaDate, timeAgo } from '@/lib/format';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { Button, buttonClass } from '@/components/ui/Button';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { IconButton } from '@/components/ui/IconButton';
import { StatusChip } from '@/components/ui/StatusChip';
import { useToast } from '@/components/ui/Toast';
import { useSaveInboxChannelSettings, useTestInboxChannel, type InboxChannelCredentials, type InboxChannelSettings } from '../api';
import { Switch } from '../Switch';
import { keepAll, useDisconnectChannel, type Channel } from './api';
import messengerLogo from './logos/messenger.svg';
import whatsappLogo from './logos/whatsapp.svg';

/** The keys form (Integrations › Messaging › Inbox channels) — "Enter keys manually" and "Keys & webhook". */
/** The channel's own keys form: its tab on Integrations › Messaging. */
export const keysLink = (channel: Channel) => `/integrations/messaging/${channel}#inbox-channel-settings`;

export const cardClass = 'rounded-md border border-line-default bg-surface shadow-e1';

/** Figma `logo/whatsapp` 613:2705 / `logo/messenger` 613:2708 on a surface tile. Source + licence: ./logos/LICENSE.md. */
export function LogoTile({ channel, size = 'lg' }: { channel: Channel; size?: 'md' | 'lg' }) {
    return (
        <span className={`flex shrink-0 items-center justify-center rounded-md border border-line-default bg-surface ${size === 'lg' ? 'size-12' : 'size-10'}`}>
            <img src={channel === 'whatsapp' ? whatsappLogo : messengerLogo} alt="" aria-hidden className={size === 'lg' ? 'size-7' : 'size-6'} />
        </span>
    );
}

/** Not connected yet (Figma "setup card"): intro, call to action and the numbered steps beside it. */
export function SetupCard({ channel, title, body, actions, footnote, steps }: { channel: Channel; title: string; body: string; actions: ReactNode; footnote: ReactNode; steps: { title: string; body: string }[] }) {
    return (
        <section aria-labelledby={`${channel}-setup`} className={`${cardClass} flex flex-col gap-8 p-6 lg:flex-row`}>
            <div className="flex flex-col gap-4 lg:w-105 lg:shrink-0">
                <LogoTile channel={channel} />
                <div className="flex flex-col gap-2">
                    <h2 id={`${channel}-setup`} className="t-admin-section text-fg-primary">
                        {title}
                    </h2>
                    <p className="t-admin-body text-fg-secondary">{body}</p>
                </div>
                <div className="flex flex-wrap gap-3">{actions}</div>
                <p className="flex items-start gap-1 t-admin-caption text-fg-tertiary">
                    <ClockIcon size={14} aria-hidden className="mt-0.5 shrink-0" />
                    <span>{footnote}</span>
                </p>
            </div>
            <ol className="flex min-w-0 flex-1 flex-col">
                {steps.map((step, index) => (
                    <li key={step.title} className="flex gap-3 border-b border-line-default py-3 last:border-b-0">
                        <span aria-hidden className="flex size-6 shrink-0 items-center justify-center rounded-full bg-brand-subtle t-admin-caption-strong text-fg-brand">
                            {index + 1}
                        </span>
                        <span className="flex min-w-0 flex-col">
                            <span className="t-admin-body-strong text-fg-primary">{step.title}</span>
                            <span className="t-admin-body text-fg-secondary">{step.body}</span>
                        </span>
                    </li>
                ))}
            </ol>
        </section>
    );
}

/** "Good to know before you start" — open by default, a native disclosure. */
export function GoodToKnow({ tips }: { tips: string[] }) {
    const { t } = useI18n();
    return (
        <details open className="group rounded-md bg-info-subtle p-4">
            <summary className="flex cursor-pointer list-none items-center gap-2 t-admin-body-strong text-fg-primary [&::-webkit-details-marker]:hidden">
                <InfoIcon size={18} aria-hidden className="shrink-0 text-feedback-info" />
                <span className="flex-1">{t('channels.goodToKnow')}</span>
                <CaretDownIcon size={16} aria-hidden className="shrink-0 text-fg-secondary transition-transform duration-150 ease-standard group-open:rotate-180" />
            </summary>
            <ol className="mt-3 flex list-decimal flex-col gap-2 ps-12 t-admin-body text-fg-secondary marker:t-admin-caption-strong">
                {tips.map((tip) => (
                    <li key={tip}>{tip}</li>
                ))}
            </ol>
        </details>
    );
}

/** "Prefer to paste the keys?" — the dashed card that opens the keys form. */
export function ManualKeysCard({ channel }: { channel: Channel }) {
    const { t } = useI18n();
    return (
        <section className="flex flex-col gap-4 rounded-md border border-dashed border-line-strong bg-surface p-4 sm:flex-row sm:items-center">
            <span className="flex size-11 shrink-0 items-center justify-center rounded-md bg-subtle">
                <KeyIcon size={22} aria-hidden className="text-fg-secondary" />
            </span>
            <div className="flex min-w-0 flex-1 flex-col">
                <h2 className="t-admin-body-strong text-fg-primary">{t('channels.manual.title')}</h2>
                <p className="t-admin-body text-fg-secondary">{t(`channels.manual.body.${channel}`)}</p>
            </div>
            <Link to={keysLink(channel)} className={buttonClass('outline', 'md')}>
                {t('channels.manual.button')}
            </Link>
        </section>
    );
}

type ConnectedCardProps = {
    channel: Channel;
    data: InboxChannelCredentials;
    settings: InboxChannelSettings;
    title: string;
    meta: string;
    chips?: ReactNode;
    /** Messenger: Reconnect Page. WhatsApp: Message templates. */
    extraAction: ReactNode;
    /** The fix offered inside the Reconnect-needed box. */
    reconnectAction: ReactNode;
};

/** A connected Page / number (Figma "Number card"): identity, reply switch, counts, actions. */
export function ConnectedCard({ channel, data, settings, title, meta, chips, extraAction, reconnectAction }: ConnectedCardProps) {
    const { t, locale } = useI18n();
    const { notify } = useToast();
    const save = useSaveInboxChannelSettings();
    const test = useTestInboxChannel();
    const disconnect = useDisconnectChannel();
    const [confirming, setConfirming] = useState(false);
    const sending = channel === 'whatsapp' ? data.driver === 'cloud' : data.driver === 'messenger';
    const reconnect = data.connection.state === 'reconnect';

    const toggleReplies = (on: boolean) => {
        const driver = on ? (channel === 'whatsapp' ? 'cloud' : 'messenger') : 'log';
        save.mutate(
            { ...keepAll(settings), [`${channel}_driver`]: driver },
            {
                onSuccess: () => notify(on ? t('channels.sendReplies.on', { channel: t(`channels.${channel}.title`) }) : t('channels.sendReplies.off')),
                onError: (error) => notify(error instanceof ApiError ? (error.problem.detail ?? error.problem.title) : t('error.title'), 'danger'),
            },
        );
    };

    const runTest = () =>
        test.mutate(channel, {
            onError: (error) => notify(error instanceof ApiError && error.status === 429 ? t('channels.check.tooMany') : error instanceof ApiError ? error.problem.title : t('error.title'), 'danger'),
        });

    const a = data.activity;

    return (
        <section aria-labelledby={`${channel}-connected`} className={`${cardClass} flex flex-col gap-5 p-6`} data-channel-card={channel}>
            <div className="flex flex-col gap-4 md:flex-row md:items-center">
                <LogoTile channel={channel} />
                <div className="flex min-w-0 flex-1 flex-col gap-1">
                    <div className="flex flex-wrap items-center gap-3">
                        <h2 id={`${channel}-connected`} className="t-admin-section break-all text-fg-primary">
                            {title}
                        </h2>
                        <StatusChip tone={reconnect ? 'warning' : 'success'} label={t(reconnect ? 'channels.state.reconnect' : 'channels.state.connected')} />
                        {chips}
                    </div>
                    <p className="t-admin-caption break-words text-fg-tertiary">{meta}</p>
                </div>
                <div className="flex items-start gap-4 md:max-w-80">
                    <div className="flex min-w-0 flex-col">
                        <span className="t-admin-body text-fg-primary">{t(`channels.sendReplies.${channel}`)}</span>
                        <span className="t-admin-caption text-fg-secondary">{t('channels.sendReplies.hint')}</span>
                    </div>
                    <Switch checked={sending} label={t(`channels.sendReplies.${channel}`)} onChange={toggleReplies} />
                </div>
            </div>

            {reconnect && (
                <div role="alert" className="flex flex-col gap-3 rounded-md bg-warning-subtle p-4 sm:flex-row sm:items-center">
                    <WarningCircleIcon size={20} aria-hidden className="shrink-0 text-feedback-warning" />
                    <div className="flex min-w-0 flex-1 flex-col">
                        <span className="t-admin-body-strong text-fg-primary">{t(`channels.reconnect.title.${channel}`)}</span>
                        <span className="t-admin-body text-fg-secondary">
                            {t(`channels.reconnect.body.${channel}`, { when: data.connection.since ? formatDhakaDate(data.connection.since, locale) : '—' })}
                        </span>
                    </div>
                    {reconnectAction}
                </div>
            )}

            <dl className="grid grid-cols-2 gap-x-6 gap-y-4 border-y border-line-default py-4 lg:grid-cols-4">
                <Stat label={t('channels.stat.open')} value={String(a.open)} caption={t(`channels.stat.openCaption.${channel}`)} />
                <Stat
                    label={t('channels.stat.waiting')}
                    value={String(a.waiting)}
                    caption={a.oldest_waiting_at ? t('channels.stat.oldest', { age: timeAgo(a.oldest_waiting_at, locale) }) : t('channels.stat.noneWaiting')}
                />
                <Stat label={t('channels.stat.unread')} value={String(a.unread)} caption={t('channels.stat.unreadCaption')} />
                <Stat
                    label={t('channels.stat.last')}
                    value={a.last_inbound_at ? timeAgo(a.last_inbound_at, locale) : t('channels.stat.lastNever')}
                    caption={a.last_inbound_from ? t('channels.stat.lastFrom', { name: a.last_inbound_from }) : ''}
                />
            </dl>

            <div className="flex flex-wrap items-center gap-2">
                <Link to={`/inbox?channel=${channel}`} className={`${buttonClass('outline', 'md')} gap-2`}>
                    <ChatsCircleIcon size={16} aria-hidden />
                    {t('channels.action.openChats')}
                </Link>
                <Button variant="outline" className="gap-2" disabled={test.isPending} aria-busy={test.isPending || undefined} onClick={runTest} data-test-connection={channel}>
                    <ArrowsClockwiseIcon size={16} aria-hidden />
                    {test.isPending ? t('channels.action.testing') : t('channels.action.test')}
                </Button>
                {extraAction}
                <Link to={keysLink(channel)} className={`${buttonClass('outline', 'md')} gap-2`}>
                    <KeyIcon size={16} aria-hidden />
                    {t('channels.action.keys')}
                </Link>
                <Button variant="ghost" className="gap-2 sm:ms-auto" onClick={() => setConfirming(true)}>
                    <TrashIcon size={16} aria-hidden />
                    {t('channels.action.disconnect')}
                </Button>
            </div>

            <ConfirmDialog
                open={confirming}
                title={t(channel === 'whatsapp' ? 'channels.wa.disconnect.title' : 'channels.fb.disconnect.title')}
                body={t(channel === 'whatsapp' ? 'channels.wa.disconnect.body' : 'channels.fb.disconnect.body')}
                confirmLabel={t('channels.action.disconnect')}
                pending={disconnect.isPending}
                onClose={() => setConfirming(false)}
                onConfirm={() =>
                    disconnect.mutate(channel, {
                        onSuccess: () => {
                            setConfirming(false);
                            notify(t(channel === 'whatsapp' ? 'channels.wa.disconnected' : 'channels.fb.disconnected'));
                        },
                        onError: (error) => notify(error instanceof ApiError ? error.problem.title : t('error.title'), 'danger'),
                    })
                }
            />
        </section>
    );
}

function Stat({ label, value, caption }: { label: string; value: string; caption: string }) {
    return (
        <div className="flex min-w-0 flex-col gap-1">
            <dt className="t-admin-caption text-fg-secondary">{label}</dt>
            <dd className="t-admin-num-lg text-fg-primary">{value}</dd>
            {caption && <dd className="t-admin-caption truncate text-fg-tertiary">{caption}</dd>}
        </div>
    );
}

/** The last "Test connection", in a sentence. Never marks the channel verified. */
export function LastCheckLine({ channel, data }: { channel: Channel; data: InboxChannelCredentials }) {
    const { t, locale } = useI18n();
    const check = data.last_check;
    if (!check) {
        return <p className="t-admin-caption text-fg-secondary">{t('channels.check.never')}</p>;
    }
    const when = timeAgo(check.checked_at, locale);
    let text: string;
    if (check.ok) {
        const name = check.display_name ?? '—';
        text =
            channel === 'whatsapp'
                ? t('channels.check.okNumber', { when, name, number: check.display_number ?? '—', quality: qualityLabel(t, check.quality_rating) })
                : t('channels.check.okPage', { when, name });
    } else {
        text = t('channels.check.failed', { when, reason: reasonText(t, check.error_code, check.error_message) });
    }
    return (
        <p role="status" data-last-check={channel} className="flex items-start gap-2 t-admin-caption text-fg-secondary">
            {check.ok ? (
                <CheckCircleIcon size={16} aria-hidden className="shrink-0 text-feedback-success" />
            ) : (
                <XCircleIcon size={16} aria-hidden className="shrink-0 text-feedback-danger" />
            )}
            <span className="min-w-0 break-words">{text}</span>
        </p>
    );
}

type T = ReturnType<typeof useI18n>['t'];

export function qualityLabel(t: T, rating: string | null): string {
    return rating && ['GREEN', 'YELLOW', 'RED', 'NA'].includes(rating) ? t(`channels.quality.${rating}` as MessageKey) : (rating ?? '—');
}

export function reasonText(t: T, code: string | null, message: string | null): string {
    const known = code && ['not_configured', 'unreachable', 'not_subscribed', 'meta_190'].includes(code) ? t(`channels.reason.${code}` as MessageKey) : null;
    return [known ?? code, message].filter((part) => part !== null && part !== '').join(' ');
}

/** "Incoming messages": the callback URL to give Meta, the verify token as a hint, and the last delivery. */
export function WebhookCard({ channel, data }: { channel: Channel; data: InboxChannelCredentials }) {
    const { t, locale } = useI18n();
    const last = data.activity.last_event_at;
    return (
        <section aria-labelledby={`${channel}-webhook`} className={`${cardClass} flex flex-col gap-4 p-5`}>
            <div className="flex flex-col gap-2 sm:flex-row sm:items-start">
                <div className="flex min-w-0 flex-1 flex-col">
                    <h2 id={`${channel}-webhook`} className="t-admin-body-strong text-fg-primary">
                        {t('channels.webhook.title')}
                    </h2>
                    <p className="t-admin-caption text-fg-secondary">{t(`channels.webhook.hint.${channel}`)}</p>
                </div>
                {!data.webhook_ready ? (
                    <StatusChip tone="warning" label={t('channels.webhook.notReady')} />
                ) : last ? (
                    <StatusChip tone="success" label={t('channels.webhook.receiving', { when: timeAgo(last, locale) })} />
                ) : (
                    <StatusChip tone="neutral" label={t('channels.webhook.waiting')} />
                )}
            </div>
            <div className="grid gap-4 md:grid-cols-2">
                <CopyBox label={t('channels.webhook.callback')} value={data.webhook_url} copy />
                <CopyBox
                    label={t('channels.webhook.verifyToken')}
                    value={data.verify_token.set ? t('channels.webhook.saved', { hint: data.verify_token.hint ?? '••••' }) : t('channels.webhook.missing')}
                />
            </div>
        </section>
    );
}

export function CopyBox({ label, value, copy = false }: { label: string; value: string; copy?: boolean }) {
    const { t } = useI18n();
    const [copied, setCopied] = useState(false);
    const onCopy = async () => {
        try {
            await navigator.clipboard.writeText(value);
            setCopied(true);
            window.setTimeout(() => setCopied(false), 2000);
        } catch {
            /* clipboard refused: the value stays selectable */
        }
    };
    return (
        <div className="flex min-w-0 flex-col gap-1">
            <span className="t-admin-caption-strong text-fg-secondary">{label}</span>
            <div className="flex min-h-10 items-center gap-2 rounded-sm bg-subtle ps-3 pe-1">
                <code className="min-w-0 flex-1 truncate py-2 t-admin-mono-sm text-fg-primary">{value}</code>
                {copy && (
                    <IconButton size="sm" aria-label={copied ? t('channels.copied') : t('channels.copy', { what: label })} onClick={() => void onCopy()}>
                        {copied ? <CheckIcon size={16} aria-hidden className="text-feedback-success" /> : <CopyIcon size={16} aria-hidden />}
                    </IconButton>
                )}
            </div>
        </div>
    );
}
