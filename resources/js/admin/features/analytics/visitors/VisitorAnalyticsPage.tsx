import { useI18n } from '@/i18n/I18nProvider';
import { ContentHeader } from '@/components/shell/ContentHeader';
import { AnalyticsTabs } from '../AnalyticsTabs';

/** Analytics › Visitors & funnel — placeholder from the batch scaffold; the feature agent replaces this file. */
export function VisitorAnalyticsPage() {
    const { t } = useI18n();
    return (
        <section className="flex flex-col gap-6">
            <ContentHeader breadcrumb={[{ label: t('courierReport.crumb') }]} title={t('analyticsVisitors.tab')} />
            <AnalyticsTabs />
        </section>
    );
}
