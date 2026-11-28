import { useEffect, useRef, useState } from 'react';
import { Link, useSearchParams } from 'react-router';
import { FacebookLogoIcon, PlusIcon } from '@phosphor-icons/react';
import { ApiError } from '@/lib/api';
import { formatDhakaDate } from '@/lib/format';
import { useI18n } from '@/i18n/I18nProvider';
import { can, useMe } from '@/features/auth/api';
import { Button, buttonClass } from '@/components/ui/Button';
import { ContentHeader } from '@/components/shell/ContentHeader';
import { QueryError } from '@/components/ui/QueryError';
import { useToast } from '@/components/ui/Toast';
import { useInboxChannelSettings, useSaveInboxChannelSettings, type InboxChannelSettings } from '../api';
import { Switch } from '../Switch';
import { keepAll, useConnectMessengerPage, useFinishMessengerLogin, useStartMessengerLogin, type MessengerLoginPages } from './api';
import { ConnectedCard, GoodToKnow, keysLink, LastCheckLine, ManualKeysCard, SetupCard, WebhookCard, cardClass } from './parts';

const PERMISSION = 'settings.manage_integrations';

/**
 * Inbox › Channels › Facebook Page (Figma B · 44: Not connected 627:51843, Connected 627:51411,
 * Reconnect needed 629:52143, Meta app not added 629:52249), modelled on SSFlows' Agent Pages.
 *
 * "Continue with Facebook" is Meta's manual login flow on Smart Junior's OWN Meta app: the server
 * builds the dialog URL, Facebook sends the owner back HERE with `?code&state`, and this page
 * hands both to the server, which exchanges them and lists the Pages that were shared. Page
 * tokens never reach the browser. One Page shared → connected at once; several → a chooser.
 */
export function MessengerChannelPage() {
    const { t } = useI18n();
    const { data: me } = useMe();
    const allowed = can(me, PERMISSION);
    const settings = useInboxChannelSettings(allowed);

    if (me && !allowed) {
        return <p className="t-admin-body text-fg-secondary">{t('error.forbidden')}</p>;
    }

    return (
        <div className="flex w-full min-w-0 flex-col gap-6">
            <ContentHeader
                title={t('channels.messenger.title')}
                breadcrumb={[{ label: t('nav.integrations'), to: '/integrations' }, { label: t('nav.integrations.messenger') }]}
                subtitle={t('channels.messenger.subtitle')}
            />
            {settings.isError ? (
                <QueryError error={settings.error} onRetry={() => void settings.refetch()} />
            ) : !settings.data ? (
                <p aria-busy="true" className="sr-only" />
            ) : (
                <MessengerChannel settings={settings.data} />
            )}
        </div>
    );
}

