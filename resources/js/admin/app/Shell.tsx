import { useCallback, useEffect, useRef, useState, type KeyboardEvent } from 'react';
import { Outlet, useNavigate } from 'react-router';
import { XIcon } from '@phosphor-icons/react';
import { Sidebar } from '@/components/shell/Sidebar';
import { Topbar } from '@/components/shell/Topbar';
import { useLogout, useMe } from '@/features/auth/api';
import { useI18n } from '@/i18n/I18nProvider';

const COLLAPSED_KEY = 'tm-admin-sidebar-collapsed';

function storedCollapsed(): boolean {
    try {
        return localStorage.getItem(COLLAPSED_KEY) === '1';
    } catch {
        return false;
    }
}

/**
 * AppShell (docs/_design/04-admin-spec.md A1; Figma page 07 §14 Theme B).
 *   ≥1024  sidebar expanded 248, user may collapse to the 64 rail
 *   768+   sidebar locked to the 64 rail
 *   <768   off-canvas drawer 280 over a scrim; focus trapped, Esc closes, focus returns
 * The sidebar always renders in admin-dark, matching Figma.
 */
export function Shell() {
    const { t } = useI18n();
    const { data: me } = useMe();
    const logout = useLogout();
    const navigate = useNavigate();

    const [collapsed, setCollapsed] = useState(storedCollapsed);
    const [drawerOpen, setDrawerOpen] = useState(false);
    const menuButton = useRef<HTMLButtonElement>(null);
    const drawer = useRef<HTMLDivElement>(null);

    const toggleCollapsed = useCallback(() => {
        setCollapsed((value) => {
            try {
                localStorage.setItem(COLLAPSED_KEY, value ? '0' : '1');
            } catch {
                // Preference only.
            }
            return !value;
        });
    }, []);

    const closeDrawer = useCallback(() => {
        setDrawerOpen(false);
        menuButton.current?.focus();
    }, []);

    useEffect(() => {
        if (drawerOpen) drawer.current?.querySelector<HTMLElement>('a, button')?.focus();
    }, [drawerOpen]);

    function trapFocus(event: KeyboardEvent<HTMLDivElement>) {
        if (event.key === 'Escape') {
            event.preventDefault();
            closeDrawer();
            return;
        }
        if (event.key !== 'Tab' || !drawer.current) return;
        const focusable = drawer.current.querySelectorAll<HTMLElement>('a[href], button:not([disabled])');
        const first = focusable[0];
        const last = focusable[focusable.length - 1];
        if (!first || !last) return;
        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    }

    if (!me) return null; // RequireAuth guarantees a user; this only narrows the type.

    const signOut = () => logout.mutate(undefined, { onSettled: () => navigate('/login', { replace: true }) });

    return (
        <div className="flex min-h-dvh bg-canvas text-fg-primary">
            <a href="#content" className="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-50 focus:rounded-sm focus:bg-surface focus:px-3 focus:py-2 focus:shadow-focus">
                {t('shell.skipToContent')}
            </a>

            <aside
                data-theme="admin-dark"
                className={`sticky top-0 hidden h-dvh shrink-0 border-r border-line-default md:block md:w-16 ${collapsed ? '' : 'lg:w-62'}`}
            >
                <Sidebar permissions={me.permissions} collapsed={collapsed} onToggleCollapsed={toggleCollapsed} />
            </aside>

            {drawerOpen && (
                <div className="fixed inset-0 z-40 md:hidden">
                    <button type="button" tabIndex={-1} aria-hidden onClick={closeDrawer} className="absolute inset-0 bg-overlay" />
                    <div
                        ref={drawer}
                        data-theme="admin-dark"
                        role="dialog"
                        aria-modal="true"
                        aria-label={t('nav.primary')}
                        onKeyDown={trapFocus}
                        className="absolute inset-y-0 left-0 w-70 shadow-e4"
                    >
                        <Sidebar permissions={me.permissions} collapsed={false} variant="drawer" onNavigate={closeDrawer} />
                        <button
                            type="button"
                            onClick={closeDrawer}
                            aria-label={t('shell.closeMenu')}
                            className="absolute right-2 top-3 grid size-9 place-items-center rounded-sm text-fg-secondary hover:bg-subtle"
                        >
                            <XIcon size={20} aria-hidden />
                        </button>
                    </div>
                </div>
            )}

            <div className="flex min-w-0 flex-1 flex-col">
                <Topbar ref={menuButton} me={me} onOpenMenu={() => setDrawerOpen(true)} onSignOut={signOut} signingOut={logout.isPending} />
                <main id="content" tabIndex={-1} className="flex-1 p-4 md:p-5 lg:p-6">
                    <Outlet />
                </main>
            </div>
        </div>
    );
}
