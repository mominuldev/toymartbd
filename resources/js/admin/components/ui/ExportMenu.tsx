import { DownloadSimpleIcon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import { buttonClass } from './Button';

export type ExportFormat = 'csv' | 'xlsx';

/** The language of the labels and sheet names inside the file: the admin's current locale. */
export const exportLocale = (locale: string): 'en' | 'bn' => (locale === 'bn' ? 'bn' : 'en');

/**
 * Figma Export button (AdminButton outline md, 184:242) opening the two formats. Each item is
 * a plain download link to the server-built file (rule 1) — the cookie session authorises it,
 * so nothing is fetched or computed here. `href` receives the format and the label locale.
 */
export function ExportMenu({ href }: { href: (format: ExportFormat, locale: 'en' | 'bn') => string }) {
    const { t, locale } = useI18n();
    const lang = exportLocale(locale);
    return (
        <details className="relative">
            <summary className={`${buttonClass('outline')} cursor-pointer list-none [&::-webkit-details-marker]:hidden`}>
                <DownloadSimpleIcon size={16} aria-hidden />
                {t('reports.export')}
            </summary>
            <div className="absolute end-0 z-10 mt-1 flex w-48 flex-col rounded-sm border border-line-default bg-surface p-1 shadow-e2">
                <a href={href('xlsx', lang)} download className="rounded-xs px-3 py-2 t-admin-body text-fg-primary hover:bg-subtle">
                    {t('reports.export.xlsx')}
                </a>
                <a href={href('csv', lang)} download className="rounded-xs px-3 py-2 t-admin-body text-fg-primary hover:bg-subtle">
                    {t('reports.export.csv')}
                </a>
            </div>
        </details>
    );
}
