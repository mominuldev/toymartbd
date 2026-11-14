import type { ReactNode } from 'react';
import { ShoppingBagOpenIcon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import { LanguageSwitch } from '@/components/shell/LanguageSwitch';

/** The sign-in screen around a card: brand panel (desktop) and the language switch. */
export function SignInFrame({ children }: { children: ReactNode }) {
    const { t } = useI18n();
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
                {children}
            </div>
        </main>
    );
}
