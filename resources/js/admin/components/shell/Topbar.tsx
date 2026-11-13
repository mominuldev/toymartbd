import { forwardRef, useEffect, useId, useRef, useState } from 'react';
import { BellIcon, CaretDownIcon, ClockIcon, ListIcon, MoonIcon, SignOutIcon, SunIcon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import { useTheme } from '@/lib/theme';
import { IconButton } from '@/components/ui/IconButton';
import { SearchField } from '@/components/ui/SearchField';
import { Avatar } from './Avatar';
import { LanguageSwitch } from './LanguageSwitch';
import type { Me } from '@/features/auth/api';

type TopbarProps = {
    me: Me;
    /** Translated role name for the user block. */
    roleLabel: string;
    onOpenMenu: () => void;
    onSignOut: () => void;
    signingOut: boolean;
};

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

/** User block (avatar · name · role · caret) opening a small menu with Sign out. */
function UserMenu({ me, roleLabel, onSignOut, signingOut }: Pick<TopbarProps, 'me' | 'roleLabel' | 'onSignOut' | 'signingOut'>) {
    const { t } = useI18n();
    const [open, setOpen] = useState(false);
    const menuId = useId();
    const root = useRef<HTMLDivElement>(null);
    const trigger = useRef<HTMLButtonElement>(null);
    const firstName = me.name.trim().split(/\s+/)[0] ?? me.name;

    useEffect(() => {
        if (!open) return;
        const close = (event: MouseEvent | KeyboardEvent) => {
            if (event instanceof KeyboardEvent) {
                if (event.key !== 'Escape') return;
                setOpen(false);
                trigger.current?.focus(); // Escape returns focus to the button (UI-UX spec §7)
            } else if (!root.current?.contains(event.target as Node)) setOpen(false);
        };
        document.addEventListener('mousedown', close);
        document.addEventListener('keydown', close);
        return () => {
            document.removeEventListener('mousedown', close);
            document.removeEventListener('keydown', close);
        };
    }, [open]);

    return (
        <div ref={root} className="relative">
            <button
                ref={trigger}
                type="button"
                aria-haspopup="menu"
                aria-expanded={open}
                aria-controls={open ? menuId : undefined}
                onClick={() => setOpen((value) => !value)}
                className="flex items-center gap-2 rounded-sm p-0.5 text-fg-primary hover:bg-subtle"
            >
                {/* Named by its content, not an aria-label (WCAG 2.5.3 label in name): below lg only the
                    avatar shows, so the full label is visually hidden there; from lg the visible name and role. */}
                <Avatar name={me.name} />
                <span className="sr-only lg:hidden">{t('shell.userMenu', { name: me.name })}</span>
                <span className="hidden min-w-0 flex-col items-start text-start lg:flex">
                    <span className="max-w-32 truncate t-admin-body-strong text-fg-primary">{firstName}</span>
                    <span className="max-w-32 truncate t-admin-caption text-fg-tertiary">{roleLabel}</span>
                </span>
                <CaretDownIcon size={16} aria-hidden className="hidden text-fg-primary lg:block" />
            </button>
            {open && (
                <div id={menuId} role="menu" className="absolute end-0 top-full z-30 mt-1 min-w-44 rounded-sm border border-line-default bg-surface-raised p-1 shadow-e3">
                    <button
                        type="button"
                        role="menuitem"
                        autoFocus
                        disabled={signingOut}
                        onClick={onSignOut}
                        className="flex h-9 w-full items-center gap-2 rounded-xs px-3 t-admin-body text-fg-primary hover:bg-subtle disabled:text-fg-disabled"
                    >
                        <SignOutIcon size={18} aria-hidden />
                        {t('shell.signOut')}
                    </button>
                </div>
            )}
        </div>
    );
}

/**
 * Figma Topbar 187:474 (≥1024) and Topbar / Tablet 228:1857 (<1024): 60 high, bg/surface,
 * border/default bottom edge, no shadow. Search 420 wide with ⌘K, Dhaka clock chip,
 * EN | বাং, theme, notifications, user menu. Below 1024 the menu button opens the drawer.
 */
export const Topbar = forwardRef<HTMLButtonElement, TopbarProps>(function Topbar({ me, roleLabel, onOpenMenu, onSignOut, signingOut }, menuButtonRef) {
    const { t, locale } = useI18n();
    const { theme, toggle } = useTheme();
    const time = useDhakaTime(locale);
    const dark = theme === 'admin-dark';

    return (
        <header className="sticky top-0 z-20 flex h-15 shrink-0 items-center gap-2 border-b border-line-default bg-surface px-4 md:gap-3 lg:gap-4 lg:px-6">
            <IconButton ref={menuButtonRef} onClick={onOpenMenu} aria-label={t('shell.openMenu')} className="lg:hidden">
                <ListIcon size={20} aria-hidden />
            </IconButton>

            {/* Search is a placeholder until the global search endpoint lands. */}
            <SearchField label={t('shell.search')} placeholder={t('shell.search')} shortcut="⌘K" className="hidden w-105 shrink lg:flex" />

            <div className="flex-1" />

            <span className="hidden h-6.5 items-center gap-1.5 rounded-xs bg-subtle px-2.5 t-admin-caption whitespace-nowrap text-fg-secondary tabular md:inline-flex">
                <ClockIcon size={14} aria-hidden />
                {t('shell.timezone', { time })}
            </span>
            <div className="hidden sm:block">
                <LanguageSwitch />
            </div>
            <IconButton onClick={toggle} aria-pressed={dark} aria-label={dark ? t('shell.themeLight') : t('shell.themeDark')}>
                {dark ? <SunIcon size={18} aria-hidden /> : <MoonIcon size={18} aria-hidden />}
            </IconButton>
            <IconButton aria-label={t('shell.notifications')}>
                <BellIcon size={20} aria-hidden />
            </IconButton>
            <UserMenu me={me} roleLabel={roleLabel} onSignOut={onSignOut} signingOut={signingOut} />
        </header>
    );
});
