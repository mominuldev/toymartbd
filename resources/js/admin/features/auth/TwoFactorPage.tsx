import { useId, useState, type FormEvent } from 'react';
import { Link, useNavigate } from 'react-router';
import { CheckCircleIcon, CopyIcon, DownloadSimpleIcon, ShieldCheckIcon, ShieldWarningIcon, WarningCircleIcon } from '@phosphor-icons/react';
import { ApiError } from '@/lib/api';
import { formatDhakaDate } from '@/lib/format';
import { useI18n } from '@/i18n/I18nProvider';
import { Button, buttonClass } from '@/components/ui/Button';
import { Field } from '@/components/ui/Field';
import { Input } from '@/components/ui/Input';
import { QueryError } from '@/components/ui/QueryError';
import { StatusChip } from '@/components/ui/StatusChip';
import { useToast } from '@/components/ui/Toast';
import { useLogout } from './api';
import { SignInFrame } from './SignInFrame';
import { useConfirmTwoFactor, useDisableTwoFactor, useNewRecoveryCodes, useStartTwoFactor, useTwoFactorStatus, type TwoFactorSetup } from './twoFactorApi';

const card = 'flex w-full max-w-120 flex-col gap-4.5 rounded-lg border border-line-default bg-surface p-6 shadow-e2 sm:p-10';

/** The 422 message for `code` (or a generic one) from a failed code check. */
function codeError(error: unknown, fallback: string): string | undefined {
    if (!error) return undefined;
    if (error instanceof ApiError && error.status === 429) return undefined;
    return error instanceof ApiError ? (error.problem.errors?.[0]?.message ?? error.problem.detail ?? fallback) : fallback;
}

/**
 * Account → Two-factor sign-in (owner decision 2026-11-14: required for anyone who can see
 * money). No Figma frame: it sits in the sign-in frame (B · 00 241:29526) because someone who
 * must enrol is held here before they can use the admin. Setup is QR + setup key → one code →
 * the recovery codes, shown once with copy and download. Once on: recovery codes left, new
 * codes, and — only when the role does not require it — turning it off.
 */
export function TwoFactorPage() {
    const { t } = useI18n();
    const status = useTwoFactorStatus();
    const [codes, setCodes] = useState<string[] | null>(null);

    let body;
    if (status.isError) body = <QueryError error={status.error} onRetry={() => void status.refetch()} />;
    else if (!status.data) body = <div className={card} aria-busy="true" />;
    else if (codes) body = <RecoveryCodesCard codes={codes} onDone={() => setCodes(null)} />;
    else if (!status.data.enabled) body = <SetupCard required={status.data.required} onEnabled={setCodes} />;
    else body = <ManageCard confirmedAt={status.data.confirmed_at} left={status.data.recovery_codes_left} required={status.data.required} onCodes={setCodes} />;

    return (
        <SignInFrame>
            <div className="flex w-full max-w-120 flex-col gap-3">
                <span className="sr-only" role="status">
                    {t('twoFactor.title')}
                </span>
                {body}
            </div>
        </SignInFrame>
    );
}

function Header({ icon: Icon = ShieldCheckIcon, title, body }: { icon?: typeof ShieldCheckIcon; title: string; body: string }) {
    return (
        <div className="flex flex-col gap-3">
            <span aria-hidden className="grid size-11 place-items-center rounded-sm bg-brand-subtle text-fg-brand">
                <Icon size={22} />
            </span>
            <h1 className="t-admin-page-title text-fg-primary">{title}</h1>
            <p className="t-admin-body text-fg-secondary">{body}</p>
        </div>
    );
}

