import { useEffect, useState } from 'react';
import { ArrowClockwiseIcon, CaretDownIcon, CheckCircleIcon, ClockCounterClockwiseIcon, FilePdfIcon, WarningCircleIcon, XIcon } from '@phosphor-icons/react';
import { Button } from '@/components/ui/Button';
import { IconButton } from '@/components/ui/IconButton';
import { useI18n } from '@/i18n/I18nProvider';
import { formatBytes } from './format';
import type { UploadItem } from './useUploadQueue';

/** A local preview for an image file; revoked on unmount. PDFs get a glyph instead. */
function useObjectUrl(file: File): string | null {
    const [url, setUrl] = useState<string | null>(null);
    useEffect(() => {
        if (!file.type.startsWith('image/') || typeof URL.createObjectURL !== 'function') return;
        const next = URL.createObjectURL(file);
        setUrl(next);
        return () => URL.revokeObjectURL(next);
    }, [file]);
    return url;
}

type UploadQueueItemProps<T> = {
    item: UploadItem<T>;
    onRetry: () => void;
    onRemove: () => void;
};

/**
 * Figma UploadQueueItem 292:2169 — 12×16 padding, 12 gap: a 40px thumb (radius/xs-plus),
 * filename (Admin/body-strong) and a caption, a 4px progress bar (bg/subtle track, bg/brand
 * fill) while uploading or processing. Uploading → cancel; Processing → clock glyph; Done →
 * success check; Failed → a danger-subtle thumb with warning-circle, the server's reason in
 * text/primary (feedback colours never carry 12px text), Retry and Remove.
 */
export function UploadQueueItem<T>({ item, onRetry, onRemove }: UploadQueueItemProps<T>) {
    const { t } = useI18n();
    const preview = useObjectUrl(item.file);
    const failed = item.status === 'failed';
    const percent = item.total > 0 ? Math.min(100, Math.round((item.loaded / item.total) * 100)) : 0;

    let caption: string;
    if (item.status === 'failed') caption = item.error ?? '';
    else if (item.status === 'done') caption = t('media.queue.ready');
    else if (item.status === 'processing') caption = t('media.queue.processing');
    else if (item.status === 'queued') caption = t('media.queue.waiting');
    else caption = t('media.queue.progress', { percent, loaded: formatBytes(item.loaded), total: formatBytes(item.total) });

    const showBar = item.status === 'uploading' || item.status === 'processing' || item.status === 'queued';
    const barWidth = item.status === 'processing' ? 100 : item.status === 'queued' ? 0 : percent;

    return (
        <li className="flex items-center gap-3 px-4 py-3" aria-label={item.file.name}>
            <span className={`relative grid size-10 shrink-0 place-items-center overflow-hidden rounded-xs-plus ${failed ? 'bg-danger-subtle' : 'bg-image'}`}>
                {failed ? (
                    <WarningCircleIcon size={20} aria-hidden className="text-feedback-danger" />
                ) : preview ? (
                    <img src={preview} alt="" className="size-full object-cover" />
                ) : (
                    <FilePdfIcon size={20} weight="fill" aria-hidden className="text-fg-secondary" />
                )}
            </span>
            <div className="flex min-w-0 flex-1 flex-col gap-1">
                <p className="truncate t-admin-body-strong text-fg-primary">{item.file.name}</p>
                <p className={`t-admin-caption ${failed ? 'text-fg-primary' : 'text-fg-secondary'}`} role={failed ? 'alert' : undefined}>
                    {caption}
                </p>
                {showBar && (
                    <div
                        role="progressbar"
                        aria-label={item.file.name}
                        aria-valuemin={0}
                        aria-valuemax={100}
                        aria-valuenow={barWidth}
                        className="h-1 w-full overflow-hidden rounded-full bg-subtle"
                    >
                        <div className="h-1 rounded-full bg-brand transition-[width] duration-150 ease-standard" style={{ width: `${barWidth}%` }} />
                    </div>
                )}
            </div>
            {(item.status === 'uploading' || item.status === 'queued') && (
                <IconButton size="sm" aria-label={t('media.queue.cancel', { name: item.file.name })} onClick={onRemove}>
                    <XIcon size={16} aria-hidden />
                </IconButton>
            )}
            {item.status === 'processing' && <ClockCounterClockwiseIcon size={16} aria-hidden className="shrink-0 text-fg-secondary" />}
            {item.status === 'done' && <CheckCircleIcon size={20} aria-label={t('media.queue.doneLabel')} className="shrink-0 text-feedback-success" />}
            {failed && (
                <div className="flex shrink-0 items-center gap-1">
                    <IconButton size="sm" aria-label={t('media.queue.retry', { name: item.file.name })} onClick={onRetry}>
                        <ArrowClockwiseIcon size={16} aria-hidden />
                    </IconButton>
                    <Button variant="ghost" size="sm" onClick={onRemove}>
                        {t('media.queue.remove')}
                    </Button>
                </div>
            )}
        </li>
    );
}

