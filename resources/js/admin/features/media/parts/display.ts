import type { MessageKey } from '@/i18n/messages';
import type { AltStatus, Media, MediaFolder, MediaUsage, SystemFolderKey } from '../api';
import type { AltState } from './AltChip';
import { formatBytes, formatDimensions } from './format';

type T = (key: MessageKey, vars?: Record<string, string | number>) => string;

const altFromStatus: Record<AltStatus, AltState> = {
    complete: 'complete',
    missing_bn: 'missingBn',
    missing_en: 'missingEn',
    missing: 'missingBoth',
    not_applicable: 'notNeeded',
};

/** The server computes `alt_status`; the chip only maps it. */
export function altStateOf(media: Pick<Media, 'alt_status'>): AltState {
    return altFromStatus[media.alt_status];
}

const systemFolderKeys: Record<SystemFolderKey, MessageKey> = {
    products: 'media.folder.system.products',
    category_banners: 'media.folder.system.category_banners',
    blog: 'media.folder.system.blog',
    pages: 'media.folder.system.pages',
};

/** System folders are translated by `system_key`; custom folders show the name as typed. */
export function folderLabel(folder: Pick<MediaFolder, 'name' | 'system_key'>, t: T): string {
    return folder.system_key ? t(systemFolderKeys[folder.system_key]) : folder.name;
}

/** "WebP", "JPG", "PNG", "AVIF", "PDF" from a MIME type. */
export function formatName(mime: string): string {
    const sub = mime.split('/')[1] ?? mime;
    if (sub === 'jpeg') return 'JPG';
    if (sub === 'webp') return 'WebP';
    return sub.toUpperCase();
}

/** The tile's meta line: "1600×1600 · 312 KB", or "PDF · 1.8 MB". */
export function tileMeta(media: Pick<Media, 'kind' | 'width' | 'height' | 'size_bytes' | 'original_size_bytes'>): string {
    if (media.kind !== 'image') return `PDF · ${formatBytes(media.size_bytes || media.original_size_bytes)}`;
    const dims = formatDimensions(media.width, media.height);
    const size = media.size_bytes > 0 ? formatBytes(media.size_bytes) : null;
    return [dims, size].filter(Boolean).join(' · ');
}

/** "Product · image 1 (primary)" — built from the structured usage, never server English. */
export function usageContext(usage: MediaUsage, t: T): string {
    if (usage.type === 'product_image' && usage.position !== null) {
        return t(usage.is_primary ? 'media.usage.productPrimary' : 'media.usage.product', { position: usage.position });
    }
    if (usage.type === 'seo_og_image') return t('media.usage.seoOg');
    return t('media.usage.other', { type: usage.subject_type });
}

export function usageTitle(usage: MediaUsage, locale: string): string {
    return locale === 'bn' ? (usage.title_bn ?? usage.title) : usage.title;
}

/** "1:1", "4:3", or "1.91:1" — the ratio the detail panel and the picker show. */
export function aspectLabel(width: number | null, height: number | null): string | null {
    if (!width || !height) return null;
    const gcd = (a: number, b: number): number => (b === 0 ? a : gcd(b, a % b));
    const d = gcd(width, height);
    const w = width / d;
    const h = height / d;
    if (w <= 32 && h <= 32) return `${w}:${h}`;
    return `${(width / height).toFixed(2).replace(/\.?0+$/, '')}:1`;
}
