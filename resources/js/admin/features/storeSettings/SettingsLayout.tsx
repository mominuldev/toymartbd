import { NavLink, Outlet } from 'react-router';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { ContentHeader } from '@/components/shell/ContentHeader';
import { Note, useSettingsAccess } from './parts';

/**
 * Sections in Figma order. The frame also lists Languages, Domains & email, Notifications
 * and Danger zone; none of them has a backend yet, so they are not offered.
 */
const sections = [
    { to: 'business', label: 'storeSettings.nav.business' },
    { to: 'branches', label: 'storeSettings.nav.branches' },
    { to: 'checkout', label: 'storeSettings.nav.checkout' },
    { to: 'delivery', label: 'deliverySettings.nav' },
    { to: 'invoice', label: 'storeSettings.nav.invoice' },
] as const satisfies readonly { to: string; label: MessageKey }[];

/**
 * One nav item. Below lg it is an AdminTab (Figma 185:125, brand underline); from lg the
 * frame's left column: a 40-high row, the active one tinted bg/brand-subtle.
 */
function itemClass(active: boolean): string {
    const base = '-mb-px inline-flex h-10 shrink-0 items-center border-b-2 px-3 whitespace-nowrap transition-colors duration-150 ease-standard lg:mb-0 lg:rounded-sm lg:border-b-0';
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

    if (me && !canView) return <p className="t-admin-body text-fg-secondary">{t('error.forbidden')}</p>;

    return (
        <section className="flex flex-col gap-6">
            <ContentHeader
                breadcrumb={[{ label: t('storeSettings.crumb.settings') }, { label: t('storeSettings.crumb.store') }]}
                title={t('storeSettings.title')}
                subtitle={t('storeSettings.subtitle')}
            />
            {me && !canEdit && <Note>{t('storeSettings.readOnly')}</Note>}
            <div className="flex flex-col gap-6 lg:flex-row lg:items-start">
                <nav
                    aria-label={t('storeSettings.nav.label')}
                    className="scrollbar-none flex overflow-x-auto border-b border-line-default lg:sticky lg:top-6 lg:w-52 lg:shrink-0 lg:flex-col lg:overflow-visible lg:border-b-0"
                >
                    {sections.map((s) => (
                        <NavLink key={s.to} to={`/settings/${s.to}`} className={({ isActive }) => itemClass(isActive)}>
                            {t(s.label)}
                        </NavLink>
                    ))}
                </nav>
                <div className="flex min-w-0 flex-1 flex-col gap-6">
                    <Outlet />
                </div>
            </div>
        </section>
    );
}
