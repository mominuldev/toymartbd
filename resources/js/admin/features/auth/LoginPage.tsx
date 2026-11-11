import { useId, useState, type FormEvent } from 'react';
import { Navigate, useLocation, useNavigate } from 'react-router';
import { useLogin, useMe } from './api';
import { ApiError } from '@/lib/api';
import { useI18n } from '@/i18n/I18nProvider';
import { LanguageSwitch } from '@/components/shell/LanguageSwitch';
import { Button } from '@/components/ui/Button';
import { Field } from '@/components/ui/Field';
import { Input } from '@/components/ui/Input';
import { EnvelopeSimpleIcon, LockSimpleIcon, ShoppingBagOpenIcon, WarningCircleIcon } from '@phosphor-icons/react';

/** Figma page 07 §14 Theme B "B · 00 Sign in" 241:29526 (2FA not built yet). */
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
        login.mutate({ email, password, remember }, { onSuccess: () => navigate(from, { replace: true }) });
    }

    const facts = ['auth.fact.couriers', 'auth.fact.payments', 'auth.fact.languages', 'auth.fact.inbox'] as const;

    return (
        <main className="flex min-h-dvh bg-canvas">
            {/* Brand panel — Figma "B · 00 Sign in" 241:29526; desktop only. */}
            <section aria-hidden className="hidden w-155 shrink-0 flex-col justify-between bg-brand-subtle px-14 py-16 lg:flex">
                <div className="flex items-center gap-2.5">
                    <span className="grid size-10 place-items-center rounded-sm bg-[image:var(--tm-gradient-button-primary)] text-fg-on-brand">
                        <ShoppingBagOpenIcon size={22} weight="fill" />
                    </span>
                    <span lang="en" className="t-h3 text-fg-primary">
                        {t('app.wordmark')}
                    </span>
                </div>
                <div className="flex flex-col gap-3">
                    <p className="t-admin-display text-fg-primary">{t('auth.heroTitle')}</p>
                    <p className="t-admin-body-lg text-fg-secondary">{t('auth.heroBody')}</p>
                </div>
                <ul className="flex flex-wrap gap-2.5">
                    {facts.map((fact) => (
                        <li key={fact} className="rounded-sm bg-surface px-3 py-1.5 t-admin-button-sm text-fg-primary">
                            {t(fact)}
                        </li>
                    ))}
                </ul>
            </section>

            <div className="relative flex flex-1 flex-col items-center justify-center p-gutter-mobile">
                <div className="absolute end-4 top-4 lg:end-6 lg:top-6">
                    <LanguageSwitch />
                </div>

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
            </div>
        </main>
    );
}
