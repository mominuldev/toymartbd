import { useState } from 'react';
import { NavLink, Outlet } from 'react-router';
import { BuildingsIcon, CashRegisterIcon, FileTextIcon, ListChecksIcon, StorefrontIcon, TruckIcon, WarehouseIcon, type Icon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { ContentHeader } from '@/components/shell/ContentHeader';
import { useSingleShop } from './branchModeApi';
import { Note, useSettingsAccess, type SettingsOutletContext } from './parts';

/**
 * Sections in Figma order. The frame also lists Languages, Domains & email, Notifications
 * and Danger zone; none of them has a backend yet, so they are not offered.
 */
type Section = { to: string; label: MessageKey; icon: Icon; isNew?: boolean };

/** Figma Premium nav card 390:69048: one "Store" group, a Phosphor icon per item, "New" on Site & branding. */
const sections: readonly Section[] = [
    { to: 'business', label: 'storeSettings.nav.business', icon: BuildingsIcon },
    { to: 'branding', label: 'storeSettings.nav.branding', icon: StorefrontIcon, isNew: true },
    { to: 'branches', label: 'storeSettings.nav.branches', icon: WarehouseIcon },
    { to: 'checkout', label: 'storeSettings.nav.checkout', icon: ListChecksIcon },
    { to: 'delivery', label: 'deliverySettings.nav', icon: TruckIcon },
    { to: 'invoice', label: 'storeSettings.nav.invoice', icon: FileTextIcon },
    { to: 'pos', label: 'storeSettings.nav.pos', icon: CashRegisterIcon },
];

/**
 * One nav item. Below lg it is an AdminTab (Figma 185:125 / mobile 396:69819, brand
 * underline, no icon); from lg a 40-high row in the nav card — 18 icon, 10 gap, 12 / 10
 * side padding, the active one tinted bg/brand-subtle.
 */
function itemClass(active: boolean): string {
    const base =
        'group -mb-px inline-flex h-10 shrink-0 items-center border-b-2 px-3 whitespace-nowrap transition-colors duration-150 ease-standard lg:mb-0 lg:gap-2.5 lg:rounded-sm lg:border-b-0 lg:pr-2.5 lg:pl-3';
    return active
        ? `${base} border-brand t-admin-button text-fg-primary lg:bg-brand-subtle lg:t-admin-body-strong`
        : `${base} border-transparent t-admin-body text-fg-secondary hover:text-fg-primary lg:hover:bg-subtle`;
}

/**
 * Settings › Store (Figma B · 38 241:50827): ContentHeader, the section nav on the left and the
 * section in an <Outlet />. Each section is its own route and saves on its own (the frame's
 * single header "Save changes" assumed one long page). Needs settings.view; without
 * settings.update every section is read-only.
 */
export function SettingsLayout() {
    const { t } = useI18n();
    const { me, canView, canEdit } = useSettingsAccess();
    // Where a section's sticky save bar is portalled (StickySaveBar), so it spans the content width.
    const [saveBarSlot, setSaveBarSlot] = useState<HTMLDivElement | null>(null);
    const outlet: SettingsOutletContext = { saveBarSlot };
    // A single shop has one location: the item reads "Shop & warehouse" (Branch setup).
    const single = useSingleShop() === true;
    const labelOf = (s: Section): MessageKey => (s.to === 'branches' && single ? 'branchMode.nav.single' : s.label);

    if (me && !canView) return <p className="t-admin-body text-fg-secondary">{t('error.forbidden')}</p>;

    return (
        <section className="flex flex-col gap-6">
            <ContentHeader
                breadcrumb={[{ label: t('storeSettings.crumb.settings') }, { label: t('storeSettings.crumb.store') }]}
                title={t('storeSettings.title')}
                subtitle={
                    <>
                        {t('storeSettings.subtitle')}
                        <span className="hidden md:inline">{t('storeSettings.subtitleMore')}</span>
                    </>
                }
            />
            {me && !canEdit && <Note>{t('storeSettings.readOnly')}</Note>}
            <div className="flex flex-col gap-6 lg:flex-row lg:items-start">
                <nav
                    aria-label={t('storeSettings.nav.label')}
                    className="scrollbar-none flex overflow-x-auto border-b border-line-default lg:sticky lg:top-6 lg:w-58 lg:shrink-0 lg:flex-col lg:gap-0.5 lg:overflow-visible lg:rounded-md lg:border lg:bg-surface lg:p-2 lg:shadow-e1"
                >
                    <p aria-hidden className="hidden py-1.5 pl-3 t-admin-overline text-fg-tertiary uppercase lg:block">
                        {t('storeSettings.nav.group')}
                    </p>
                    {sections.map((s) => (
                        <NavLink key={s.to} to={`/settings/${s.to}`} className={({ isActive }) => itemClass(isActive)}>
                            {({ isActive }) => (
                                <>
                                    <s.icon size={18} aria-hidden className={`hidden shrink-0 lg:block ${isActive ? 'text-fg-brand' : 'text-fg-secondary'}`} />
                                    <span className="lg:min-w-0 lg:flex-1 lg:truncate">{t(labelOf(s))}</span>
                                    {s.isNew && (
                                        <span className="hidden shrink-0 rounded-full bg-surface px-1.5 py-px t-admin-badge text-fg-brand lg:inline">{t('storeSettings.nav.new')}</span>
                                    )}
                                </>
                            )}
                        </NavLink>
                    ))}
                </nav>
                <div className="flex min-w-0 flex-1 flex-col gap-6">
                    <Outlet context={outlet} />
                </div>
            </div>
            {/* display:contents, so the portalled FormActions is sticky against this whole section. */}
            <div ref={setSaveBarSlot} className="contents" />
        </section>
    );
}
