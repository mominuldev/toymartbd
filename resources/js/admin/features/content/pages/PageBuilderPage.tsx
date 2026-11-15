import { Link, useParams } from 'react-router';
import { GearSixIcon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import { buttonClass } from '@/components/ui/Button';
import { pageLayout } from '../home/api';
import { LayoutBuilder } from '../home/LayoutBuilder';
import { PageStateChip } from '../shared/PageStateChip';
import { usePage } from './api';

/**
 * A static page's section builder (owner decision 2026-11-16): About, Delivery, FAQ … are built
 * from the same sections as the homepage, in the same builder (Figma B · 26 241:44102), on the
 * page's own layout (`/content/pages/{id}/layout`). Title, URL, status and SEO stay on the page
 * settings screen, one click away.
 */
export function PageBuilderPage() {
    const { t } = useI18n();
    const params = useParams();
    const id = Number(params.pageId);
    const page = usePage(Number.isInteger(id) && id > 0 ? id : null);
    const title = page.data?.title || t('content.pages.edit');
    const settings = `/store/pages/${id}`;

    return (
        <LayoutBuilder
            key={id}
            base={pageLayout(id)}
            title={title}
            breadcrumb={[{ label: t('content.crumb.store') }, { label: t('content.tab.pages'), to: '/store/pages' }, { label: title }]}
            titleAddon={page.data && <PageStateChip page={page.data} />}
            actions={
                <Link to={settings} className={buttonClass('outline')}>
                    <GearSixIcon size={16} aria-hidden />
                    {t('content.pageBuilder.settings')}
                </Link>
            }
            backTo="/store/pages"
            copy={{
                back: t('content.pageBuilder.back'),
                readOnly: t('content.pageBuilder.readOnly'),
                canvas: t('content.pageBuilder.canvas'),
                failed: t('content.pageBuilder.loadFailed'),
                published: (version) => t('content.pageBuilder.published', { version }),
            }}
        />
    );
}
