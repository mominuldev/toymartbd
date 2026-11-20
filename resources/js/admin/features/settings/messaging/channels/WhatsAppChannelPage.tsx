import { useEffect, useId, useRef, useState } from 'react';
import { Link } from 'react-router';
import { ArrowSquareOutIcon, CaretLeftIcon, CaretRightIcon, CheckCircleIcon, FileTextIcon, InfoIcon, KeyIcon, XIcon } from '@phosphor-icons/react';
import { ApiError } from '@/lib/api';
import { useI18n } from '@/i18n/I18nProvider';
import { can, useMe } from '@/features/auth/api';
import { Button, buttonClass } from '@/components/ui/Button';
import { ContentHeader } from '@/components/shell/ContentHeader';
import { Field, monoInputClass } from '@/components/ui/Field';
import { IconButton } from '@/components/ui/IconButton';
import { QueryError } from '@/components/ui/QueryError';
import { StatusChip } from '@/components/ui/StatusChip';
import { useToast } from '@/components/ui/Toast';
import { useInboxChannelSettings, useSaveInboxChannelSettings, useTestInboxChannel, type InboxChannelSettings, type InboxChannelSettingsInput } from '../api';
import { keepAll } from './api';
import { ConnectedCard, CopyBox, GoodToKnow, KEYS_LINK, LastCheckLine, ManualKeysCard, SetupCard, WebhookCard, qualityLabel, reasonText } from './parts';

const PERMISSION = 'settings.manage_integrations';
const DIGITS = /^\d{1,32}$/;

/** Meta's own pages for each step (developers.facebook.com, business.facebook.com — read 2026-11-20). */
const LINKS = {
    developers: 'https://developers.facebook.com/apps/',
    // API Setup lives inside the app (its id is in the URL), so the app list is the nearest page.
    apiSetup: 'https://developers.facebook.com/apps/',
    systemUsers: 'https://business.facebook.com/settings/system-users',
} as const;

/**
 * Inbox › Channels › WhatsApp (Figma B · 43: Not connected 623:50927, Connected 626:51147, Setup
 * guide 628:51892), modelled on SSFlows' WhatsApp screen — but a GUIDED paste-the-keys setup, not
 * Meta's Embedded Signup: that and Business-app "coexistence" are for Tech Providers only, and a
 * shop on its own number is a direct developer with a system-user token (owner decision 2026-11-20).
 *
 * The guide saves each step through `PUT /settings/inbox-channels` (so closing it loses nothing),
 * and its last step runs Test connection; replies switch to the Cloud API only when Meta answers.
 */
export function WhatsAppChannelPage() {
    const { t } = useI18n();
    const { data: me } = useMe();
    const allowed = can(me, PERMISSION);
    const settings = useInboxChannelSettings(allowed);
    const [guide, setGuide] = useState(false);

    if (me && !allowed) {
        return <p className="t-admin-body text-fg-secondary">{t('error.forbidden')}</p>;
    }

    return (
        <div className="flex w-full min-w-0 flex-col gap-6">
            <ContentHeader
                title={t('channels.whatsapp.title')}
                breadcrumb={[{ label: t('channels.crumb.inbox'), to: '/inbox' }, { label: t('channels.crumb.channels') }]}
                subtitle={t('channels.whatsapp.subtitle')}
            />
            {settings.isError ? (
                <QueryError error={settings.error} onRetry={() => void settings.refetch()} />
            ) : !settings.data ? (
                <p aria-busy="true" className="sr-only" />
            ) : (
                <>
                    <WhatsAppChannel settings={settings.data} onStart={() => setGuide(true)} />
                    <SetupGuide open={guide} settings={settings.data} onClose={() => setGuide(false)} />
                </>
            )}
        </div>
    );
}

