import { NavLink } from 'react-router';
import { ShoppingBagOpenIcon, SidebarSimpleIcon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import { Avatar } from './Avatar';
import { visibleNavigation } from './nav';

type SidebarProps = {
    permissions: readonly string[];
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
 * The nav scrolls without a visible scrollbar (owner request).
 */
export function Sidebar({ permissions, collapsed, onToggleCollapsed, onNavigate, variant = 'rail', user }: SidebarProps) {
    const { t } = useI18n();
    const groups = visibleNavigation(permissions);
    const drawer = variant === 'drawer';
    // 'always' = drawer, 'never' = user-collapsed rail, 'wide' = rail below xl, expanded at xl.
    const mode = drawer ? 'always' : collapsed ? 'never' : 'wide';
    const pick = (always: string, never: string, wide: string) => (mode === 'always' ? always : mode === 'never' ? never : wide);

    const label = pick('', 'sr-only', 'sr-only xl:not-sr-only');
    const onlyExpanded = pick('flex', 'hidden', 'hidden xl:flex');
    const onlyRail = pick('hidden', '', 'xl:hidden');

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
                            {group.items.map((item) => (
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
                                                isActive ? 'bg-brand-subtle t-admin-body-strong' : 't-admin-body hover:bg-subtle',
                                            ].join(' ')
                                        }
                                    >
                                        {({ isActive }) => (
                                            <>
                                                <item.icon size={20} aria-hidden className={`shrink-0 ${isActive ? 'text-fg-brand' : 'text-fg-primary'}`} />
                                                <span className={`min-w-0 flex-1 truncate ${label}`}>{t(item.key)}</span>
                                            </>
                                        )}
                                    </NavLink>
                                </li>
                            ))}
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
