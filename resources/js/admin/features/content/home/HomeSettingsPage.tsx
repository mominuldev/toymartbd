import { Link } from 'react-router';
import { SquaresFourIcon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import { can, useMe } from '@/features/auth/api';
import { buttonClass } from '@/components/ui/Button';
import { ContentHeader } from '@/components/shell/ContentHeader';
import { SeoPanel } from '@/features/seo';

/** The homepage's singleton SEO subject (API `HomeSeoSubjects`: type `home`, id 1). */
const HOME_SEO = { type: 'home', id: 1 } as const;

/**
 * Online store → Pages → Home → Edit (owner request 2026-11-18): the homepage's settings, which
 * are its search and social settings — the same full SEO panel as every page (Google and share
 * previews, the score, Bangla share text, a Media Library share image). Home has no title, URL or
 * status of its own; its defaults come from Settings › Site & branding, linked here.
 */
export function HomeSettingsPage() {
    const { t } = useI18n();
    const { data: me } = useMe();

    return (
        <section className="flex flex-col gap-6">
            <ContentHeader
                breadcrumb={[{ label: t('content.crumb.store') }, { label: t('content.pages.backToList'), to: '/store/pages' }, { label: t('content.pages.home') }]}
                title={t('content.homeSettings.title')}
                subtitle={t('content.homeSettings.subtitle')}
                actions={
                    <Link to="/store/home" className={buttonClass('primary')}>
                        <SquaresFourIcon size={16} aria-hidden />
                        {t('content.pages.editSections')}
                    </Link>
                }
            />
            <p className="t-admin-caption text-fg-secondary">
                {t('content.homeSettings.defaults')}{' '}
                {can(me, 'settings.view') && (
                    <Link to="/settings/branding" className="text-fg-link underline">
                        {t('content.homeSettings.brandingLink')}
                    </Link>
                )}
            </p>
            {can(me, 'seo.view') ? <SeoPanel type={HOME_SEO.type} id={HOME_SEO.id} /> : <p className="t-admin-body text-fg-secondary">{t('content.homeSettings.noSeoAccess')}</p>}
        </section>
    );
}
