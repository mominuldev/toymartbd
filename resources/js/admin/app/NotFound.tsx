import { Link } from 'react-router';
import { buttonClass } from '@/components/ui/Button';
import { EmptyState } from '@/components/ui/EmptyState';
import { useI18n } from '@/i18n/I18nProvider';

/**
 * An admin URL that matches no screen (inside the shell, so the sidebar still works). Figma has
 * no frame for it; it follows AdminEmptyState 203:1531 — name the reason, then the next action.
 */
export function NotFound() {
    const { t } = useI18n();

    return (
        <section className="flex justify-center pt-8 lg:pt-16">
            <EmptyState
                reason="notFound"
                titleAs="h1"
                className="w-full max-w-md"
                title={t('error.notFound')}
                body={t('error.notFoundBody')}
                actions={
                    <Link to="/" className={buttonClass('outline')}>
                        {t('error.backHome')}
                    </Link>
                }
            />
        </section>
    );
}