/** Connected once Meta has answered for these keys, or replies already go through the Cloud API. */
function isConnected(s: InboxChannelSettings): boolean {
    const w = s.whatsapp;
    return w.connection.state === 'reconnect' || (w.connection.state === 'connected' && (w.driver === 'cloud' || w.last_check?.ok === true));
}

function WhatsAppChannel({ settings, onStart }: { settings: InboxChannelSettings; onStart: () => void }) {
    const { t } = useI18n();
    const data = settings.whatsapp;

    if (isConnected(settings)) {
        const check = data.last_check?.ok ? data.last_check : null;
        const title = check?.display_number ?? data.account_id ?? '—';
        const meta = [
            t('channels.wa.meta', { name: check?.display_name ?? t('channels.whatsapp.title'), id: data.account_id ?? '—' }),
            data.business_account_id ? t('channels.wa.waba', { id: data.business_account_id }) : null,
        ]
            .filter(Boolean)
            .join('  ·  ');
        return (
            <>
                <ConnectedCard
                    channel="whatsapp"
                    data={data}
                    settings={settings}
                    title={title}
                    meta={meta}
                    chips={check?.quality_rating ? <StatusChip tone="neutral" showIcon={false} label={t('channels.quality', { rating: qualityLabel(t, check.quality_rating) })} /> : null}
                    extraAction={
                        <Link to="/automations/templates" className={`${buttonClass('outline', 'md')} gap-2`}>
                            <FileTextIcon size={16} aria-hidden />
                            {t('channels.action.templates')}
                        </Link>
                    }
                    reconnectAction={
                        <Link to={KEYS_LINK} className={`${buttonClass('primary', 'md')} gap-2`}>
                            <KeyIcon size={16} aria-hidden />
                            {t('channels.reconnect.updateToken')}
                        </Link>
                    }
                />
                <LastCheckLine channel="whatsapp" data={data} />
                <WebhookCard channel="whatsapp" data={data} />
            </>
        );
    }

    const begun = data.app_secret.set || data.account_id !== null || data.send_token.set;
    return (
        <>
            <SetupCard
                channel="whatsapp"
                title={t('channels.wa.connectTitle')}
                body={t('channels.wa.connectBody')}
                actions={
                    <Button size="lg" className="gap-2" onClick={onStart}>
                        {t(begun ? 'channels.wa.continue' : 'channels.wa.start')}
                        <CaretRightIcon size={20} aria-hidden />
                    </Button>
                }
                footnote={t('channels.wa.time')}
                steps={[1, 2, 3, 4].map((n) => ({ title: t(`channels.wa.step${n}.title` as 'channels.wa.step1.title'), body: t(`channels.wa.step${n}.body` as 'channels.wa.step1.body') }))}
            />
            <GoodToKnow tips={[1, 2, 3, 4, 5].map((n) => t(`channels.wa.tip${n}` as 'channels.wa.tip1'))} />
            <ManualKeysCard channel="whatsapp" />
        </>
    );
}

type Step = 1 | 2 | 3 | 4;

/** A random verify token for Meta's webhook handshake — made here, shown once, then saved encrypted. */
function makeVerifyToken(): string {
    const bytes = new Uint8Array(24);
    crypto.getRandomValues(bytes);
    return Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('');
}

