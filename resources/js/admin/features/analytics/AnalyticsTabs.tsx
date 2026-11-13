import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { can, useMe } from '@/features/auth/api';
import { TabList, TabLink } from '@/components/ui/Tabs';

/**
 * Analytics' tab strip (Figma B · 24 Analytics 241:30584: Sales · Visitors & funnel · Products ·
 * Couriers · Marketing ROI · Live). Built tabs only; a tab shows when the user may open it.
 * Add a section by adding a row here; every Analytics page renders this one strip.
 */
const tabs: { to: string; label: MessageKey; permissions: string[] }[] = [
    { to: '/analytics/sales', label: 'analyticsNav.sales', permissions: ['reports.view'] },
    { to: '/analytics/customers', label: 'customerAnalytics.tab', permissions: ['reports.view', 'customers.view'] },
    { to: '/analytics/couriers', label: 'courierReport.tab', permissions: ['reports.view', 'shipments.view'] },
    { to: '/analytics/schedules', label: 'analyticsNav.schedules', permissions: ['reports.view', 'reports.export'] },
];

export function AnalyticsTabs() {
    const { t } = useI18n();
    const { data: me } = useMe();
    return (
        <TabList label={t('courierReport.tabs')}>
            {tabs
                .filter((tab) => tab.permissions.every((p) => can(me, p)))
                .map((tab) => (
                    <TabLink key={tab.to} to={tab.to} label={t(tab.label)} />
                ))}
        </TabList>
    );
}
