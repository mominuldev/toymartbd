import { useId, useState, type FormEvent } from 'react';
import { Navigate, useLocation, useNavigate } from 'react-router';
import { useLogin, useMe } from './api';
import { ApiError } from '@/lib/api';
import { useI18n } from '@/i18n/I18nProvider';
import { LanguageSwitch } from '@/components/shell/LanguageSwitch';

/** Figma page 07 §00 "Access & states" — sign in. */
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
    const ids = { email: useId(), password: useId(), error: useId() };

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

    const field =
        'h-control-md w-full rounded-sm border border-line-strong bg-surface px-3 t-admin-body text-fg-primary placeholder:text-fg-tertiary focus-visible:border-line-focus';

    return (
        <main className="grid min-h-dvh place-items-center bg-canvas p-gutter-mobile">
            <div className="w-full max-w-sm">
                <div className="mb-6 flex items-center justify-between">
                    <p className="t-admin-body-strong text-fg-primary">
                        {t('app.name')} · {t('app.admin')}
                    </p>
                    <LanguageSwitch />
                </div>

                <form
                    onSubmit={submit}
                    noValidate
                    aria-describedby={message ? ids.error : undefined}
                    className="space-y-5 rounded-md border border-line-default bg-surface p-6 shadow-e1"
                >
                    <div className="space-y-1">
                        <h1 className="t-admin-page-title text-fg-primary">{t('auth.title')}</h1>
                        <p className="t-admin-body text-fg-secondary">{t('auth.subtitle')}</p>
                    </div>

                    {message && (
                        <p id={ids.error} role="alert" className="rounded-sm bg-danger-subtle px-3 py-2 t-admin-body text-feedback-danger">
                            {message}
                        </p>
                    )}

                    <div className="space-y-1.5">
                        <label htmlFor={ids.email} className="t-admin-body-strong text-fg-primary">
                            {t('auth.email')}
                        </label>
                        <input
                            id={ids.email}
                            type="email"
                            autoComplete="username"
                            required
                            value={email}
                            onChange={(e) => setEmail(e.target.value)}
                            aria-invalid={error ? true : undefined}
                            className={field}
                        />
                    </div>

                    <div className="space-y-1.5">
                        <label htmlFor={ids.password} className="t-admin-body-strong text-fg-primary">
                            {t('auth.password')}
                        </label>
                        <input
                            id={ids.password}
                            type="password"
                            autoComplete="current-password"
                            required
                            value={password}
                            onChange={(e) => setPassword(e.target.value)}
                            aria-invalid={error ? true : undefined}
                            className={field}
                        />
                    </div>

                    <label className="flex min-h-touch-min items-center gap-2 t-admin-body text-fg-secondary">
                        <input
                            type="checkbox"
                            checked={remember}
                            onChange={(e) => setRemember(e.target.checked)}
                            className="size-4 accent-brand"
                        />
                        {t('auth.remember')}
                    </label>

                    <button
                        type="submit"
                        disabled={login.isPending}
                        className="h-control-md w-full rounded-sm bg-brand t-admin-button text-fg-on-brand transition-opacity duration-150 ease-standard disabled:opacity-60"
                    >
                        {login.isPending ? t('auth.submitting') : t('auth.submit')}
                    </button>
                </form>
            </div>
        </main>
    );
}