type UploadQueueProps<T> = {
    items: UploadItem<T>[];
    counts: { total: number; done: number; processing: number; uploading: number; failed: number };
    /** "Products" — the folder the files land in. */
    folderName: string;
    onRetry: (key: string) => void;
    onRemove: (key: string) => void;
    /** Dismiss the panel (only offered once nothing is in flight). */
    onClose?: () => void;
    /** `floating` = the 400-wide bottom-right panel; `inline` = inside the picker's Upload tab. */
    variant?: 'floating' | 'inline';
};

/**
 * Figma UploadQueue 292:2170 — a 400-wide floating panel at the bottom-right, radius/md, e4:
 * "Uploading 7 files to Products" with the per-state counts, collapsible, the rows, and a
 * footer explaining the content check, EXIF strip and AVIF/WebP serving.
 */
export function UploadQueue<T>({ items, counts, folderName, onRetry, onRemove, onClose, variant = 'floating' }: UploadQueueProps<T>) {
    const { t } = useI18n();
    const [collapsed, setCollapsed] = useState(false);
    const inFlight = counts.uploading + counts.processing > 0;
    const parts = [
        counts.done > 0 && t('media.queue.countDone', { count: counts.done }),
        counts.processing > 0 && t('media.queue.countProcessing', { count: counts.processing }),
        counts.uploading > 0 && t('media.queue.countUploading', { count: counts.uploading }),
        counts.failed > 0 && t('media.queue.countFailed', { count: counts.failed }),
    ].filter(Boolean);

    const floating = variant === 'floating';
    return (
        <section
            aria-label={t('media.queue.label')}
            className={
                floating
                    ? 'fixed right-4 bottom-4 z-40 flex max-h-[calc(100dvh-2rem)] w-[min(100%-2rem,25rem)] flex-col overflow-hidden rounded-md border border-line-default bg-surface shadow-e4'
                    : 'flex flex-col overflow-hidden rounded-md border border-line-default bg-surface'
            }
        >
            <header className="flex items-center gap-2 border-b border-line-default px-4 py-3">
                <div className="min-w-0 flex-1">
                    <h2 className="t-admin-body-strong text-fg-primary">{t('media.queue.title', { count: counts.total, folder: folderName })}</h2>
                    <p className="t-admin-caption text-fg-secondary tabular" aria-live="polite">
                        {parts.join(' · ')}
                    </p>
                </div>
                {floating && (
                    <IconButton size="sm" aria-expanded={!collapsed} aria-label={t(collapsed ? 'media.queue.expand' : 'media.queue.collapse')} onClick={() => setCollapsed((c) => !c)}>
                        <CaretDownIcon size={16} aria-hidden className={collapsed ? 'rotate-180' : ''} />
                    </IconButton>
                )}
                {floating && onClose && !inFlight && (
                    <IconButton size="sm" aria-label={t('common.close')} onClick={onClose}>
                        <XIcon size={16} aria-hidden />
                    </IconButton>
                )}
            </header>
            {!collapsed && (
                <>
                    <ul className="min-h-0 flex-1 overflow-y-auto">
                        {items.map((item) => (
                            <UploadQueueItem key={item.key} item={item} onRetry={() => onRetry(item.key)} onRemove={() => onRemove(item.key)} />
                        ))}
                    </ul>
                    <p className="border-t border-line-default bg-subtle px-4 py-3 t-admin-caption text-fg-secondary">{t('media.queue.footer')}</p>
                </>
            )}
        </section>
    );
}
