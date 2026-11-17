import { useI18n } from '@/i18n/I18nProvider';

/** Placeholder — built by its own feature work (Figma "AI for growth" 536:82212). */
export function AiSettingsPage() {
    const { t } = useI18n();
    return <p className="t-admin-body text-fg-secondary">{t('ai.problem.not_configured.title')}</p>;
}
