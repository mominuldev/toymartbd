import { useEffect, useRef, useState, type CSSProperties, type ReactNode } from 'react';
import { Link, NavLink, useLocation } from 'react-router';
import { CaretDownIcon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { CountBadge } from '@/components/ui/CountBadge';
import { Avatar } from './Avatar';
import { BranchSwitcher } from './BranchSwitcher';
import type { WorkingBranchLocation } from './workingBranch';
import { visibleNavigation, type NavChild, type NavItem } from './nav';
import { BrandLogo, BrandMark } from '@/components/brand/BrandLogo';

/** A sub-page link is current at its path or below it — except under `activeUnless` (All integrations vs the channel pages). */
export function childActive(child: NavChild, pathname: string): boolean {
    if (child.activeUnless?.some((prefix) => pathname === prefix || pathname.startsWith(`${prefix}/`))) return false;
    return pathname === child.path || pathname.startsWith(`${child.path}/`);
}

type SidebarProps = {
    permissions: readonly string[];
    /** Live counts by nav path (`/inventory` → low-stock variants); a zero or missing count shows no badge. */
    counts?: Readonly<Record<string, number | undefined>>;
    /** The user's choice at ≥1280px. Below that the rail is forced (Figma section 10, 1024). */
    collapsed: boolean;
    /** Called after a link is followed — the drawer uses it to close itself. */
    onNavigate?: () => void;
    /** The off-canvas drawer (<1024px) always shows labels. */
    variant?: 'rail' | 'drawer';
    /** Signed-in user for the pinned footer. */
    user?: { name: string; role: string; avatar?: string | null };
    /** The branch switcher under the brand row (Figma BranchSwitcher 187:102); omitted, none is shown. */
    branches?: {
        multi: boolean;
        locations: WorkingBranchLocation[];
        current: WorkingBranchLocation | null;
        onSelect: (id: number) => void;
        manageHref: string | null;
    };
};

/**
 * Figma Sidebar 187:473 (expanded 248 / collapsed 64), always rendered inside
 * data-theme="admin-dark". Brand row 60 high with the Smart Junior mark (BrandMark) and name; nav items 38
 * high, radius/sm; overline group labels (dividers in the rail); a pinned user footer.
 * Active item: bg/brand-subtle + brand icon + semibold label (06-admin-feature-checklist §0).
 * An item with children (Online store) shows a caret and, while the route is inside it, a
 * submenu of SidebarSubItem links (321:59233): 32 high, label indented to the parent label.
 * The open parent drops its active fill where the submenu shows; the rail keeps the fill.
 * The nav scrolls without a visible scrollbar (owner request).
 */
/** Screen-reader wording for a nav item's count badge. */
const badgeLabels: Partial<Record<string, MessageKey>> = { '/inventory': 'nav.inventoryBadge', '/inbox': 'nav.inboxBadge' };

export function Sidebar({ permissions, counts = {}, collapsed, onNavigate, variant = 'rail', user, branches }: SidebarProps) {
    const { t } = useI18n();
    const { pathname } = useLocation();
    const groups = visibleNavigation(permissions);
    const drawer = variant === 'drawer';
    // 'always' = drawer, 'never' = user-collapsed rail, 'wide' = rail below xl, expanded at xl.
    const mode = drawer ? 'always' : collapsed ? 'never' : 'wide';
    const pick = (always: string, never: string, wide: string) => (mode === 'always' ? always : mode === 'never' ? never : wide);

    const label = pick('', 'sr-only', 'sr-only xl:not-sr-only');
    const onlyExpanded = pick('flex', 'hidden', 'hidden xl:flex');
    const onlyRail = pick('hidden', '', 'xl:hidden');
    // A parent whose submenu is showing reads as Default; in the rail it stays Active.
    const openParent = pick(
        't-admin-body hover:bg-subtle',
        'bg-brand-subtle t-admin-body-strong',
        'bg-brand-subtle t-admin-body-strong xl:bg-transparent xl:t-admin-body xl:hover:bg-subtle',
    );
    const openParentIcon = pick('text-fg-primary', 'text-fg-brand', 'text-fg-brand xl:text-fg-primary');

    return (
        // The content keeps its own width (rail 64, expanded 248) so the shell can clip it like a curtain
        // while the width eases: nothing inside reflows mid-way, and both layouts put every icon,
        // the logo and each row in the same place, so switching between them does not move anything.
        <div className={`flex h-full flex-col border-r border-line-default bg-surface ${pick('', 'w-16', 'w-16 xl:w-62')}`}>
            <div className={`flex h-15 shrink-0 items-center gap-2.5 overflow-hidden ${pick('ps-5 pe-3', 'ps-4.5', 'ps-4.5 xl:pe-3')}`}>
                {/* Rail: the rocket mark, centred in the 28 px icon column. Expanded: the On dark lockup
                    (the sidebar is always admin-dark), which is the name. */}
                <span className={`w-7 shrink-0 place-items-center ${pick('hidden', 'grid', 'grid xl:hidden')}`}>
                    <BrandMark className="h-9" />
                </span>
                <span className={onlyExpanded}>
                    <BrandLogo alt={t('app.name')} className="h-10" />
                </span>
                {/* The name for the rail only: where the lockup shows, its alt already says it. */}
                <span className={`sr-only ${onlyRail}`}>{t('app.name')}</span>
            </div>

            {branches && <BranchSwitcher {...branches} mode={mode} onNavigate={onNavigate} />}

            <nav aria-label={t('nav.primary')} className={`scrollbar-none flex min-h-0 flex-1 flex-col gap-px overflow-y-auto pt-1 pb-3 ${pick('px-3', 'px-2', 'px-2 xl:px-3')}`}>
                {groups.map((group, index) => (
                    <div key={group.label ?? index} className="flex flex-col gap-px">
                        {group.label && (
                            <>
                                <p className={`h-8 items-end px-3 pb-1 t-admin-overline whitespace-nowrap text-fg-tertiary ${onlyExpanded}`}>{t(group.label)}</p>
                                {/* As tall as the heading from 1280 up, so a group starts at the same height in both layouts. */}
                                <div aria-hidden className={`px-1 ${pick('hidden', 'flex py-1.5 xl:h-8 xl:items-end xl:py-0 xl:pb-2.5', 'flex py-1.5 xl:hidden')}`}>
                                    <div className="h-px w-10 bg-line-default" />
                                </div>
                            </>
                        )}
                        <ul className="flex flex-col gap-px">
                            {group.items.map((item) => {
                                const open = item.children !== undefined && (pathname === item.path || pathname.startsWith(`${item.path}/`));
                                // In the rail a parent's sub-pages open beside its icon on hover or focus.
                                const flyout = !drawer && item.children ? pick('', '', 'xl:hidden') : null;
                                return (
                                <RailSubmenu key={item.path} item={item} visibility={flyout} onNavigate={onNavigate}>
                                    <NavLink
                                        to={item.path}
                                        end={item.path === '/'}
                                        onClick={onNavigate}
                                        title={drawer || item.children ? undefined : t(item.key)}
                                        className={({ isActive }) =>
                                            [
                                                'flex items-center rounded-sm text-fg-primary transition-colors duration-150 ease-standard',
                                                pick('h-9.5 gap-3 px-3', 'h-9 w-12 justify-center xl:h-9.5', 'h-9 w-12 justify-center xl:h-9.5 xl:w-auto xl:justify-start xl:gap-3 xl:px-2.5'),
                                                isActive ? (open ? openParent : 'bg-brand-subtle t-admin-body-strong') : 't-admin-body hover:bg-subtle',
                                            ].join(' ')
                                        }
                                    >
                                        {({ isActive }) => {
                                            const count = counts[item.path];
                                            const badgeLabel = badgeLabels[item.path];
                                            const iconTone = isActive ? (open ? openParentIcon : 'text-fg-brand') : 'text-fg-primary';
                                            return (
                                                <>
                                                    <item.icon size={20} aria-hidden className={`shrink-0 ${iconTone}`} />
                                                    <span className={`min-w-0 flex-1 truncate ${label}`}>{t(item.key)}</span>
                                                    {item.children && (
                                                        <CaretDownIcon
                                                            size={16}
                                                            aria-hidden
                                                            className={`shrink-0 text-fg-secondary transition-transform duration-150 ease-standard ${open ? '' : '-rotate-90'} ${onlyExpanded}`}
                                                        />
                                                    )}
                                                    {count !== undefined && count > 0 && (
                                                        <>
                                                            {/* Figma CountBadge 185:43: bg/subtle, on the active item bg/surface. Labels only where the item shows its label. */}
                                                            <span aria-hidden className={onlyExpanded}>
                                                                <CountBadge count={count} tone={isActive ? 'onBrand' : 'neutral'} />
                                                            </span>
                                                            {badgeLabel && <span className="sr-only">{`, ${t(badgeLabel, { count })}`}</span>}
                                                        </>
                                                    )}
                                                </>
                                            );
                                        }}
                                    </NavLink>
                                    {open && item.children && (
                                        <ul aria-label={t(item.key)} className={`flex-col gap-px pt-px pb-1 ${onlyExpanded}`}>
                                            {item.children.map((child) => (
                                                <li key={child.path}>
                                                    <Link
                                                        to={child.path}
                                                        onClick={onNavigate}
                                                        aria-current={childActive(child, pathname) ? 'page' : undefined}
                                                        className={[
                                                            'flex h-8 items-center rounded-sm ps-11 pe-3 transition-colors duration-150 ease-standard',
                                                            childActive(child, pathname) ? 'bg-brand-subtle t-admin-body-strong text-fg-primary' : 't-admin-body text-fg-secondary hover:bg-subtle',
                                                        ].join(' ')}
                                                    >
                                                        <span className="min-w-0 truncate">{t(child.key)}</span>
                                                    </Link>
                                                </li>
                                            ))}
                                        </ul>
                                    )}
                                </RailSubmenu>
                                );
                            })}
                        </ul>
                    </div>
                ))}
            </nav>

            {user && (
                <div
                    className={`flex shrink-0 border-t border-line-default ${pick(
                        'items-center gap-2.5 py-3 ps-4 pe-3',
                        'flex-col items-center gap-2.5 p-3',
                        'flex-col items-center gap-2.5 p-3 xl:flex-row xl:py-3 xl:ps-4 xl:pe-3',
                    )}`}
                >
                    <Avatar name={user.name} src={user.avatar} />
                    <span className={`min-w-0 flex-1 flex-col whitespace-nowrap ${onlyExpanded}`}>
                        <span className="truncate t-admin-body-strong text-fg-primary">{user.name}</span>
                        <span className="truncate t-admin-caption text-fg-tertiary">{user.role}</span>
                    </span>
                </div>
            )}
        </div>
    );
}

/**
 * A nav entry. With `visibility` (a parent in the rail) it also opens its sub-pages in a small
 * panel beside the icon on hover or keyboard focus — the rail has no room for the inline submenu.
 * The panel is position: fixed inside the <li>, so the sidebar's clip cannot cut it and Tab goes
 * from the icon straight into its links. Leaving closes it after a short grace (so the pointer can
 * cross the gap); Escape closes it and returns focus to the icon. `visibility` 'xl:hidden' keeps it
 * to the rail below 1280, where the sidebar expands and shows the submenu inline.
 */
function RailSubmenu({ item, visibility, onNavigate, children }: { item: NavItem; visibility: string | null; onNavigate?: () => void; children: ReactNode }) {
    const { t } = useI18n();
    const { pathname } = useLocation();
    const root = useRef<HTMLLIElement>(null);
    const timer = useRef<number | undefined>(undefined);
    const [style, setStyle] = useState<CSSProperties | null>(null);
    const items = item.children ?? [];

    const show = () => {
        window.clearTimeout(timer.current);
        const anchor = root.current?.querySelector('a');
        if (!anchor) return;
        const box = anchor.getBoundingClientRect();
        const height = 52 + items.length * 34; // header + rows, to keep the panel on screen
        setStyle({ insetInlineStart: box.right, top: Math.max(8, Math.min(box.top - 6, window.innerHeight - height - 8)) });
    };
    const hide = (delay = 140) => {
        window.clearTimeout(timer.current);
        timer.current = window.setTimeout(() => setStyle(null), delay);
    };

    useEffect(() => {
        if (!style) return;
        // A scrolled nav or page would leave the panel beside the wrong icon.
        const close = () => setStyle(null);
        window.addEventListener('scroll', close, true);
        window.addEventListener('resize', close);
        return () => {
            window.removeEventListener('scroll', close, true);
            window.removeEventListener('resize', close);
        };
    }, [style]);

    useEffect(() => () => window.clearTimeout(timer.current), []);

    if (visibility === null) return <li>{children}</li>;

    return (
        <li
            ref={root}
            onMouseEnter={show}
            onMouseLeave={() => hide()}
            onFocus={(event) => {
                // Only focus arriving from outside opens it: Escape hands focus back to the icon from inside.
                if (!root.current?.contains(event.relatedTarget as Node | null)) show();
            }}
            onBlur={(event) => {
                if (!root.current?.contains(event.relatedTarget as Node | null)) hide(0);
            }}
            onKeyDown={(event) => {
                if (event.key === 'Escape' && style) {
                    event.stopPropagation();
                    setStyle(null);
                    root.current?.querySelector('a')?.focus();
                }
            }}
        >
            {children}
            {style && (
                // ps-2 is an invisible bridge over the gap, so moving the pointer across keeps it open.
                <div className={`fixed z-40 ps-2 ${visibility}`} style={style}>
                    <div className="min-w-52 rounded-md border border-line-default bg-surface-raised p-1 shadow-e3">
                        <p className="px-3 pt-2 pb-1 t-admin-overline whitespace-nowrap text-fg-tertiary uppercase">{t(item.key)}</p>
                        <ul aria-label={t(item.key)} className="flex flex-col gap-px">
                            {items.map((child) => (
                                <li key={child.path}>
                                    <Link
                                        to={child.path}
                                        onClick={() => {
                                            setStyle(null);
                                            onNavigate?.();
                                        }}
                                        aria-current={childActive(child, pathname) ? 'page' : undefined}
                                        className={[
                                            'flex h-8 items-center rounded-sm px-3 whitespace-nowrap transition-colors duration-150 ease-standard',
                                            childActive(child, pathname)
                                                ? 'bg-brand-subtle t-admin-body-strong text-fg-primary'
                                                : 't-admin-body text-fg-secondary hover:bg-subtle hover:text-fg-primary',
                                        ].join(' ')}
                                    >
                                        {t(child.key)}
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    </div>
                </div>
            )}
        </li>
    );
}
