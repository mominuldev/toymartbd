import { ArticleIcon, BrowserIcon, PackageIcon, ShareNetworkIcon } from '@phosphor-icons/react';
import type { MediaUsage } from '../api';

/** Figma MediaDetailPanel usage rows: package (product), browser (page), article (post). */
export function UsageIcon({ usage }: { usage: Pick<MediaUsage, 'type' | 'subject_type'> }) {
    const Icon = usage.type === 'seo_og_image' ? ShareNetworkIcon : usage.subject_type === 'product' ? PackageIcon : usage.subject_type === 'page' ? BrowserIcon : ArticleIcon;
    return <Icon size={16} aria-hidden className="shrink-0 text-fg-primary" />;
}
