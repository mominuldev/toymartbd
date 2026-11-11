import { Outlet } from 'react-router';
import { TabLink, TabList } from '@/components/ui/Tabs';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';

const tabs: { to: string; label: MessageKey; end?: boolean }[] = [
    { to: '/products', label: 'catalog.tab.products', end: true },
    { to: '/products/categories', label: 'catalog.tab.categories' },
    { to: '/products/brands', label: 'catalog.tab.brands' },
    { to: '/products/attributes', label: 'catalog.tab.attributes' },
];

/** Products section tabs (06-admin-feature-checklist §1: Products · Categories · Brands · Attributes …). Figma AdminTab 185:125. */
export function CatalogLayout() {
    const { t } = useI18n();

    return (
        <div className="flex flex-col gap-6">
            <TabList label={t('nav.products')}>
                {tabs.map((tab) => (
                    <TabLink key={tab.to} to={tab.to} end={tab.end} label={t(tab.label)} />
                ))}
            </TabList>
            <Outlet />
        </div>
    );
}
