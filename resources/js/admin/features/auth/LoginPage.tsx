import { useEffect, useId, useRef, useState, type FormEvent } from 'react';
import { Navigate, useLocation, useNavigate } from 'react-router';
import { useLogin, useMe, useTwoFactorChallenge } from './api';
import { ApiError } from '@/lib/api';
import { useI18n } from '@/i18n/I18nProvider';
import { Button } from '@/components/ui/Button';
import { Field } from '@/components/ui/Field';
import { Input } from '@/components/ui/Input';
import { ArrowRightIcon, EnvelopeSimpleIcon, EyeIcon, EyeSlashIcon, LockSimpleIcon, ShieldCheckIcon, WarningCircleIcon, WarningIcon } from '@phosphor-icons/react';
import { SignInFrame } from './SignInFrame';

/** The sign-in card and the two-factor card share one shape (Figma "Sign-in card", 586:50586). */
export const signInCard = 'flex w-full max-w-114 flex-col gap-6 rounded-lg border border-line-default bg-surface p-6 shadow-e3 sm:p-10';

/**
 * Figma "B · 00 Sign in · Premium · C Toy orbit" 595:50745 (states 588:50626): Welcome back,
 * email, password with a show/hide button and a Caps Lock warning, keep-me-signed-in, Sign in.
 * The two-factor step (owner decision 2026-11-14) reuses the same card — title, body, one field.
 */
export function LoginPage() {
    const { t } = useI18n();
    const me = useMe();
    const login = useLogin();
    const navigate = useNavigate();
    const location = useLocation();
    const from = (location.state as { from?: string } | null)?.from ?? '/';

    const [email, setEmail] = useState('');
    const [password, setPassword] = useState('');
    const [remember, setRemember] = useState(false);
    const [shown, setShown] = useState(false);
    const [capsLock, setCapsLock] = useState(false);
    const [step, setStep] = useState<'password' | 'two-factor'>('password');
    // "Start again" from the code step unmounts the button that was pressed: focus goes to Email.
    const [restarted, setRestarted] = useState(false);
    const ids = { error: useId(), password: useId(), caps: useId() };

    if (me.data) return <Navigate to={from} replace />;

    const error = login.error;
    const message =
        error instanceof ApiError && error.status === 429
            ? t('auth.throttled')
            : error
              ? t('auth.failed')
              : null;

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        login.mutate(
            { email, password, remember },
            {
                onSuccess: (result) => {
                    if (result.kind === 'two-factor') setStep('two-factor');
                    else navigate(from, { replace: true });
                },
            },
        );
    }

    if (step === 'two-factor') {
        return (
            <SignInFrame>
                <TwoFactorStep
                    onDone={() => navigate(from, { replace: true })}
                    onRestart={() => {
                        setPassword('');
                        login.reset();
                        setRestarted(true);
                        setStep('password');
                    }}
                />
            </SignInFrame>
        );
    }

    return (
        <SignInFrame>
            <form onSubmit={submit} noValidate aria-describedby={message ? ids.error : undefined} className={signInCard}>
                <div className="flex flex-col gap-4">
                    <span aria-hidden className="grid size-12 place-items-center rounded-md bg-brand-subtle text-fg-brand">
                        <ShieldCheckIcon size={24} />
                    </span>
                    <div className="flex flex-col gap-2">
                        <h1 className="t-admin-page-title text-fg-primary">{t('auth.title')}</h1>
                        <p className="t-admin-body text-fg-secondary">{t('auth.subtitle')}</p>
                    </div>
                </div>

                {message && (
                    <div className="flex items-start gap-2 rounded-sm border border-line-danger bg-danger-subtle px-3 py-3">
                        <WarningCircleIcon size={18} aria-hidden className="mt-0.5 shrink-0 text-feedback-danger" />
                        <div className="flex flex-col gap-0.5">
                            {/* The alert and both fields' description are the message alone; the hint is extra help. */}
                            <p id={ids.error} role="alert" className="t-admin-body text-fg-primary">
                                {message}
                            </p>
                            {!(error instanceof ApiError && error.status === 429) && <p className="t-admin-caption text-fg-secondary">{t('auth.failedHint')}</p>}
                        </div>
                    </div>
                )}

                <div className="flex flex-col gap-4">
                    <Field label={t('auth.email')}>
                        {({ id }) => (
                            <Input
                                id={id}
                                size="lg"
                                type="email"
                                autoComplete="username"
                                autoFocus={restarted}
                                required
                                value={email}
                                onChange={(e) => setEmail(e.target.value)}
                                aria-invalid={error ? true : undefined}
                                aria-describedby={message ? ids.error : undefined}
                                leading={<EnvelopeSimpleIcon size={18} aria-hidden />}
                            />
                        )}
                    </Field>

                    <div className="flex flex-col gap-2">
                        <Field label={t('auth.password')}>
                            {({ id }) => (
                                <Input
                                    id={id}
                                    size="lg"
                                    type={shown ? 'text' : 'password'}
                                    autoComplete="current-password"
                                    required
                                    value={password}
                                    onChange={(e) => setPassword(e.target.value)}
                                    onKeyDown={(e) => setCapsLock(e.getModifierState('CapsLock'))}
                                    onKeyUp={(e) => setCapsLock(e.getModifierState('CapsLock'))}
                                    onBlur={() => setCapsLock(false)}
                                    aria-invalid={error ? true : undefined}
                                    aria-describedby={[message ? ids.error : null, capsLock ? ids.caps : null].filter(Boolean).join(' ') || undefined}
                                    leading={<LockSimpleIcon size={18} aria-hidden />}
                                    trailing={
                                        <button
                                            type="button"
                                            onClick={() => setShown(!shown)}
                                            aria-pressed={shown}
                                            aria-label={t(shown ? 'auth.hidePassword' : 'auth.showPassword')}
                                            title={t(shown ? 'auth.hidePassword' : 'auth.showPassword')}
                                            className="grid size-8 place-items-center rounded-xs text-fg-secondary hover:bg-subtle hover:text-fg-primary aria-pressed:bg-subtle"
                                        >
                                            {shown ? <EyeSlashIcon size={18} aria-hidden /> : <EyeIcon size={18} aria-hidden />}
                                        </button>
                                    }
                                />
                            )}
                        </Field>
                        {capsLock && (
                            <p id={ids.caps} className="flex items-center gap-1 t-admin-caption-strong text-fg-primary">
                                <WarningIcon size={14} aria-hidden className="shrink-0 text-feedback-warning" />
                                {t('auth.capsLock')}
                            </p>
                        )}
                        <p className="t-admin-caption text-fg-tertiary">{t('auth.forgot')}</p>
                    </div>
                </div>

                <label className="flex min-h-touch-min items-center gap-3 t-admin-body text-fg-secondary">
                    <input type="checkbox" checked={remember} onChange={(e) => setRemember(e.target.checked)} className="size-4 accent-brand" />
                    {t('auth.remember')}
                </label>

                <Button type="submit" size="lg" disabled={login.isPending} className="w-full gap-2">
                    {login.isPending ? t('auth.submitting') : t('auth.submit')}
                    {!login.isPending && <ArrowRightIcon size={20} aria-hidden />}
                </Button>

                <p className="flex items-start gap-2 border-t border-line-default pt-6 t-admin-caption text-fg-secondary">
                    <ShieldCheckIcon size={16} weight="fill" aria-hidden className="mt-px shrink-0 text-feedback-success" />
                    {t('auth.secure')}
                </p>
            </form>
        </SignInFrame>
    );
}

