import { NavLink } from 'react-router';
import { SidebarSimpleIcon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import { visibleNavigation } from './nav';

type SidebarProps = {
    permissions: readonly string[];
    /** Icon rail. Forced at 768–1023px by CSS; a user choice at ≥1024px. */
    collapsed: boolean;
    onToggleCollapsed?: () => void;
    /** Called after a link is followed — the drawer uses it to close itself. */
    onNavigate?: () => void;
    /** The off-canvas drawer always shows labels. */
    variant?: 'rail' | 'drawer';
};

/**
 * Figma Sidebar 187:473. Rendered inside data-theme="admin-dark" in both admin themes.
 * Active item: bg/brand-subtle with a brand icon, not a solid brand fill
 * (06-admin-feature-checklist §0, "calm pro + brand").
 */
export function Sidebar({ permissions, collapsed, onToggleCollapsed, onNavigate, variant = 'rail' }: SidebarProps) {
    const { t } = useI18n();
    const groups = visibleNavigation(permissions);
    const drawer = variant === 'drawer';
    // In the rail, labels hide when the user collapses it (lg) and always at md.
    const labelClass = drawer ? '' : collapsed ? 'sr-only' : 'sr-only lg:not-sr-only';

    return (
        <div className="flex h-full flex-col gap-1 bg-surface p-2 lg:p-3">
            <div className="flex h-14 shrink-0 items-center gap-3 px-2">
                <span aria-hidden className="grid size-7 shrink-0 place-items-center rounded-sm bg-brand t-admin-caption-strong text-fg-on-brand">
                    TM
                </span>
                <span className={`t-admin-body-strong text-fg-primary ${labelClass}`}>{t('app.name')}</span>
            </div>

            <nav aria-label={t('nav.primary')} className="scrollbar-thin -mx-1 flex-1 overflow-y-auto px-1">
                {groups.map((group, index) => (
                    <div key={group.label ?? index} className="py-1">
                        {group.label &&
                            (drawer || !collapsed ? (
                                <p className={`flex h-7 items-end px-3 pb-1 t-admin-overline text-fg-tertiary ${drawer ? '' : 'hidden lg:flex'}`}>
                                    {t(group.label)}
                                </p>
                            ) : null)}
                        {group.label && !drawer && (
                            <hr className={`mx-2 my-1 border-line-default ${collapsed ? '' : 'lg:hidden'}`} />
                        )}
                        <ul className="space-y-0.5">
                            {group.items.map((item) => (
                                <li key={item.path}>
                                    <NavLink
                                        to={item.path}
                                        end={item.path === '/'}
                                        onClick={onNavigate}
                                        title={drawer ? undefined : t(item.key)}
                                        className={({ isActive }) =>
                                            [
                                                'group flex h-10 items-center gap-3 rounded-md px-3 t-admin-body transition-colors duration-150 ease-standard',
                                                isActive ? 'bg-brand-subtle text-fg-primary' : 'text-fg-primary hover:bg-subtle',
                                            ].join(' ')
                                        }
                                    >
                                        {({ isActive }) => (
                                            <>
                                                <item.icon
                                                    size={20}
                                                    weight={isActive ? 'fill' : 'regular'}
                                                    aria-hidden
                                                    className={`shrink-0 ${isActive ? 'text-fg-brand' : 'text-fg-secondary'}`}
                                                />
                                                <span className={`truncate ${labelClass}`}>{t(item.key)}</span>
                                            </>
                                        )}
                                    </NavLink>
                                </li>
                            ))}
                        </ul>
                    </div>
                ))}
            </nav>

            {!drawer && onToggleCollapsed && (
                <div className="hidden shrink-0 pt-2 lg:block">
                    <button
                        type="button"
                        onClick={onToggleCollapsed}
                        aria-label={collapsed ? t('shell.expand') : t('shell.collapse')}
                        aria-pressed={collapsed}
                        className="grid size-8 place-items-center rounded-sm text-fg-secondary hover:bg-subtle"
                    >
                        <SidebarSimpleIcon size={20} aria-hidden />
                    </button>
                </div>
            )}
        </div>
    );
}