function SetupCard({ required, onEnabled }: { required: boolean; onEnabled: (codes: string[]) => void }) {
    const { t } = useI18n();
    const start = useStartTwoFactor();
    const confirm = useConfirmTwoFactor();
    const logout = useLogout();
    const navigate = useNavigate();
    const [setup, setSetup] = useState<TwoFactorSetup | null>(null);
    const [code, setCode] = useState('');

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        if (code.trim() === '') return;
        confirm.mutate({ code: code.replace(/\s+/g, '') }, { onSuccess: (r) => onEnabled(r.recovery_codes) });
    }

    if (!setup) {
        return (
            <div className={card}>
                <Header icon={required ? ShieldWarningIcon : ShieldCheckIcon} title={t('twoFactor.title')} body={t(required ? 'twoFactor.requiredBody' : 'twoFactor.optionalBody')} />
                {start.isError && <QueryError error={start.error} onRetry={() => start.mutate(undefined, { onSuccess: setSetup })} />}
                <Button size="lg" className="w-full" disabled={start.isPending} onClick={() => start.mutate(undefined, { onSuccess: setSetup })}>
                    {t('twoFactor.start')}
                </Button>
                {required ? (
                    <Button variant="ghost" onClick={() => logout.mutate(undefined, { onSettled: () => navigate('/login', { replace: true }) })}>
                        {t('shell.signOut')}
                    </Button>
                ) : (
                    <Link to="/" className={buttonClass('ghost')}>
                        {t('twoFactor.back')}
                    </Link>
                )}
            </div>
        );
    }

    const error = codeError(confirm.error, t('twoFactor.invalid'));

    return (
        <form noValidate onSubmit={submit} className={card}>
            <Header title={t('twoFactor.scanTitle')} body={t('twoFactor.scanBody')} />
            <img
                src={`data:image/svg+xml;utf8,${encodeURIComponent(setup.qr_svg)}`}
                alt={t('twoFactor.qrAlt')}
                width={192}
                height={192}
                className="size-48 self-center rounded-sm border border-line-default"
            />
            <div className="flex flex-col gap-1">
                <p className="t-admin-caption text-fg-secondary">{t('twoFactor.keyLabel')}</p>
                <code className="t-admin-mono-sm rounded-sm bg-subtle px-3 py-2 break-all text-fg-primary select-all">{setup.secret.replace(/(.{4})/g, '$1 ').trim()}</code>
            </div>
            <Field label={t('twoFactor.code')} hint={t('twoFactor.codeHint')} error={error}>
                {({ id, describedBy, invalid }) => (
                    <Input
                        id={id}
                        inputMode="numeric"
                        autoComplete="one-time-code"
                        maxLength={7}
                        className="tabular tracking-widest"
                        aria-describedby={describedBy}
                        aria-invalid={invalid || undefined}
                        value={code}
                        onChange={(e) => setCode(e.target.value)}
                    />
                )}
            </Field>
            <Button type="submit" size="lg" className="w-full" disabled={confirm.isPending}>
                {t('twoFactor.confirm')}
            </Button>
        </form>
    );
}

function RecoveryCodesCard({ codes, onDone }: { codes: string[]; onDone: () => void }) {
    const { t } = useI18n();
    const { notify } = useToast();
    const navigate = useNavigate();
    const text = codes.join('\n');

    return (
        <div className={card}>
            <Header icon={CheckCircleIcon} title={t('twoFactor.codesTitle')} body={t('twoFactor.codesBody')} />
            <ul aria-label={t('twoFactor.codesTitle')} className="grid grid-cols-2 gap-2 rounded-sm bg-subtle p-4">
                {codes.map((code) => (
                    <li key={code} className="t-admin-mono-sm text-fg-primary">
                        {code}
                    </li>
                ))}
            </ul>
            <div className="flex flex-wrap gap-3">
                <Button
                    variant="outline"
                    onClick={() =>
                        void navigator.clipboard?.writeText(text).then(
                            () => notify(t('twoFactor.copied')),
                            () => notify(t('error.title'), 'danger'),
                        )
                    }
                >
                    <CopyIcon size={16} aria-hidden />
                    {t('twoFactor.copy')}
                </Button>
                <a href={`data:text/plain;charset=utf-8,${encodeURIComponent(`${t('twoFactor.fileHeading')}\n\n${text}\n`)}`} download="toymart-recovery-codes.txt" className={buttonClass('outline')}>
                    <DownloadSimpleIcon size={16} aria-hidden />
                    {t('twoFactor.download')}
                </a>
            </div>
            <Button
                size="lg"
                className="w-full"
                onClick={() => {
                    onDone();
                    navigate('/', { replace: true });
                }}
            >
                {t('twoFactor.saved')}
            </Button>
        </div>
    );
}

