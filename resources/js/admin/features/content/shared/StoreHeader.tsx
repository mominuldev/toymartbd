import type { ReactNode } from 'react';
import { Link } from 'react-router';
import { ImageIcon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import { buttonClass } from '@/components/ui/Button';
import { ContentHeader } from '@/components/shell/ContentHeader';
import { TabLink, TabList } from '@/components/ui/Tabs';

/**
 * Figma B · 27 Pages & navigation (241:45525): "Store / Online store" crumb, title and subtitle,
 * Media library (outline) + the primary action on the right, then the AdminTab row. The design's
 * tabs are Pages · Header menu · Footer menu · Mobile menu · Theme; Theme is not built, and
 * Banners and Testimonials (no frame) join the same row.
 */
export function StoreHeader({ action, pageCount }: { action?: ReactNode; pageCount?: number }) {
    const { t } = useI18n();

    return (
        <>
            <ContentHeader
                breadcrumb={[{ label: t('content.crumb.store') }, { label: t('content.crumb.online') }]}
                title={t('content.title')}
                subtitle={t('content.subtitle')}
                actions={
                    <>
                        <Link to="/store/media" className={buttonClass('outline')}>
                            <ImageIcon size={16} aria-hidden />
                            {t('content.mediaLibrary')}
                        </Link>
                        {action}
                    </>
                }
            />
            <TabList label={t('content.sectionTabs')}>
                <TabLink to="/store/home" end label={t('homeBuilder.title')} />
                <TabLink to="/store/pages" end label={t('content.tab.pages')} count={pageCount} />
                <TabLink to="/store/navigation/header" label={t('content.tab.header')} />
                <TabLink to="/store/navigation/footer" label={t('content.tab.footer')} />
                <TabLink to="/store/navigation/mobile" label={t('content.tab.mobile')} />
                <TabLink to="/store/banners" end label={t('content.tab.banners')} />
                <TabLink to="/store/testimonials" end label={t('content.tab.testimonials')} />
                <TabLink to="/store/blog" label={t('blog.title')} />
            </TabList>
        </>
    );
}