function MessengerChannel({ settings }: { settings: InboxChannelSettings }) {
    const { t, locale } = useI18n();
    const data = settings.messenger;
    const login = useFacebookLogin();
    const connected = data.connection.state !== 'not_connected';

    if (login.pages) {
        return <PageChooser pages={login.pages} onDone={login.reset} />;
    }

    const notice = login.message && (
        <p role={login.messageTone === 'danger' ? 'alert' : 'status'} className={`rounded-md p-4 t-admin-body ${login.messageTone === 'danger' ? 'bg-danger-subtle text-fg-primary' : 'bg-subtle text-fg-secondary'}`}>
            {login.message}
        </p>
    );

    if (connected) {
        const name = data.page_name ?? data.last_check?.display_name ?? t('channels.fb.pageId', { id: data.account_id ?? '' });
        const meta = [
            t('channels.fb.pageId', { id: data.account_id ?? '—' }),
            data.connected_at
                ? data.connected_by
                    ? t('channels.fb.connectedBy', { name: data.connected_by, date: formatDhakaDate(data.connected_at, locale) })
                    : t('channels.fb.connectedOn', { date: formatDhakaDate(data.connected_at, locale) })
                : null,
        ]
            .filter(Boolean)
            .join('  ·  ');
        const reconnect = (variant: 'primary' | 'outline') => (
            <Button variant={variant} className="gap-2" disabled={!data.login_ready || login.starting} aria-busy={login.starting || undefined} onClick={login.start}>
                <FacebookLogoIcon size={16} aria-hidden />
                {t('channels.action.reconnect')}
            </Button>
        );
        return (
            <>
                {notice}
                <ConnectedCard channel="messenger" data={data} settings={settings} title={name} meta={meta} extraAction={reconnect('outline')} reconnectAction={reconnect('primary')} />
                <LastCheckLine channel="messenger" data={data} />
                <LateReplies settings={settings} />
                <WebhookCard channel="messenger" data={data} />
            </>
        );
    }

    return (
        <>
            {notice}
            <SetupCard
                channel="messenger"
                title={t('channels.fb.connectTitle')}
                body={t('channels.fb.connectBody')}
                actions={
                    <>
                        <Button size="lg" className="gap-2" disabled={!data.login_ready || login.starting} aria-busy={login.starting || undefined} onClick={login.start}>
                            <FacebookLogoIcon size={20} aria-hidden />
                            {login.starting ? t('channels.fb.opening') : t('channels.fb.continue')}
                        </Button>
                        {!data.login_ready && (
                            <Link to={keysLink('messenger')} className={`${buttonClass('outline', 'lg')} gap-2`}>
                                <PlusIcon size={20} aria-hidden />
                                {t('channels.fb.addApp')}
                            </Link>
                        )}
                    </>
                }
                footnote={data.login_ready ? t('channels.fb.time') : t('channels.fb.needsApp')}
                steps={[1, 2, 3, 4].map((n) => ({ title: t(`channels.fb.step${n}.title` as 'channels.fb.step1.title'), body: t(`channels.fb.step${n}.body` as 'channels.fb.step1.body') }))}
            />
            {data.oauth_redirect_uri && <p className="t-admin-caption break-all text-fg-tertiary">{t('channels.fb.redirectNote', { uri: data.oauth_redirect_uri })}</p>}
            <GoodToKnow tips={[1, 2, 3, 4, 5].map((n) => t(`channels.fb.tip${n}` as 'channels.fb.tip1'))} />
            <ManualKeysCard channel="messenger" />
        </>
    );
}

/**
 * Starting the login (navigate to Facebook) and finishing it when Facebook sends the owner back
 * with `?code&state` (or `?error=access_denied` when they closed the window). The query is
 * removed from the address bar straight away, so a reload cannot replay a used code.
 */
function useFacebookLogin() {
    const { t } = useI18n();
    const [params, setParams] = useSearchParams();
    const start = useStartMessengerLogin();
    const finish = useFinishMessengerLogin();
    const [pages, setPages] = useState<MessengerLoginPages | null>(null);
    const [message, setMessage] = useState<{ text: string; tone: 'danger' | 'neutral' } | null>(null);
    const handled = useRef(false);

    useEffect(() => {
        if (handled.current) return;
        const code = params.get('code');
        const state = params.get('state');
        const error = params.get('error');
        if (!code && !error) return;
        handled.current = true;
        setParams({}, { replace: true });
        if (error || !code || !state) {
            setMessage({ text: t('channels.fb.cancelled'), tone: 'neutral' });
            return;
        }
        setMessage({ text: t('channels.fb.finishing'), tone: 'neutral' });
        finish.mutate(
            { code, state },
            {
                onSuccess: (result) => {
                    setMessage(null);
                    setPages(result);
                },
                onError: (failure) => setMessage({ text: failure instanceof ApiError ? (failure.problem.detail ?? failure.problem.title) : t('error.title'), tone: 'danger' }),
            },
        );
    }, [params, setParams, finish, t]);

    return {
        pages,
        reset: (text?: string) => {
            setPages(null);
            setMessage(text ? { text, tone: 'neutral' } : null);
        },
        starting: start.isPending || start.isSuccess,
        start: () => {
            setMessage(null);
            start.mutate(undefined, {
                onSuccess: (result) => window.location.assign(result.url),
                onError: (failure) => setMessage({ text: failure instanceof ApiError ? (failure.problem.detail ?? failure.problem.title) : t('error.title'), tone: 'danger' }),
            });
        },
        message: message?.text ?? null,
        messageTone: message?.tone ?? 'neutral',
    };
}

