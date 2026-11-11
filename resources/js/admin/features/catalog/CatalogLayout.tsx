import { NavLink, Outlet } from 'react-router';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';

const tabs: { to: string; label: MessageKey; end?: boolean }[] = [
    { to: '/products', label: 'catalog.tab.products', end: true },
    { to: '/products/categories', label: 'catalog.tab.categories' },
    { to: '/products/brands', label: 'catalog.tab.brands' },
];

/** Products section tabs (06-admin-feature-checklist §1: Products · Categories · Brands …). Figma AdminTab 185:125. */
export function CatalogLayout() {
    const { t } = useI18n();

    return (
        <div className="space-y-5">
            <nav aria-label={t('nav.products')} className="-mx-1 flex gap-1 overflow-x-auto border-b border-line-default px-1">
                {tabs.map((tab) => (
                    <NavLink
                        key={tab.to}
                        to={tab.to}
                        end={tab.end}
                        className={({ isActive }) =>
                            `-mb-px flex h-10 items-center border-b-2 px-3 t-admin-body-strong whitespace-nowrap ${
                                isActive ? 'border-line-brand text-fg-brand' : 'border-transparent text-fg-secondary hover:text-fg-primary'
                            }`
                        }
                    >
                        {t(tab.label)}
                    </NavLink>
                ))}
            </nav>
            <Outlet />
        </div>
    );
}
