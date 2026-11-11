import { useMe } from '@/features/auth/api';
import { useI18n } from '@/i18n/I18nProvider';

/**
 * Placeholder until the dashboard API lands (Figma page 07 §14, docs/_design/04-admin-spec.md Part B).
 * It proves the signed-in shell end to end: session, locale, theme, tokens.
 */
export function Dashboard() {
    const { t } = useI18n();
    const { data: me } = useMe();

    return (
        <section className="space-y-4">
            <h1 className="t-admin-page-title text-fg-primary">{t('dashboard.title')}</h1>
            <p className="t-admin-body max-w-prose text-fg-secondary">{t('dashboard.welcome', { name: me?.name ?? '' })}</p>
        </section>
    );
}