/** After Facebook: one Page that can message → connected at once; otherwise choose. */
function PageChooser({ pages, onDone }: { pages: MessengerLoginPages; onDone: (message?: string) => void }) {
    const { t } = useI18n();
    const { notify } = useToast();
    const connect = useConnectMessengerPage();
    const [error, setError] = useState<string | null>(null);
    const auto = useRef(false);
    const usable = pages.pages.filter((page) => page.can_message);

    const choose = (pageId: string, name: string) => {
        setError(null);
        connect.mutate(
            { session: pages.session, page_id: pageId },
            {
                onSuccess: () => {
                    notify(t('channels.fb.connected', { name }));
                    onDone();
                },
                onError: (failure) => setError(failure instanceof ApiError ? (failure.problem.detail ?? failure.problem.title) : t('error.title')),
            },
        );
    };

    useEffect(() => {
        const only = usable[0];
        if (auto.current || pages.pages.length !== 1 || !only) return;
        auto.current = true;
        choose(only.id, only.name);
        // Runs once for the single shared Page.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    return (
        <section aria-labelledby="fb-choose" className={`${cardClass} flex flex-col gap-4 p-6`}>
            <div className="flex flex-col gap-1">
                <h2 id="fb-choose" className="t-admin-section text-fg-primary">
                    {t('channels.fb.choose.title')}
                </h2>
                <p className="t-admin-body text-fg-secondary">{t('channels.fb.choose.body')}</p>
            </div>
            <ul className="flex flex-col divide-y divide-line-default rounded-md border border-line-default">
                {pages.pages.map((page) => (
                    <li key={page.id} className="flex flex-col gap-2 p-4 sm:flex-row sm:items-center">
                        <div className="flex min-w-0 flex-1 flex-col">
                            <span className="t-admin-body-strong text-fg-primary">{page.name}</span>
                            <span className="t-admin-caption text-fg-tertiary">{page.can_message ? t('channels.fb.pageId', { id: page.id }) : t('channels.fb.choose.cannotMessage')}</span>
                        </div>
                        <Button disabled={!page.can_message || connect.isPending} aria-busy={connect.isPending || undefined} onClick={() => choose(page.id, page.name)}>
                            {t('channels.fb.choose.connect')}
                        </Button>
                    </li>
                ))}
            </ul>
            {error && (
                <p role="alert" className="t-admin-body text-feedback-danger">
                    {error}
                </p>
            )}
            <div>
                <Button variant="ghost" onClick={() => onDone()}>
                    {t('channels.fb.choose.cancel')}
                </Button>
            </div>
        </section>
    );
}

/** Messenger HUMAN_AGENT switch (Figma "Late replies"). */
function LateReplies({ settings }: { settings: InboxChannelSettings }) {
    const { t } = useI18n();
    const { notify } = useToast();
    const save = useSaveInboxChannelSettings();
    return (
        <section className={`${cardClass} flex items-start gap-4 p-5`}>
            <div className="flex min-w-0 flex-1 flex-col">
                <span className="t-admin-body text-fg-primary">{t('channels.late.title')}</span>
                <span className="t-admin-caption text-fg-secondary">{t('channels.late.hint')}</span>
            </div>
            <Switch
                checked={settings.messenger.human_agent}
                label={t('channels.late.title')}
                onChange={(value) =>
                    save.mutate(
                        { ...keepAll(settings), messenger_human_agent: value },
                        {
                            onSuccess: () => notify(t('channels.late.saved')),
                            onError: (error) => notify(error instanceof ApiError ? error.problem.title : t('error.title'), 'danger'),
                        },
                    )
                }
            />
        </section>
    );
}
