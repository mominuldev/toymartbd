import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';

/** A sidebar section whose screens are designed but not built yet. */
export function ComingSoon({ title }: { title: MessageKey }) {
    const { t } = useI18n();

    return (
        <section className="space-y-3">
            <h1 className="t-admin-page-title text-fg-primary">{t(title)}</h1>
            <div className="max-w-prose rounded-md border border-line-default bg-surface p-5 shadow-e1">
                <p className="t-admin-body-strong text-fg-primary">{t('section.comingSoon')}</p>
                <p className="t-admin-body text-fg-secondary">{t('section.comingSoonBody')}</p>
            </div>
        </section>
    );
}
