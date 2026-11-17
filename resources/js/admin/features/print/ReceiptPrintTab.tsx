import { useI18n } from '@/i18n/I18nProvider';
import { PrintTabs } from './parts';

/** Settings › Invoices, receipts & labels › Receipt (placeholder — built next). */
export function ReceiptPrintTab() {
    const { t } = useI18n();
    return (
        <section className="flex min-w-0 flex-col gap-6">
            <PrintTabs />
            <p className="t-admin-body text-fg-secondary">{t('print.tab.receipt')}</p>
        </section>
    );
}
