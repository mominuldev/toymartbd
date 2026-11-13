import type { ReactNode } from 'react';
import { useI18n } from '@/i18n/I18nProvider';
import { ContentHeader } from '@/components/shell/ContentHeader';
import { StatusChip, type StatusTone } from '@/components/ui/StatusChip';
import { TabLink, TabList } from '@/components/ui/Tabs';
import type { PostStatus } from './api';

/**
 * The blog section header: "Store / Online store / Blog" crumb (Figma B · 28 241:46016), title,
 * subtitle and the primary action, then Posts · Categories · Tags · Authors tabs. Only the editor
 * has a frame; the list and taxonomy screens reuse its header and the Pages & navigation language.
 */
export function BlogHeader({ action, postCount }: { action?: ReactNode; postCount?: number }) {
    const { t } = useI18n();
    return (
        <>
            <ContentHeader
                breadcrumb={[{ label: t('content.crumb.store') }, { label: t('content.crumb.online'), to: '/store' }, { label: t('blog.crumb') }]}
                title={t('blog.title')}
                subtitle={t('blog.subtitle')}
                actions={action}
            />
            <TabList label={t('blog.tabs')}>
                <TabLink to="/store/blog" end label={t('blog.tab.posts')} count={postCount} />
                <TabLink to="/store/blog/categories" end label={t('blog.tab.categories')} />
                <TabLink to="/store/blog/tags" end label={t('blog.tab.tags')} />
                <TabLink to="/store/blog/authors" end label={t('blog.tab.authors')} />
            </TabList>
        </>
    );
}

const tones: Record<PostStatus, StatusTone> = { draft: 'neutral', scheduled: 'info', published: 'success' };

/** Draft / Scheduled / Published, icon + word (never colour alone). */
export function PostStatusChip({ status }: { status: PostStatus }) {
    const { t } = useI18n();
    return <StatusChip label={t(`blog.status.${status}`)} tone={tones[status]} />;
}

export const cardClass = 'flex min-w-0 flex-col rounded-md border border-line-default bg-surface p-5 shadow-e1';