/**
 * Second step: the 6-digit code from the authenticator app, or a recovery code. A wrong or
 * expired code says so in place; "Start again" returns to email and password.
 */
function TwoFactorStep({ onDone, onRestart }: { onDone: () => void; onRestart: () => void }) {
    const { t } = useI18n();
    const challenge = useTwoFactorChallenge();
    const [recovery, setRecovery] = useState(false);
    const [value, setValue] = useState('');
    const errorId = useId();
    const input = useRef<HTMLInputElement>(null);
    const toggled = useRef(false);

    // Switching between the app code and a recovery code relabels the one field: put focus in it.
    useEffect(() => {
        if (toggled.current) input.current?.focus();
    }, [recovery]);

    const error = challenge.error;
    const message =
        error instanceof ApiError && error.status === 429
            ? t('auth.throttled')
            : error instanceof ApiError
              ? (error.problem.errors?.[0]?.message ?? t('twoFactor.invalid'))
              : error
                ? t('error.title')
                : null;

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        const trimmed = value.trim();
        if (trimmed === '') return;
        challenge.mutate(recovery ? { recovery_code: trimmed } : { code: trimmed.replace(/\s+/g, '') }, { onSuccess: onDone });
    }

    return (
        <form
            onSubmit={submit}
            noValidate
            aria-describedby={message ? errorId : undefined}
            className={signInCard}
        >
            <div className="flex flex-col gap-4.5">
                <span aria-hidden className="grid size-11 place-items-center rounded-sm bg-brand-subtle text-fg-brand">
                    <ShieldCheckIcon size={22} />
                </span>
                <h1 className="t-admin-page-title text-fg-primary">{t('twoFactor.challenge.title')}</h1>
                <p className="t-admin-body text-fg-secondary">{t(recovery ? 'twoFactor.challenge.recoveryBody' : 'twoFactor.challenge.body')}</p>
            </div>

            {message && (
                <p id={errorId} role="alert" className="flex items-start gap-2 rounded-sm bg-danger-subtle px-3 py-2 t-admin-body text-fg-primary">
                    <WarningCircleIcon size={18} aria-hidden className="mt-0.5 shrink-0 text-feedback-danger" />
                    {message}
                </p>
            )}

            <Field label={t(recovery ? 'twoFactor.recoveryCode' : 'twoFactor.code')}>
                {({ id }) => (
                    <Input
                        ref={input}
                        id={id}
                        autoFocus
                        inputMode={recovery ? 'text' : 'numeric'}
                        autoComplete={recovery ? 'off' : 'one-time-code'}
                        maxLength={recovery ? 32 : 7}
                        className="tabular tracking-widest"
                        value={value}
                        onChange={(e) => setValue(e.target.value)}
                        aria-invalid={error ? true : undefined}
                        aria-describedby={message ? errorId : undefined}
                    />
                )}
            </Field>

            <Button type="submit" size="lg" disabled={challenge.isPending} className="w-full">
                {challenge.isPending ? t('auth.submitting') : t('twoFactor.challenge.submit')}
            </Button>

            <div className="flex flex-wrap items-center justify-between gap-2">
                <Button
                    variant="ghost"
                    size="sm"
                    onClick={() => {
                        toggled.current = true;
                        setRecovery(!recovery);
                        setValue('');
                        challenge.reset();
                    }}
                >
                    {t(recovery ? 'twoFactor.challenge.useApp' : 'twoFactor.challenge.useRecovery')}
                </Button>
                <Button variant="ghost" size="sm" onClick={onRestart}>
                    {t('twoFactor.challenge.restart')}
                </Button>
            </div>
        </form>
    );
}