function ManageCard({ confirmedAt, left, required, onCodes }: { confirmedAt: string | null; left: number; required: boolean; onCodes: (codes: string[]) => void }) {
    const { t, locale } = useI18n();
    const { notify } = useToast();
    const regenerate = useNewRecoveryCodes();
    const disable = useDisableTwoFactor();
    const [action, setAction] = useState<'codes' | 'off' | null>(null);
    const [code, setCode] = useState('');
    const errorId = useId();
    const pending = regenerate.isPending || disable.isPending;
    const error = codeError(action === 'off' ? disable.error : regenerate.error, t('twoFactor.invalid'));

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        const input = { code: code.replace(/\s+/g, '') };
        if (action === 'codes') regenerate.mutate(input, { onSuccess: (r) => onCodes(r.recovery_codes) });
        if (action === 'off')
            disable.mutate(input, {
                onSuccess: () => notify(t('twoFactor.turnedOff')),
                onError: (e) => {
                    if (e instanceof ApiError && e.status === 409) notify(e.problem.detail ?? e.problem.title, 'danger');
                },
            });
    }

    return (
        <div className={card}>
            <Header title={t('twoFactor.title')} body={t(required ? 'twoFactor.onRequired' : 'twoFactor.onOptional')} />
            <dl className="grid grid-cols-[auto_1fr] items-center gap-x-4 gap-y-2 t-admin-body">
                <dt className="text-fg-secondary">{t('twoFactor.state')}</dt>
                <dd>
                    <StatusChip label={t('twoFactor.on')} tone="success" />
                </dd>
                {confirmedAt && (
                    <>
                        <dt className="text-fg-secondary">{t('twoFactor.since')}</dt>
                        <dd className="text-fg-primary">{formatDhakaDate(confirmedAt, locale)}</dd>
                    </>
                )}
                <dt className="text-fg-secondary">{t('twoFactor.codesLeft')}</dt>
                <dd className="flex items-center gap-2 tabular text-fg-primary">
                    {left}
                    {left <= 2 && (
                        <span role="img" aria-label={t('twoFactor.codesLow')} title={t('twoFactor.codesLow')} className="inline-grid text-feedback-warning">
                            <WarningCircleIcon size={16} aria-hidden />
                        </span>
                    )}
                </dd>
            </dl>

            {action === null ? (
                <div className="flex flex-wrap gap-3">
                    <Button variant="outline" onClick={() => setAction('codes')}>
                        {t('twoFactor.newCodes')}
                    </Button>
                    {!required && (
                        <Button variant="danger" onClick={() => setAction('off')}>
                            {t('twoFactor.turnOff')}
                        </Button>
                    )}
                    <Link to="/" className={buttonClass('ghost')}>
                        {t('twoFactor.back')}
                    </Link>
                </div>
            ) : (
                <form noValidate onSubmit={submit} aria-describedby={error ? errorId : undefined} className="flex flex-col gap-3 border-t border-line-default pt-4">
                    <p className="t-admin-body text-fg-secondary">{t(action === 'off' ? 'twoFactor.turnOffBody' : 'twoFactor.newCodesBody')}</p>
                    <Field label={t('twoFactor.code')} error={error}>
                        {({ id, describedBy, invalid }) => (
                            <Input
                                id={id}
                                inputMode="numeric"
                                autoComplete="one-time-code"
                                maxLength={7}
                                className="tabular tracking-widest"
                                aria-describedby={describedBy}
                                aria-invalid={invalid || undefined}
                                value={code}
                                onChange={(e) => setCode(e.target.value)}
                            />
                        )}
                    </Field>
                    <div className="flex justify-end gap-3">
                        <Button
                            variant="ghost"
                            onClick={() => {
                                setAction(null);
                                setCode('');
                                regenerate.reset();
                                disable.reset();
                            }}
                        >
                            {t('common.cancel')}
                        </Button>
                        <Button type="submit" variant={action === 'off' ? 'danger' : 'primary'} disabled={pending}>
                            {t(action === 'off' ? 'twoFactor.turnOff' : 'twoFactor.newCodes')}
                        </Button>
                    </div>
                </form>
            )}
        </div>
    );
}