/** Figma 628:51892: the four-step guide in a side drawer. */
function SetupGuide({ open, settings, onClose }: { open: boolean; settings: InboxChannelSettings; onClose: () => void }) {
    const { t } = useI18n();
    const { notify } = useToast();
    const ref = useRef<HTMLDialogElement>(null);
    const titleId = useId();
    const save = useSaveInboxChannelSettings();
    const test = useTestInboxChannel();
    const w = settings.whatsapp;

    const [step, setStep] = useState<Step>(1);
    const [secret, setSecret] = useState('');
    const [phoneId, setPhoneId] = useState(w.account_id_source === 'settings' ? (w.account_id ?? '') : '');
    const [wabaId, setWabaId] = useState(w.business_account_id_source === 'settings' ? (w.business_account_id ?? '') : '');
    const [token, setToken] = useState('');
    const [verify, setVerify] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [failure, setFailure] = useState<string | null>(null);

    useEffect(() => {
        const dialog = ref.current;
        if (!dialog) return;
        if (open && !dialog.open) {
            if (typeof dialog.showModal === 'function') dialog.showModal();
            else dialog.setAttribute('open', '');
            // Resume where the saved keys leave off.
            setStep(!w.app_secret.set ? 1 : !w.account_id ? 2 : !w.send_token.set ? 3 : 4);
        }
        if (!open && dialog.open) {
            if (typeof dialog.close === 'function') dialog.close();
            else dialog.removeAttribute('open');
        }
        // Only on open/close; the saved keys are read at that moment.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    const put = (change: Partial<InboxChannelSettingsInput>, next: () => void) => {
        setError(null);
        save.mutate(
            { ...keepAll(settings), ...change },
            {
                onSuccess: next,
                onError: (e) => setError(e instanceof ApiError ? (Object.values(change).length && e.problem.errors?.[0]?.message) || e.problem.detail || e.problem.title : t('error.title')),
            },
        );
    };

    const next = () => {
        setError(null);
        if (step === 1) {
            if (!secret.trim() && !w.app_secret.set) return setError(t('channels.guide.required'));
            return secret.trim() ? put({ whatsapp_app_secret: secret.trim() }, () => {
                      setSecret('');
                      setStep(2);
                  }) : setStep(2);
        }
        if (step === 2) {
            if (!DIGITS.test(phoneId.trim()) || (wabaId.trim() !== '' && !DIGITS.test(wabaId.trim()))) return setError(t('channels.guide.ids.invalid'));
            return put({ whatsapp_phone_number_id: phoneId.trim(), whatsapp_business_account_id: wabaId.trim() || null }, () => setStep(3));
        }
        if (step === 3) {
            if (!token.trim() && !w.send_token.set) return setError(t('channels.guide.required'));
            return token.trim() ? put({ whatsapp_access_token: token.trim() }, () => {
                      setToken('');
                      setStep(4);
                  }) : setStep(4);
        }
        finish();
    };

    const finish = () => {
        if (!verify && !w.verify_token.set) return setError(t('channels.guide.required'));
        setFailure(null);
        const check = () =>
            test.mutate('whatsapp', {
                onSuccess: (result) => {
                    if (!result.ok) {
                        setFailure(t('channels.guide.failed', { reason: reasonText(t, result.error_code, result.error_message) }));
                        return;
                    }
                    put({ whatsapp_driver: 'cloud' }, () => {
                        notify(t('channels.guide.done'));
                        setVerify(null);
                        onClose();
                    });
                },
                onError: (e) => setFailure(e instanceof ApiError && e.status === 429 ? t('channels.check.tooMany') : e instanceof ApiError ? e.problem.title : t('error.title')),
            });
        if (verify) put({ whatsapp_verify_token: verify }, check);
        else check();
    };

    const busy = save.isPending || test.isPending;
    const pills: { n: Step; label: string }[] = [
        { n: 1, label: t('channels.guide.pill.app') },
        { n: 2, label: t('channels.guide.pill.ids') },
        { n: 3, label: t('channels.guide.pill.token') },
        { n: 4, label: t('channels.guide.pill.webhook') },
    ];
    const nextLabel = step === 1 ? t('channels.guide.next.ids') : step === 2 ? t('channels.guide.next.token') : step === 3 ? t('channels.guide.next.webhook') : t('channels.guide.finish');

    const openLink = (href: string, place: string) => (
        <a href={href} target="_blank" rel="noreferrer noopener" className={`${buttonClass('outline', 'sm')} gap-2 self-start`}>
            <ArrowSquareOutIcon size={16} aria-hidden />
            {t('channels.guide.open', { place })}
        </a>
    );

    return (
        <dialog
            ref={ref}
            aria-labelledby={titleId}
            onClose={onClose}
            onCancel={onClose}
            className="m-0 ms-auto h-dvh max-h-dvh w-full max-w-140 overflow-hidden rounded-s-lg bg-surface p-0 text-fg-primary shadow-e4 backdrop:bg-overlay max-md:max-w-none max-md:rounded-none"
        >
            {open && (
                <form
                    noValidate
                    className="flex h-full flex-col"
                    onSubmit={(event) => {
                        event.preventDefault();
                        next();
                    }}
                >
                    <div className="flex shrink-0 items-center gap-2 border-b border-line-default py-4 ps-5 pe-4">
                        <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                            <h2 id={titleId} className="t-admin-section text-fg-primary">
                                {t('channels.guide.title')}
                            </h2>
                            <p className="t-admin-caption text-fg-tertiary">{t('channels.guide.progress', { step })}</p>
                        </div>
                        <IconButton aria-label={t('channels.guide.close')} onClick={onClose}>
                            <XIcon size={18} aria-hidden />
                        </IconButton>
                    </div>

                    <ol className="grid shrink-0 grid-cols-2 gap-2 px-5 pt-5 sm:grid-cols-4">
                        {pills.map((pill) => {
                            const done = pill.n < step;
                            const now = pill.n === step;
                            return (
                                <li
                                    key={pill.n}
                                    aria-current={now ? 'step' : undefined}
                                    className={`flex items-center gap-1 rounded-sm px-2 py-1.5 t-admin-caption ${now ? 'bg-brand-subtle t-admin-caption-strong text-fg-brand' : 'bg-subtle text-fg-secondary'}`}
                                >
                                    {done ? <CheckCircleIcon size={16} aria-hidden className="shrink-0 text-feedback-success" /> : <span aria-hidden>{pill.n}</span>}
                                    <span className="truncate">{pill.label}</span>
                                </li>
                            );
                        })}
                    </ol>

                    <div className="flex min-h-0 flex-1 flex-col gap-5 overflow-y-auto p-5">
                        {step === 1 && (
                            <>
                                <Intro title={t('channels.guide.app.title')} body={t('channels.guide.app.body')} />
                                {openLink(LINKS.developers, t('channels.guide.place.developers'))}
                                <SecretInput
                                    label={t('channels.guide.app.secret')}
                                    hint={w.app_secret.set ? t('channels.guide.keepSaved', { hint: w.app_secret.hint ?? '••••' }) : t('channels.guide.app.secretHint')}
                                    value={secret}
                                    onChange={setSecret}
                                    error={error}
                                />
                            </>
                        )}
                        {step === 2 && (
                            <>
                                <Intro title={t('channels.guide.ids.title')} body={t('channels.guide.ids.body')} />
                                {openLink(LINKS.apiSetup, t('channels.guide.place.apiSetup'))}
                                <Field label={t('channels.guide.ids.phone')} hint={t('channels.guide.ids.phoneHint')} error={error ?? undefined}>
                                    {({ id, describedBy, invalid }) => (
                                        <input id={id} inputMode="numeric" autoComplete="off" spellCheck={false} aria-describedby={describedBy} aria-invalid={invalid || undefined} className={`h-10 ${monoInputClass}`} value={phoneId} onChange={(e) => setPhoneId(e.target.value)} />
                                    )}
                                </Field>
                                <Field label={t('channels.guide.ids.waba')} hint={t('channels.guide.ids.wabaHint')}>
                                    {({ id, describedBy }) => (
                                        <input id={id} inputMode="numeric" autoComplete="off" spellCheck={false} aria-describedby={describedBy} className={`h-10 ${monoInputClass}`} value={wabaId} onChange={(e) => setWabaId(e.target.value)} />
                                    )}
                                </Field>
                            </>
                        )}
                        {step === 3 && (
                            <>
                                <Intro title={t('channels.guide.token.title')} body={t('channels.guide.token.body')} />
                                {openLink(LINKS.systemUsers, t('channels.guide.place.systemUsers'))}
                                <SecretInput
                                    label={t('channels.guide.token.label')}
                                    hint={w.send_token.set ? t('channels.guide.keepSaved', { hint: w.send_token.hint ?? '••••' }) : t('channels.guide.token.hint')}
                                    value={token}
                                    onChange={setToken}
                                    error={error}
                                />
                            </>
                        )}
                        {step === 4 && (
                            <>
                                <Intro title={t('channels.guide.webhook.title')} body={t('channels.guide.webhook.body')} />
                                <CopyBox label={t('channels.webhook.callback')} value={w.webhook_url} copy />
                                {verify ? (
                                    <div className="flex flex-col gap-1">
                                        <CopyBox label={t('channels.webhook.verifyToken')} value={verify} copy />
                                        <p className="t-admin-caption text-fg-secondary">{t('channels.guide.webhook.generated')}</p>
                                    </div>
                                ) : (
                                    <div className="flex flex-col gap-2">
                                        {w.verify_token.set && <p className="t-admin-caption text-fg-secondary">{t('channels.guide.webhook.keep', { hint: w.verify_token.hint ?? '••••' })}</p>}
                                        <Button variant="outline" size="sm" className="self-start" onClick={() => setVerify(makeVerifyToken())}>
                                            {t('channels.guide.webhook.generate')}
                                        </Button>
                                        {error && (
                                            <p role="alert" className="t-admin-caption text-feedback-danger">
                                                {error}
                                            </p>
                                        )}
                                    </div>
                                )}
                                {failure && (
                                    <p role="alert" className="rounded-md bg-danger-subtle p-3 t-admin-body text-fg-primary">
                                        {failure}
                                    </p>
                                )}
                            </>
                        )}
                        <p className="flex items-start gap-2 rounded-md bg-info-subtle p-3 t-admin-caption text-fg-secondary">
                            <InfoIcon size={16} aria-hidden className="shrink-0 text-feedback-info" />
                            <span>{t('channels.guide.note')}</span>
                        </p>
                    </div>

                    <div className="flex shrink-0 items-center gap-3 border-t border-line-default p-5">
                        {step > 1 && (
                            <Button variant="outline" className="gap-2" disabled={busy} onClick={() => {
                                    setError(null);
                                    setStep((step - 1) as Step);
                                }}>
                                <CaretLeftIcon size={16} aria-hidden />
                                {t('channels.guide.back')}
                            </Button>
                        )}
                        <Button type="submit" className="ms-auto gap-2" disabled={busy} aria-busy={busy || undefined}>
                            {test.isPending ? t('channels.guide.checking') : save.isPending ? t('channels.guide.saving') : nextLabel}
                            {step < 4 && !busy && <CaretRightIcon size={16} aria-hidden />}
                        </Button>
                    </div>
                </form>
            )}
        </dialog>
    );
}

function Intro({ title, body }: { title: string; body: string }) {
    return (
        <div className="flex flex-col gap-2">
            <h3 className="t-admin-body-strong text-fg-primary">{title}</h3>
            <p className="t-admin-body text-fg-secondary">{body}</p>
        </div>
    );
}

function SecretInput({ label, hint, value, onChange, error }: { label: string; hint: string; value: string; onChange: (value: string) => void; error: string | null }) {
    return (
        <Field label={label} hint={hint} error={error ?? undefined}>
            {({ id, describedBy, invalid }) => (
                <input
                    id={id}
                    type="password"
                    autoComplete="off"
                    spellCheck={false}
                    aria-describedby={describedBy}
                    aria-invalid={invalid || undefined}
                    className={`h-10 ${monoInputClass}`}
                    value={value}
                    onChange={(e) => onChange(e.target.value)}
                />
            )}
        </Field>
    );
}
