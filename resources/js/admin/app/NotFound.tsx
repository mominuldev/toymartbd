import { Link } from 'react-router';
import { useI18n } from '@/i18n/I18nProvider';

export function NotFound() {
    const { t } = useI18n();

    return (
        <section className="space-y-3">
            <h1 className="t-admin-page-title text-fg-primary">{t('error.notFound')}</h1>
            <p className="t-admin-body text-fg-secondary">{t('error.notFoundBody')}</p>
            <Link to="/" className="t-admin-body-strong text-fg-link underline">
                {t('error.backHome')}
            </Link>
        </section>
    );
}
