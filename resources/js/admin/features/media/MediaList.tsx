import type { ReactNode } from 'react';
import {
    CheckCircleIcon,
    DotsThreeIcon,
    DownloadSimpleIcon,
    FilePdfIcon,
    FolderSimpleIcon,
    TranslateIcon,
    TrashIcon,
    WarningCircleIcon,
    XIcon,
} from '@phosphor-icons/react';
import { Button } from '@/components/ui/Button';
import { DataTableFooter, dataTable } from '@/components/ui/DataTable';
import { IconButton } from '@/components/ui/IconButton';
import { useI18n } from '@/i18n/I18nProvider';
import type { BulkDeleteResult, Media, MediaFolder } from './api';
import { AltChip } from './parts/AltChip';
import { altStateOf, folderLabel, formatName } from './parts/display';
import { formatBytes, formatCount, formatDhakaShort } from './parts/format';

type MediaListProps = {
    rows: Media[];
    folders: MediaFolder[];
    selected: ReadonlySet<number>;
    selectable: boolean;
    total: number;
    hasMore: boolean;
    loadingMore: boolean;
    onToggle: (id: number) => void;
    onTogglePage: () => void;
    onOpen: (id: number) => void;
    onLoadMore: () => void;
};

/**
 * Figma 303:53979 — the list view as a DataTable 192:532: a checkbox, the file (40px thumb,
 * filename, "WebP · Products"), the alt chip, dimensions and size, Used in (a link-coloured
 * count, or Unused), uploaded (Dhaka time + who), and a row menu that opens the details.
 */
