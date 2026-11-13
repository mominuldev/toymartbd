import type { ReactNode } from 'react';
import { Link } from 'react-router';
import { ImageIcon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { buttonClass } from '@/components/ui/Button';
import { ContentHeader } from '@/components/shell/ContentHeader';
import { TabLink, TabList } from '@/components/ui/Tabs';

/** Which Online store sub-page the header sits on; each one is its own sidebar submenu item. */
export type StoreSection = 'pages' | 'banners' | 'testimonials';

const sections: Record<Exclude<StoreSection, 'pages'>, { crumb: MessageKey; title: MessageKey; subtitle: MessageKey }> = {
    banners: { crumb: 'content.tab.banners', title: 'content.banners.title', subtitle: 'content.banners.subtitle' },
    testimonials: { crumb: 'content.tab.testimonials', title: 'content.testimonials.title', subtitle: 'content.testimonials.subtitle' },
};

/**
 * Online store sub-page header. Pages and the three menus share Figma B · 27 Pages & navigation
 * (241:45525): "Store / Online store" crumb, title and subtitle, Media library (outline) + the
 * primary action, then Pages · Header menu · Footer menu · Mobile menu (Theme is not built).
 * Banners (B · 27b 327:62346) and Testimonials (B · 27d 329:63085) carry their own crumb, title
 * and subtitle and no tabs: the sidebar's Online store submenu is how you move between sections.
 */
export function StoreHeader({ section = 'pages', action, pageCount }: { section?: StoreSection; action?: ReactNode; pageCount?: number }) {
    const { t } = useI18n();

    if (section !== 'pages') {
        const copy = sections[section];
        return (
            <ContentHeader
                breadcrumb={[{ label: t('content.crumb.store') }, { label: t('content.crumb.online'), to: '/store' }, { label: t(copy.crumb) }]}
                title={t(copy.title)}
                subtitle={t(copy.subtitle)}
                actions={action}
            />
        );
    }

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
                <TabLink to="/store/pages" end label={t('content.tab.pages')} count={pageCount} />
                <TabLink to="/store/navigation/header" label={t('content.tab.header')} />
                <TabLink to="/store/navigation/footer" label={t('content.tab.footer')} />
                <TabLink to="/store/navigation/mobile" label={t('content.tab.mobile')} />
            </TabList>
        </>
    );
}
