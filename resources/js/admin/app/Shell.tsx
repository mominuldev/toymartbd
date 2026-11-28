import { useCallback, useEffect, useRef, useState, type KeyboardEvent } from 'react';
import { Outlet, useNavigate } from 'react-router';
import { XIcon } from '@phosphor-icons/react';
import { Sidebar } from '@/components/shell/Sidebar';
import { useInboxBadge } from '@/components/shell/useInboxBadge';
import { useInboxLiveUpdates } from '@/features/inbox/live';
import { useWorkingBranch } from '@/components/shell/workingBranch';
import { Topbar } from '@/components/shell/Topbar';
import { AskAiPanel } from '@/features/ai/advisor/AskAiPanel';
import { can, useLogout, useMe } from '@/features/auth/api';
import { useLowStockCount } from '@/features/inventory/api';
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
 *
 * Ask AI (Figma B · 42 603:51917): from `dock` (1720) a 440 column on the right that pushes the
 * page narrower, like the sidebar. Below that the page would be squeezed under the widths its
 * viewport breakpoints assume (4 KPI columns in 752), so the panel docks over the page's right
 * edge under the topbar (1024+) or covers it (<1024). It mounts on first open (so no advisor
 * request until then) and stays mounted, so closing keeps the conversation.
 */
export function Shell() {
    const { t } = useI18n();
    const { data: me } = useMe();
    const logout = useLogout();
    const navigate = useNavigate();

    const [collapsed, setCollapsed] = useState(storedCollapsed);
    // The sidebar's layout trails the width when collapsing: the expanded layout stays while the
    // width slides shut (a curtain over still content) and becomes the rail once it has. Expanding
    // switches at once, and the growing width reveals it. Both layouts share their geometry.
    const [rail, setRail] = useState(storedCollapsed);
    const railTimer = useRef<number | undefined>(undefined);
    const [drawerOpen, setDrawerOpen] = useState(false);
    const menuButton = useRef<HTMLButtonElement>(null);
    const drawer = useRef<HTMLDivElement>(null);
    // Nav count badges (Figma CountBadge 185:43 on SidebarItem): each a cheap server count.
    const lowStock = useLowStockCount(can(me, 'inventory.view'));
    const inbox = useInboxBadge(can(me, 'inbox.view'));
    // Live inbox (Reverb): a push refetches the lists, the badge and the open thread at once.
    useInboxLiveUpdates(can(me, 'inbox.view'));
    // The working branch (sidebar switcher): a per-device default for POS, purchases and stock screens.
    const working = useWorkingBranch();
    const [askOpen, setAskOpen] = useState(false);
    const [askMounted, setAskMounted] = useState(false);
    const askButton = useRef<HTMLButtonElement>(null);
    const toggleAsk = useCallback(() => {
        setAskMounted(true);
        setAskOpen((value) => !value);
    }, []);
    const closeAsk = useCallback(() => {
        setAskOpen(false);
        askButton.current?.focus();
    }, []);
    const counts: Record<string, number | undefined> = { '/inventory': lowStock.data?.count, '/inbox': inbox.data?.count };

    const toggleCollapsed = useCallback(() => {
        setCollapsed((value) => {
            const next = !value;
            try {
                localStorage.setItem(COLLAPSED_KEY, next ? '1' : '0');
            } catch {
                // Preference only.
            }
            window.clearTimeout(railTimer.current);
            if (next) {
                // transitionend ends the slide; the timer covers reduced motion and screens below 1280,
                // where the width does not change and no transition fires.
                railTimer.current = window.setTimeout(() => setRail(true), 360);
            } else setRail(false);
            return next;
        });
    }, []);

    useEffect(() => () => window.clearTimeout(railTimer.current), []);

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
    const user = { name: me.name, role: roleLabel, avatar: me.avatar_url };
    const canAsk = can(me, 'reports.view');
    const branches = {
        multi: working.multi,
        locations: working.locations,
        current: working.current,
        onSelect: working.select,
        manageHref: can(me, 'settings.view') ? '/settings/branches' : null,
    };

    return (
        <div className="flex min-h-dvh bg-canvas text-fg-primary">
            <a href="#content" className="sr-only focus:not-sr-only focus:fixed focus:top-4 focus:left-4 focus:z-50 focus:rounded-sm focus:bg-surface focus:px-3 focus:py-2 focus:shadow-focus">
                {t('shell.skipToContent')}
            </a>

            {/* Collapse / expand glides: the width eases over the slow motion token while the labels
                are clipped, so text never spills over the page mid-way. The branch menu is portalled
                (position: fixed), so the clip cannot cut it; reduced motion makes it instant. */}
            <aside
                data-theme="admin-dark"
                className={`sticky top-0 hidden h-dvh shrink-0 overflow-hidden transition-[width] duration-(--tm-duration-slow) ease-standard motion-reduce:transition-none lg:block lg:w-16 ${collapsed ? '' : 'xl:w-62'}`}
                onTransitionEnd={(event) => {
                    if (event.target === event.currentTarget && event.propertyName === 'width' && collapsed) {
                        window.clearTimeout(railTimer.current);
                        setRail(true);
                    }
                }}
            >
                <Sidebar permissions={me.permissions} counts={counts} collapsed={rail} user={user} branches={branches} />
            </aside>

            {drawerOpen && (
                <div className="fixed inset-0 z-40 lg:hidden">
                    <button type="button" tabIndex={-1} aria-hidden onClick={closeDrawer} className="absolute inset-0 bg-overlay" />
                    <div ref={drawer} role="dialog" aria-modal="true" aria-label={t('nav.primary')} onKeyDown={trapFocus} className="absolute inset-y-0 left-0 flex">
                        <div data-theme="admin-dark" className="h-full w-62 shadow-e4">
                            <Sidebar permissions={me.permissions} counts={counts} collapsed={false} variant="drawer" onNavigate={closeDrawer} user={user} branches={branches} />
                        </div>
                        <IconButton variant="outline" onClick={closeDrawer} aria-label={t('shell.closeMenu')} className="ms-3 mt-3">
                            <XIcon size={18} aria-hidden />
                        </IconButton>
                    </div>
                </div>
            )}

            <div className="flex min-w-0 flex-1 flex-col">
                <Topbar
                    ref={menuButton}
                    me={me}
                    roleLabel={roleLabel}
                    onOpenMenu={() => setDrawerOpen(true)}
                    collapsed={collapsed}
                    onToggleCollapsed={toggleCollapsed}
                    ask={canAsk ? { open: askOpen, onToggle: toggleAsk, ref: askButton } : undefined}
                    onSignOut={signOut}
                    signingOut={logout.isPending}
                />
                <main id="content" tabIndex={-1} className="flex-1 p-4 md:px-5 md:pt-5 md:pb-10 xl:px-6 xl:pt-6">
                    <Outlet />
                </main>
            </div>

            {canAsk && (
                <div
                    inert={!askOpen}
                    className={`fixed inset-y-0 end-0 z-40 w-full overflow-hidden shadow-e4 sm:w-110 lg:top-15 lg:z-30 dock:sticky dock:top-0 dock:bottom-auto dock:z-auto dock:h-dvh dock:shrink-0 dock:shadow-none dock:transition-[width] dock:duration-(--tm-duration-slow) dock:ease-standard motion-reduce:transition-none ${askOpen ? '' : 'max-dock:hidden dock:w-0'}`}
                >
                    <div className="h-full w-full sm:w-110">{askMounted && <AskAiPanel open={askOpen} onClose={closeAsk} />}</div>
                </div>
            )}
        </div>
    );
}
