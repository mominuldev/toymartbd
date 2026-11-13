import { NavLink, useLocation } from 'react-router';
import { CaretDownIcon, ShoppingBagOpenIcon, SidebarSimpleIcon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { CountBadge } from '@/components/ui/CountBadge';
import { Avatar } from './Avatar';
import { visibleNavigation } from './nav';

type SidebarProps = {
    permissions: readonly string[];
    /** Live counts by nav path (`/inventory` → low-stock variants); a zero or missing count shows no badge. */
    counts?: Readonly<Record<string, number | undefined>>;
    /** The user's choice at ≥1280px. Below that the rail is forced (Figma section 10, 1024). */
    collapsed: boolean;
    onToggleCollapsed?: () => void;
    /** Called after a link is followed — the drawer uses it to close itself. */
    onNavigate?: () => void;
    /** The off-canvas drawer (<1024px) always shows labels. */
    variant?: 'rail' | 'drawer';
    /** Signed-in user for the pinned footer. */
    user?: { name: string; role: string };
};

/**
 * Figma Sidebar 187:473 (expanded 248 / collapsed 64), always rendered inside
 * data-theme="admin-dark". Brand row 60 high with the gradient logo tile; nav items 38
 * high, radius/sm; overline group labels (dividers in the rail); a pinned user footer.
 * Active item: bg/brand-subtle + brand icon + semibold label (06-admin-feature-checklist §0).
 * An item with children (Online store) shows a caret and, while the route is inside it, a
 * submenu of SidebarSubItem links (321:59233): 32 high, label indented to the parent label.
 * The open parent drops its active fill where the submenu shows; the rail keeps the fill.
 * The nav scrolls without a visible scrollbar (owner request).
 */
/** Screen-reader wording for a nav item's count badge. */
const badgeLabels: Partial<Record<string, MessageKey>> = { '/inventory': 'nav.inventoryBadge' };

export function Sidebar({ permissions, counts = {}, collapsed, onToggleCollapsed, onNavigate, variant = 'rail', user }: SidebarProps) {
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
        <div className="flex h-full flex-col border-r border-line-default bg-surface">
            <div className={`flex h-15 shrink-0 items-center gap-2.5 overflow-hidden ${pick('ps-5 pe-3', 'ps-4.5', 'ps-4.5 xl:ps-5 xl:pe-3')}`}>
                <span aria-hidden className="grid size-7 shrink-0 place-items-center rounded-sm bg-[image:var(--tm-gradient-button-primary)] text-fg-on-brand">
                    <ShoppingBagOpenIcon size={16} weight="fill" />
                </span>
                <span className={`flex-col whitespace-nowrap ${onlyExpanded}`}>
                    <span lang="en" className="t-h3 text-fg-primary lg:text-lg/[1.3]">
                        {t('app.wordmark')}
                    </span>
                    <span className="t-admin-caption text-fg-tertiary">
                        {t('app.admin')} · {t('app.name')}
                    </span>
                </span>
                <span className="sr-only">{t('app.name')}</span>
            </div>

            <nav aria-label={t('nav.primary')} className={`scrollbar-none flex min-h-0 flex-1 flex-col gap-px overflow-y-auto pt-1 pb-3 ${pick('px-3', 'px-2', 'px-2 xl:px-3')}`}>
                {groups.map((group, index) => (
                    <div key={group.label ?? index} className="flex flex-col gap-px">
                        {group.label && (
                            <>
                                <p className={`items-end px-3 pt-3 pb-1 t-admin-overline whitespace-nowrap text-fg-tertiary ${onlyExpanded}`}>{t(group.label)}</p>
                                <div aria-hidden className={`px-1 py-1.5 ${onlyRail}`}>
                                    <div className="h-px w-10 bg-line-default" />
                                </div>
                            </>
                        )}
                        <ul className="flex flex-col gap-px">
                            {group.items.map((item) => {
                                const open = item.children !== undefined && (pathname === item.path || pathname.startsWith(`${item.path}/`));
                                return (
                                <li key={item.path}>
                                    <NavLink
                                        to={item.path}
                                        end={item.path === '/'}
                                        onClick={onNavigate}
                                        title={drawer ? undefined : t(item.key)}
                                        className={({ isActive }) =>
                                            [
                                                'flex items-center rounded-sm text-fg-primary transition-colors duration-150 ease-standard',
                                                pick('h-9.5 gap-3 px-3', 'h-9 w-12 justify-center', 'h-9 w-12 justify-center xl:h-9.5 xl:w-auto xl:justify-start xl:gap-3 xl:px-3'),
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
                                                    <NavLink
                                                        to={child.path}
                                                        onClick={onNavigate}
                                                        className={({ isActive }) =>
                                                            [
                                                                'flex h-8 items-center rounded-sm ps-11 pe-3 transition-colors duration-150 ease-standard',
                                                                isActive ? 'bg-brand-subtle t-admin-body-strong text-fg-primary' : 't-admin-body text-fg-secondary hover:bg-subtle',
                                                            ].join(' ')
                                                        }
                                                    >
                                                        <span className="min-w-0 truncate">{t(child.key)}</span>
                                                    </NavLink>
                                                </li>
                                            ))}
                                        </ul>
                                    )}
                                </li>
                                );
                            })}
                        </ul>
                    </div>
                ))}
            </nav>

            {(user || (!drawer && onToggleCollapsed)) && (
                <div
                    className={`flex shrink-0 border-t border-line-default ${pick(
                        'items-center gap-2.5 py-3 ps-4 pe-3',
                        'flex-col items-center gap-2.5 p-3',
                        'flex-col items-center gap-2.5 p-3 xl:flex-row xl:py-3 xl:ps-4 xl:pe-3',
                    )}`}
                >
                    {user && (
                        <>
                            <Avatar name={user.name} />
                            <span className={`min-w-0 flex-1 flex-col whitespace-nowrap ${onlyExpanded}`}>
                                <span className="truncate t-admin-body-strong text-fg-primary">{user.name}</span>
                                <span className="truncate t-admin-caption text-fg-tertiary">{user.role}</span>
                            </span>
                        </>
                    )}
                    {!drawer && onToggleCollapsed && (
                        <button
                            type="button"
                            onClick={onToggleCollapsed}
                            aria-label={collapsed ? t('shell.expand') : t('shell.collapse')}
                            aria-pressed={collapsed}
                            className="hidden size-8 shrink-0 place-items-center rounded-sm text-fg-primary hover:bg-subtle xl:grid"
                        >
                            <SidebarSimpleIcon size={20} aria-hidden />
                        </button>
                    )}
                </div>
            )}
        </div>
    );
}
