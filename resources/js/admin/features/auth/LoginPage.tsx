import { useId, useState, type FormEvent } from 'react';
import { Navigate, useLocation, useNavigate } from 'react-router';
import { useLogin, useMe, useTwoFactorChallenge } from './api';
import { ApiError } from '@/lib/api';
import { useI18n } from '@/i18n/I18nProvider';
import { Button } from '@/components/ui/Button';
import { Field } from '@/components/ui/Field';
import { Input } from '@/components/ui/Input';
import { EnvelopeSimpleIcon, LockSimpleIcon, ShieldCheckIcon, WarningCircleIcon } from '@phosphor-icons/react';
import { SignInFrame } from './SignInFrame';

/**
 * Figma page 07 §14 Theme B "B · 00 Sign in" 241:29526. The two-factor step (owner decision
 * 2026-11-14) has no frame: it reuses the same card — title, body, one field, primary button.
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
    const [step, setStep] = useState<'password' | 'two-factor'>('password');
    const ids = { error: useId() };

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
                        setStep('password');
                    }}
                />
            </SignInFrame>
        );
    }

    return (
        <SignInFrame>
                <form
                    onSubmit={submit}
                    noValidate
                    aria-describedby={message ? ids.error : undefined}
                    className="flex w-full max-w-110 flex-col gap-4.5 rounded-lg border border-line-default bg-surface p-6 shadow-e2 sm:p-10"
                >
                    <div className="flex flex-col gap-4.5">
                        <h1 className="t-admin-page-title text-fg-primary">{t('auth.title')}</h1>
                        <p className="t-admin-body text-fg-secondary">{t('auth.subtitle')}</p>
                    </div>

                    {message && (
                        <p id={ids.error} role="alert" className="flex items-start gap-2 rounded-sm bg-danger-subtle px-3 py-2 t-admin-body text-fg-primary">
                            <WarningCircleIcon size={18} aria-hidden className="mt-0.5 shrink-0 text-feedback-danger" />
                            {message}
                        </p>
                    )}

                    <Field label={t('auth.email')}>
                        {({ id }) => (
                            <Input
                                id={id}
                                type="email"
                                autoComplete="username"
                                required
                                value={email}
                                onChange={(e) => setEmail(e.target.value)}
                                aria-invalid={error ? true : undefined}
                                leading={<EnvelopeSimpleIcon size={16} aria-hidden />}
                            />
                        )}
                    </Field>

                    <Field label={t('auth.password')}>
                        {({ id }) => (
                            <Input
                                id={id}
                                type="password"
                                autoComplete="current-password"
                                required
                                value={password}
                                onChange={(e) => setPassword(e.target.value)}
                                aria-invalid={error ? true : undefined}
                                leading={<LockSimpleIcon size={16} aria-hidden />}
                            />
                        )}
                    </Field>

                    <label className="flex min-h-touch-min items-center gap-2 t-admin-body text-fg-secondary">
                        <input type="checkbox" checked={remember} onChange={(e) => setRemember(e.target.checked)} className="size-4 accent-brand" />
                        {t('auth.remember')}
                    </label>

                    <Button type="submit" size="lg" disabled={login.isPending} className="w-full">
                        {login.isPending ? t('auth.submitting') : t('auth.submit')}
                    </Button>
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
            className="flex w-full max-w-110 flex-col gap-4.5 rounded-lg border border-line-default bg-surface p-6 shadow-e2 sm:p-10"
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
                        id={id}
                        autoFocus
                        inputMode={recovery ? 'text' : 'numeric'}
                        autoComplete={recovery ? 'off' : 'one-time-code'}
                        maxLength={recovery ? 32 : 7}
                        className="tabular tracking-widest"
                        value={value}
                        onChange={(e) => setValue(e.target.value)}
                        aria-invalid={error ? true : undefined}
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