export function MediaList({ rows, folders, selected, selectable, total, hasMore, loadingMore, onToggle, onTogglePage, onOpen, onLoadMore }: MediaListProps) {
    const { t, locale } = useI18n();
    const allOnPage = rows.length > 0 && rows.every((r) => selected.has(r.id));
    const folderName = (id: number | null) => {
        const folder = folders.find((f) => f.id === id);
        return folder ? folderLabel(folder, t) : t('media.folder.none');
    };

    return (
        <div className={dataTable.card}>
            <div className="overflow-x-auto">
                <table className={`${dataTable.table} min-w-[52rem] table-fixed`}>
                    <thead className={dataTable.head}>
                        <tr className="border-b border-line-strong">
                            <th scope="col" className={`${dataTable.th} w-12`}>
                                {selectable && <input type="checkbox" checked={allOnPage} onChange={onTogglePage} aria-label={t('media.col.selectPage')} className="size-4 accent-brand" />}
                            </th>
                            <Th>{t('media.col.file')}</Th>
                            <Th className="w-36">{t('media.col.alt')}</Th>
                            <Th className="w-37">{t('media.col.size')}</Th>
                            <Th className="w-24">{t('media.col.usedIn')}</Th>
                            <Th className="w-37">{t('media.col.uploaded')}</Th>
                            <th scope="col" className={`${dataTable.th} w-12`}>
                                <span className="sr-only">{t('common.actions')}</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        {rows.map((row) => {
                            const isSelected = selected.has(row.id);
                            return (
                                <tr key={row.id} className={`${dataTable.row} ${isSelected ? 'bg-brand-subtle hover:bg-brand-subtle' : ''}`} aria-selected={selectable ? isSelected : undefined}>
                                    <td className={`${dataTable.td} w-12`}>
                                        {selectable && (
                                            <input type="checkbox" checked={isSelected} onChange={() => onToggle(row.id)} aria-label={t('media.col.select', { name: row.filename })} className="size-4 accent-brand" />
                                        )}
                                    </td>
                                    <td className={dataTable.td}>
                                        <div className="flex min-w-0 items-center gap-3">
                                            <span className={`grid size-10 shrink-0 place-items-center overflow-hidden rounded-xs-plus ${row.kind === 'image' ? 'bg-image' : 'bg-subtle'}`}>
                                                {row.kind === 'image' ? (
                                                    row.urls.thumb && <img src={row.urls.thumb} alt="" loading="lazy" className="size-full object-cover" />
                                                ) : (
                                                    <FilePdfIcon size={20} weight="fill" aria-hidden className="text-fg-secondary" />
                                                )}
                                            </span>
                                            <span className="flex min-w-0 flex-col">
                                                <button type="button" onClick={() => onOpen(row.id)} className="truncate text-start t-admin-body-strong text-fg-primary hover:underline" title={row.filename}>
                                                    {row.filename}
                                                </button>
                                                <span className="truncate t-admin-caption text-fg-secondary">
                                                    {t('media.rowMeta', { format: formatName(row.kind === 'image' ? row.original_mime : row.mime), folder: folderName(row.folder_id) })}
                                                </span>
                                            </span>
                                        </div>
                                    </td>
                                    <td className={dataTable.td}>
                                        <AltChip state={altStateOf(row)} form="row" />
                                    </td>
                                    <td className={`${dataTable.td} t-admin-caption-strong tabular whitespace-nowrap`}>
                                        {row.kind === 'image' && row.width && row.height
                                            ? `${row.width} × ${row.height} · ${formatBytes(row.size_bytes || row.original_size_bytes)}`
                                            : formatBytes(row.original_size_bytes)}
                                    </td>
                                    <td className={dataTable.td}>
                                        {row.used_in_count > 0 ? (
                                            <button type="button" onClick={() => onOpen(row.id)} className="t-admin-body whitespace-nowrap text-fg-link hover:underline tabular">
                                                {t('media.usedIn', { count: row.used_in_count })}
                                            </button>
                                        ) : (
                                            <span className="t-admin-body text-fg-secondary">{t('media.unused')}</span>
                                        )}
                                    </td>
                                    <td className={dataTable.td}>
                                        <span className="flex flex-col">
                                            <span className="t-admin-body text-fg-primary tabular whitespace-nowrap">{formatDhakaShort(row.created_at, locale)}</span>
                                            {row.uploaded_by && <span className="t-admin-caption text-fg-secondary">{row.uploaded_by.name}</span>}
                                        </span>
                                    </td>
                                    <td className={`${dataTable.td} w-12`}>
                                        <IconButton size="sm" aria-label={t('media.detail.open', { title: row.filename })} onClick={() => onOpen(row.id)}>
                                            <DotsThreeIcon size={16} aria-hidden />
                                        </IconButton>
                                    </td>
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
            </div>
            <DataTableFooter summary={t('media.showingList', { shown: formatCount(rows.length), total: formatCount(total) })}>
                {hasMore && (
                    <Button variant="outline" size="sm" onClick={onLoadMore} disabled={loadingMore}>
                        {loadingMore ? t('common.loading') : t('common.loadMore')}
                    </Button>
                )}
            </DataTableFooter>
        </div>
    );
}

function Th({ children, className = '' }: { children: ReactNode; className?: string }) {
    return (
        <th scope="col" className={`${dataTable.th} ${className}`}>
            {children}
        </th>
    );
}

type MediaBulkBarProps = {
    count: number;
    unused: number;
    used: number;
    canManage: boolean;
    canDelete: boolean;
    zipping: boolean;
    deleting: boolean;
    result: (BulkDeleteResult & { names: Map<number, string> }) | null;
    onMove: () => void;
    onZip: () => void;
    onAddAlt: (() => void) | null;
    onDelete: () => void;
    onClear: () => void;
};

/**
 * Figma BulkActionBar 193:740 in its `docked-toolbar` form (303:53979): "3 files selected",
 * the context ("2 unused · 1 in use — Delete skips files that are in use"), Move to folder,
 * Download .zip, Add alt text, Delete, and close. A bulk delete reports two numbers —
 * deleted and skipped — and names each file that was not deleted, never just "done".
 */
export function MediaBulkBar({ count, unused, used, canManage, canDelete, zipping, deleting, result, onMove, onZip, onAddAlt, onDelete, onClear }: MediaBulkBarProps) {
    const { t } = useI18n();
    const notDeleted = result?.results.filter((r) => r.status !== 'deleted') ?? [];

    return (
        <div role="region" aria-label={t('media.bulk.label')} className="flex flex-col gap-3 rounded-lg border border-line-strong bg-surface-raised px-4 py-3 shadow-e2">
            <div className="flex flex-wrap items-center gap-x-6 gap-y-3">
                <div className="flex flex-col gap-0.5">
                    <p className="t-admin-body-strong text-fg-primary tabular" aria-live="polite">
                        {t('media.bulk.selected', { count: formatCount(count) })}
                    </p>
                    <button type="button" onClick={onClear} className="self-start t-admin-caption-strong text-fg-link hover:underline">
                        {t('media.bulk.clear')}
                    </button>
                </div>
                {count > 0 && <p className="t-admin-caption text-fg-secondary tabular">{t('media.bulk.context', { unused, used })}</p>}
                <div className="ms-auto flex flex-wrap items-center gap-2">
                    {canManage && (
                        <Button variant="outline" onClick={onMove} disabled={count === 0}>
                            <FolderSimpleIcon size={16} aria-hidden />
                            {t('media.bulk.move')}
                        </Button>
                    )}
                    {canDelete && (
                        <Button variant="outline" onClick={onZip} disabled={count === 0 || zipping}>
                            <DownloadSimpleIcon size={16} aria-hidden />
                            {zipping ? t('media.bulk.zipping') : t('media.bulk.zip')}
                        </Button>
                    )}
                    {canManage && onAddAlt && (
                        <Button onClick={onAddAlt}>
                            <TranslateIcon size={16} aria-hidden />
                            {t('media.bulk.addAlt')}
                        </Button>
                    )}
                    {canDelete && (
                        <Button variant="outline" onClick={onDelete} disabled={count === 0 || deleting}>
                            <TrashIcon size={16} aria-hidden />
                            {t('media.bulk.delete')}
                        </Button>
                    )}
                    <span aria-hidden className="mx-1 h-8 w-px bg-line-default" />
                    <IconButton aria-label={t('media.bulk.clear')} onClick={onClear}>
                        <XIcon size={18} aria-hidden />
                    </IconButton>
                </div>
            </div>
            {result && (
                <div className="flex flex-col gap-1.5 border-t border-line-default pt-3" aria-live="polite">
                    <p className="flex flex-wrap items-center gap-x-4 gap-y-1 t-admin-body text-fg-primary">
                        <span className="inline-flex items-center gap-1.5">
                            <CheckCircleIcon size={16} aria-hidden className="text-feedback-success" />
                            {t('media.bulk.deleted', { count: result.deleted })}
                        </span>
                        <span className="inline-flex items-center gap-1.5">
                            <WarningCircleIcon size={16} aria-hidden className="text-feedback-danger" />
                            {t('media.bulk.skipped', { count: notDeleted.length })}
                        </span>
                    </p>
                    {notDeleted.length > 0 && (
                        <ul className="flex flex-col gap-1" aria-label={t('media.bulk.failedList')}>
                            {notDeleted.map((r) => (
                                <li key={r.id} className="t-admin-caption text-fg-secondary">
                                    <span className="t-admin-caption-strong text-fg-primary">{result.names.get(r.id) ?? `#${r.id}`}</span>
                                    {' · '}
                                    {r.status === 'in_use' ? t('media.usedIn', { count: r.used_in_count }) : t('media.bulk.notFound')}
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            )}
        </div>
    );
}
