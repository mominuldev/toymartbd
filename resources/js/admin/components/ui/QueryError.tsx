import { ArrowClockwiseIcon, WarningCircleIcon } from '@phosphor-icons/react';
import { ApiError } from '@/lib/api';
import { useI18n } from '@/i18n/I18nProvider';
import { Button } from './Button';

/**
 * A failed query, with a retry. Shows the API's translated problem title when there
 * is one; never a stack trace or a raw message.
 */
export function QueryError({ error, onRetry }: { error: unknown; onRetry: () => void }) {
    const { t } = useI18n();
    const forbidden = error instanceof ApiError && error.status === 403;
    const title = forbidden ? t('error.forbidden') : t('error.title');

    return (
        <div role="alert" className="flex max-w-md flex-col items-start gap-3 rounded-md border border-line-default bg-surface p-5 shadow-e1">
            <div className="flex items-center gap-2 text-feedback-danger">
                <WarningCircleIcon size={20} aria-hidden />
                <p className="t-admin-section text-fg-primary">{title}</p>
            </div>
            <p className="t-admin-body text-fg-secondary">{t('error.body')}</p>
            {!forbidden && (
                <Button variant="outline" size="sm" onClick={onRetry}>
                    <ArrowClockwiseIcon size={16} aria-hidden />
                    {t('error.retry')}
                </Button>
            )}
        </div>
    );
}
