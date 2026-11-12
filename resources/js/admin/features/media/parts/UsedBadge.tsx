import { LinkSimpleIcon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';

/**
 * The "Used in N" / "Unused" badge at the top-right of a MediaTile thumbnail (Figma
 * 289:2121 `usedIn`): bg/surface, radius/xs, 8×2 padding, a 12px link glyph and
 * Admin/caption-strong text/primary.
 */
export function UsedBadge({ count, className = '' }: { count: number; className?: string }) {
    const { t } = useI18n();
    return (
        <span className={`inline-flex items-center gap-1 rounded-xs bg-surface px-2 py-0.5 t-admin-caption-strong whitespace-nowrap text-fg-primary tabular ${className}`}>
            <LinkSimpleIcon size={12} aria-hidden className="shrink-0" />
            {count > 0 ? t('media.usedIn', { count }) : t('media.unused')}
        </span>
    );
}
