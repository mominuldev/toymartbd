import { forwardRef, useEffect, useState } from 'react';
import { BellIcon, ListIcon, MagnifyingGlassIcon, MoonIcon, SignOutIcon, SunIcon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import { useTheme } from '@/lib/theme';
import { LanguageSwitch } from './LanguageSwitch';
import type { Me } from '@/features/auth/api';
import { en, type MessageKey } from '@/i18n/messages';

type TopbarProps = {
    me: Me;
    onOpenMenu: () => void;
    onSignOut: () => void;
    signingOut: boolean;
};

const iconButton = 'grid size-9 shrink-0 place-items-center rounded-sm text-fg-secondary hover:bg-subtle';

/** Dhaka wall-clock time with Western digits in both locales (rule 36). */
function useDhakaTime(locale: string): string {
    const format = () =>
        new Intl.DateTimeFormat(locale === 'bn' ? 'bn-BD' : 'en-GB', {
            timeZone: 'Asia/Dhaka',
            hour: 'numeric',
            minute: '2-digit',
            hour12: true,
            numberingSystem: 'latn',
        }).format(new Date());

    const [time, setTime] = useState(format);

    useEffect(() => {
        setTime(format());
        const id = window.setInterval(() => setTime(format()), 30_000);
        return () => window.clearInterval(id);
        // `format` only depends on `locale`.
    }, [locale]);

    return time;
}

/** Figma Topbar 187:474 (≥1024) and Topbar/Tablet 228:1857. */
export const Topbar = forwardRef<HTMLButtonElement, TopbarProps>(function Topbar(
    { me, onOpenMenu, onSignOut, signingOut },
    menuButtonRef,
) {
    const { t, locale } = useI18n();
    const { theme, toggle } = useTheme();
    const time = useDhakaTime(locale);
    const dark = theme === 'admin-dark';
    const roleLabel = (role: string | undefined) => {
        const key = `role.${role ?? ''}`;
        return key in en ? t(key as MessageKey) : (role ?? '');
    };

    return (
        <header className="sticky top-0 z-20 flex h-14 shrink-0 items-center gap-3 border-b border-line-default bg-surface px-4 shadow-e2 lg:gap-4 lg:px-6">
            <button ref={menuButtonRef} type="button" onClick={onOpenMenu} aria-label={t('shell.openMenu')} className={`${iconButton} md:hidden`}>
                <ListIcon size={22} aria-hidden />
            </button>

            {/* Search is a placeholder until P4 wires the global search endpoint. */}
            <label className="flex h-9 min-w-0 flex-1 items-center gap-2 rounded-md border border-line-default bg-subtle px-3 text-fg-tertiary focus-within:bg-surface focus-within:shadow-focus xl:max-w-105">
                <MagnifyingGlassIcon size={18} aria-hidden className="shrink-0" />
                <span className="sr-only">{t('shell.search')}</span>
                <input
                    type="search"
                    placeholder={t('shell.search')}
                    className="min-w-0 flex-1 bg-transparent t-admin-body text-fg-primary outline-none placeholder:text-fg-tertiary"
                />
            </label>

            <div className="ml-auto flex items-center gap-2">
                <span className="hidden h-7 items-center rounded-sm bg-subtle px-2 t-admin-caption text-fg-secondary tabular lg:inline-flex">
                    {t('shell.timezone', { time })}
                </span>
                <div className="hidden sm:block">
                    <LanguageSwitch />
                </div>
                <button
                    type="button"
                    onClick={toggle}
                    aria-pressed={dark}
                    aria-label={dark ? t('shell.themeLight') : t('shell.themeDark')}
                    className={iconButton}
                >
                    {dark ? <SunIcon size={20} aria-hidden /> : <MoonIcon size={20} aria-hidden />}
                </button>
                <button type="button" aria-label={t('shell.notifications')} className={iconButton}>
                    <BellIcon size={20} aria-hidden />
                </button>
                <div className="hidden min-w-0 flex-col items-end md:flex">
                    <span className="max-w-40 truncate t-admin-body-strong text-fg-primary">{me.name}</span>
                    <span className="t-admin-caption text-fg-tertiary">{roleLabel(me.roles[0])}</span>
                </div>
                <button
                    type="button"
                    onClick={onSignOut}
                    disabled={signingOut}
                    aria-label={t('shell.signOut')}
                    title={t('shell.signOut')}
                    className={`${iconButton} disabled:opacity-60`}
                >
                    <SignOutIcon size={20} aria-hidden />
                </button>
            </div>
        </header>
    );
});
