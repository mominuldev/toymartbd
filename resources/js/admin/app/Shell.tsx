import { useCallback, useEffect, useRef, useState, type KeyboardEvent } from 'react';
import { Outlet, useNavigate } from 'react-router';
import { XIcon } from '@phosphor-icons/react';
import { Sidebar } from '@/components/shell/Sidebar';
import { Topbar } from '@/components/shell/Topbar';
import { useLogout, useMe } from '@/features/auth/api';
import { useI18n } from '@/i18n/I18nProvider';
import { IconButton } from '@/components/ui/IconButton';
import { en, type MessageKey } from '@/i18n/messages';

const COLLAPSED_KEY = 'tm-admin-sidebar-collapsed';

function storedCollapsed(): boolean {
    try {
        return localStorage.getItem(COLLAPSED_KEY) === '1';
    } catch {
        return false;
    }
}

/**
 * AppShell (docs/_design/04-admin-spec.md A1; Figma page 07 §14 Theme B, §10, §11).
 *   ≥1280  sidebar expanded 248, user may collapse to the 64 rail
 *   1024+  sidebar locked to the 64 rail (Figma section 10)
 *   <1024  no sidebar; Topbar / Tablet menu button opens the 248 drawer over a scrim
 *          (Figma Navigation drawer 230:22387); focus trapped, Esc closes, focus returns
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
    const roleKey = `role.${me.roles[0] ?? ''}`;
    const roleLabel = roleKey in en ? t(roleKey as MessageKey) : (me.roles[0] ?? '');
    const user = { name: me.name, role: roleLabel };

    return (
        <div className="flex min-h-dvh bg-canvas text-fg-primary">
            <a href="#content" className="sr-only focus:not-sr-only focus:fixed focus:top-4 focus:left-4 focus:z-50 focus:rounded-sm focus:bg-surface focus:px-3 focus:py-2 focus:shadow-focus">
                {t('shell.skipToContent')}
            </a>

            <aside data-theme="admin-dark" className={`sticky top-0 hidden h-dvh shrink-0 lg:block lg:w-16 ${collapsed ? '' : 'xl:w-62'}`}>
                <Sidebar permissions={me.permissions} collapsed={collapsed} onToggleCollapsed={toggleCollapsed} user={user} />
            </aside>

            {drawerOpen && (
                <div className="fixed inset-0 z-40 lg:hidden">
                    <button type="button" tabIndex={-1} aria-hidden onClick={closeDrawer} className="absolute inset-0 bg-overlay" />
                    <div ref={drawer} role="dialog" aria-modal="true" aria-label={t('nav.primary')} onKeyDown={trapFocus} className="absolute inset-y-0 left-0 flex">
                        <div data-theme="admin-dark" className="h-full w-62 shadow-e4">
                            <Sidebar permissions={me.permissions} collapsed={false} variant="drawer" onNavigate={closeDrawer} user={user} />
                        </div>
                        <IconButton variant="outline" onClick={closeDrawer} aria-label={t('shell.closeMenu')} className="ms-3 mt-3">
                            <XIcon size={18} aria-hidden />
                        </IconButton>
                    </div>
                </div>
            )}

            <div className="flex min-w-0 flex-1 flex-col">
                <Topbar ref={menuButton} me={me} roleLabel={roleLabel} onOpenMenu={() => setDrawerOpen(true)} onSignOut={signOut} signingOut={logout.isPending} />
                <main id="content" tabIndex={-1} className="flex-1 p-4 md:px-5 md:pt-5 md:pb-10 xl:px-6 xl:pt-6">
                    <Outlet />
                </main>
            </div>
        </div>
    );
}
