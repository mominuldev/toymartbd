import { CalendarBlankIcon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import { StatusChip } from '@/components/ui/StatusChip';
import type { CmsPage } from '../pages/api';
import { formatDhakaDayMonth } from './dhaka';

/** Figma B · 27 page row chips: "Live · edited 08 Nov", "Scheduled 15 Jun", "Draft". */
export function PageStateChip({ page }: { page: Pick<CmsPage, 'state' | 'published_at' | 'updated_at'> }) {
    const { t, locale } = useI18n();

    switch (page.state) {
        case 'live':
            return (
                <StatusChip
                    tone="success"
                    label={page.updated_at ? t('content.state.liveEdited', { date: formatDhakaDayMonth(page.updated_at, locale) }) : t('content.state.live')}
                />
            );
        case 'scheduled':
            return <StatusChip tone="info" icon={CalendarBlankIcon} label={t('content.state.scheduled', { date: page.published_at ? formatDhakaDayMonth(page.published_at, locale) : '' })} />;
        case 'archived':
            return <StatusChip tone="neutral" label={t('content.state.archived')} />;
        default:
            return <StatusChip tone="neutral" label={t('content.state.draft')} />;
    }
}
