import type { ComponentType, ReactNode } from 'react';
import {
    BrowserIcon,
    CircleDashedIcon,
    FilePdfIcon,
    ImageIcon,
    ImagesIcon,
    NewspaperIcon,
    PackageIcon,
    PlusIcon,
    FolderSimpleIcon,
    type IconProps,
} from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import type { MediaCounts, MediaFilters, MediaFolder, MediaStorage, SystemFolderKey } from './api';
import { MediaFilterItem } from './parts/MediaFilterItem';
import { folderLabel } from './parts/display';
import { formatBytes, formatCount } from './parts/format';

const folderIcons: Record<SystemFolderKey, ComponentType<IconProps>> = {
    products: PackageIcon,
    category_banners: ImageIcon,
    blog: NewspaperIcon,
    pages: BrowserIcon,
};

export function folderIcon(folder: Pick<MediaFolder, 'system_key'>): ComponentType<IconProps> {
    return folder.system_key ? folderIcons[folder.system_key] : FolderSimpleIcon;
}

/** A rail card: radius/md, border/default, bg/surface, 12 padding; an overline heading. */
function RailCard({ title, children, footer }: { title: string; children: ReactNode; footer?: ReactNode }) {
    return (
        <section aria-label={title} className="flex flex-col gap-1 rounded-md border border-line-default bg-surface p-2 shadow-e1">
            <h2 className="px-1 pt-1.5 pb-1 t-admin-overline text-fg-secondary uppercase">{title}</h2>
            <div className="flex flex-col gap-1">{children}</div>
            {footer}
        </section>
    );
}

type MediaRailProps = {
    filters: MediaFilters;
    folders: MediaFolder[];
    counts: MediaCounts | undefined;
    storage: MediaStorage | undefined;
    canManage: boolean;
    onFolder: (folder: number | 'unused' | null) => void;
    onKind: (kind: 'image' | 'document' | null) => void;
    onMissingAlt: (lang: 'bn' | 'en' | null) => void;
    onNewFolder: () => void;
};

/**
 * The folder rail (admin spec §B9): Folders (All media, the system and custom folders,
 * Unused, + New folder), Type, Needs attention (the alt-text gaps in Warning), and the
 * storage meter. Every number is the server's (`GET /media/counts`, `/media/storage`).
 */
export function MediaRail({ filters, folders, counts, storage, canManage, onFolder, onKind, onMissingAlt, onNewFolder }: MediaRailProps) {
    const { t } = useI18n();
    const count = (n: number | undefined) => (n === undefined ? undefined : formatCount(n));
    const folderCount = (id: number) => count(counts?.folders.find((f) => f.id === id)?.count);
    const isAll = filters.folder === null && !filters.unused;

    return (
        <nav aria-label={t('media.rail.label')} className="flex flex-col gap-4">
            <RailCard title={t('media.rail.folders')}>
                <MediaFilterItem label={t('media.rail.all')} icon={ImagesIcon} count={count(counts?.all)} active={isAll} onClick={() => onFolder(null)} />
                {folders.map((folder) => (
                    <MediaFilterItem
                        key={folder.id}
                        label={folderLabel(folder, t)}
                        icon={folderIcon(folder)}
                        count={folderCount(folder.id)}
                        active={filters.folder === folder.id}
                        onClick={() => onFolder(folder.id)}
                    />
                ))}
                <MediaFilterItem label={t('media.rail.unused')} icon={CircleDashedIcon} count={count(counts?.unused)} active={filters.unused} onClick={() => onFolder('unused')} />
                {canManage && (
                    <button type="button" onClick={onNewFolder} className="flex h-9 items-center gap-2 rounded-sm px-3 t-admin-body-strong text-fg-primary hover:bg-subtle">
                        <PlusIcon size={16} aria-hidden />
                        {t('media.newFolder')}
                    </button>
                )}
            </RailCard>

            <RailCard title={t('media.rail.type')}>
                <MediaFilterItem label={t('media.type.image')} icon={ImageIcon} count={count(counts?.kinds.image)} active={filters.kind === 'image'} onClick={() => onKind(filters.kind === 'image' ? null : 'image')} />
                <MediaFilterItem
                    label={t('media.type.document')}
                    icon={FilePdfIcon}
                    count={count(counts?.kinds.document)}
                    active={filters.kind === 'document'}
                    onClick={() => onKind(filters.kind === 'document' ? null : 'document')}
                />
            </RailCard>

            <RailCard title={t('media.rail.attention')} footer={<p className="px-1 pt-1 pb-1.5 t-admin-caption text-fg-secondary">{t('media.rail.attentionHint')}</p>}>
                <MediaFilterItem
                    label={t('media.rail.missingBn')}
                    warning
                    count={count(counts?.missing_alt.bn)}
                    active={filters.missingAlt === 'bn'}
                    onClick={() => onMissingAlt(filters.missingAlt === 'bn' ? null : 'bn')}
                />
                <MediaFilterItem
                    label={t('media.rail.missingEn')}
                    warning
                    count={count(counts?.missing_alt.en)}
                    active={filters.missingAlt === 'en'}
                    onClick={() => onMissingAlt(filters.missingAlt === 'en' ? null : 'en')}
                />
            </RailCard>

            {storage && <StorageMeter storage={storage} />}
        </nav>
    );
}

/** The storage card: "3.2 GB of 10 GB" + a 6px meter, or just the usage when no quota is set. */
export function StorageMeter({ storage }: { storage: MediaStorage }) {
    const { t } = useI18n();
    const quota = storage.quota_bytes;
    const percent = quota ? Math.min(100, Math.round((storage.used_bytes / quota) * 100)) : null;

    return (
        <section aria-label={t('media.storage')} className="flex flex-col gap-2 rounded-md border border-line-default bg-surface p-4 shadow-e1">
            <div className="flex items-baseline justify-between gap-2">
                <h2 className="t-admin-body-strong text-fg-primary">{t('media.storage')}</h2>
                {percent !== null && <span className="t-admin-caption text-fg-secondary tabular">{t('media.storagePercent', { percent })}</span>}
            </div>
            <p className="t-admin-num-lg text-fg-primary tabular">
                {quota ? t('media.storageUsed', { used: formatBytes(storage.used_bytes), quota: formatBytes(quota) }) : t('media.storageUsedNoQuota', { used: formatBytes(storage.used_bytes) })}
            </p>
            {percent !== null && (
                <div role="meter" aria-label={t('media.storage')} aria-valuemin={0} aria-valuemax={100} aria-valuenow={percent} className="h-1.5 w-full overflow-hidden rounded-full bg-subtle">
                    <div className="h-1.5 rounded-full bg-brand" style={{ width: `${percent}%` }} />
                </div>
            )}
            <p className="t-admin-caption text-fg-secondary">
                {t('media.storageSplit', { originals: formatBytes(storage.originals_bytes), renditions: formatBytes(storage.renditions_bytes) })}
            </p>
        </section>
    );
}
