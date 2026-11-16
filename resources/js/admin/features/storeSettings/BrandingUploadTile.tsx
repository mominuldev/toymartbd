import { useEffect, useId, useRef, useState, type DragEvent, type ReactNode } from 'react';
import { ArrowsClockwiseIcon, CloudArrowUpIcon, ImagesIcon, TrashIcon, WarningCircleIcon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import { Button } from '@/components/ui/Button';
import { IconButton } from '@/components/ui/IconButton';
import { MediaPickerModal, type InsertFailure } from '@/features/media/picker';
import { useMediaFolders, type Media } from '@/features/media/api';
import { UploadQueueItem } from '@/features/media/parts/UploadQueue';
import { useMediaUploadQueue } from '@/features/media/parts/useMediaUploadQueue';

/** One chosen image as the form keeps it: the API's `media.*` shape, or a picked/uploaded Media mapped to it. */
export type BrandingImage = { id: number; filename: string; width: number | null; height: number | null; url: string | null };

/** The formats the note promises (Figma 392:69531): no SVG — every upload is re-encoded. */
const ACCEPT = 'image/png,image/jpeg,image/webp';

export function imageOf(media: Media): BrandingImage {
    return { id: media.id, filename: media.filename, width: media.width, height: media.height, url: media.urls.medium ?? media.urls.thumb };
}

type BrandingUploadTileProps = {
    title: string;
    /** The tile's help line ("For light backgrounds — header, invoices, emails…"). */
    hint: string;
    /** The empty dropzone's second line. */
    emptyHint: string;
    pickerTitle: string;
    /** Shown as the "Optional" pill in the title row (dark-background logo). */
    optional?: boolean;
    value: BrandingImage | null;
    onChange: (next: BrandingImage | null) => void;
    /** The preview well for a set image (logo on bg/subtle, icon at 64/40/24/16, share card). */
    renderPreview: (image: BrandingImage) => ReactNode;
    /** The picker's shape hints: ratio and minimum side. Display only; the server validates. */
    ratio?: readonly [number, number] | null;
    minSize?: number | null;
    disabled?: boolean;
    /** A 422 from the save, for this field. */
    error?: string;
};

/**
 * Figma UploadTile (392:69357 set, 392:69406 empty; mobile 396:69880): radius/sm,
 * border/default, 12 padding and gap. Title row (Admin/body-strong + ghost Remove, or the
 * Optional pill), a 116 preview well, mono "file · W × H" + caption, then Replace (upload) and
 * Library (media picker). Empty: a dashed dropzone that also browses, and "Choose from library".
 * An upload runs through the media library's own queue (10 MB pre-check, polling until the
 * renditions are ready) and is only offered to the form once it is ready.
 */
export function BrandingUploadTile({ title, hint, emptyHint, pickerTitle, optional = false, value, onChange, renderPreview, ratio = null, minSize = null, disabled = false, error }: BrandingUploadTileProps) {
    const { t } = useI18n();
    const fileInput = useRef<HTMLInputElement>(null);
    const titleId = useId();
    const [picking, setPicking] = useState(false);
    const [dragging, setDragging] = useState(false);
    const [uploadError, setUploadError] = useState<string | null>(null);
    const folders = useMediaFolders();
    const pagesFolder = folders.data?.find((f) => f.system_key === 'pages')?.id ?? null;
    const queue = useMediaUploadQueue({ folderId: pagesFolder, concurrency: 1 });
    const item = queue.items[queue.items.length - 1] ?? null;

    // Offer the upload once its renditions are ready; a failed rendition is a failure.
    useEffect(() => {
        if (item?.status !== 'done' || !item.result) return;
        const media = item.result;
        queue.reset();
        if (media.processing_status === 'ready') {
            setUploadError(null);
            onChange(imageOf(media));
        } else {
            setUploadError(t('storeBranding.image.failed'));
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [item?.status, item?.result]);

    function upload(files: FileList | null) {
        const file = files?.[0];
        if (!file || disabled) return;
        setUploadError(null);
        queue.reset();
        queue.add([file]);
    }

    function drop(event: DragEvent) {
        event.preventDefault();
        setDragging(false);
        upload(event.dataTransfer.files);
    }

    const insert = async (picked: Media[]): Promise<InsertFailure[]> => {
        const media = picked[0];
        if (!media) return [];
        if (media.processing_status !== 'ready') return [{ media, reason: t('storeBranding.image.notReady') }];
        setUploadError(null);
        onChange(imageOf(media));
        setPicking(false);
        return [];
    };

    const shownError = error ?? uploadError;
    const busy = item !== null && item.status !== 'done';

    return (
        <div role="group" aria-labelledby={titleId} className="flex min-w-0 flex-col gap-3 rounded-sm border border-line-default p-3">
            <div className="flex min-h-5 items-center gap-1.5">
                <p id={titleId} className="min-w-0 flex-1 t-admin-body-strong text-fg-primary">
                    {title}
                </p>
                {optional && !value && <span className="shrink-0 rounded-full bg-subtle px-1.5 py-px t-admin-caption-strong text-fg-secondary">{t('common.optional')}</span>}
                {value && !disabled && (
                    <IconButton size="sm" aria-label={t('storeBranding.image.remove', { name: title })} onClick={() => onChange(null)}>
                        <TrashIcon size={16} aria-hidden />
                    </IconButton>
                )}
            </div>

            {busy && item ? (
                <ul aria-label={t('storeBranding.image.uploading')} className="-mx-3 border-y border-line-default">
                    <UploadQueueItem item={item} onRetry={() => queue.retry(item.key)} onRemove={() => queue.reset()} />
                </ul>
            ) : value ? (
                <>
                    {renderPreview(value)}
                    <div className="flex min-w-0 flex-col gap-0.5 t-admin-caption">
                        <p className="truncate t-admin-mono-sm text-fg-primary">
                            {value.filename}
                            {value.width && value.height ? ` · ${value.width} × ${value.height}` : ''}
                        </p>
                        <p className="text-fg-secondary">{hint}</p>
                    </div>
                </>
            ) : (
                <button
                    type="button"
                    disabled={disabled}
                    onClick={() => fileInput.current?.click()}
                    onDragOver={(e) => {
                        e.preventDefault();
                        if (!disabled) setDragging(true);
                    }}
                    onDragLeave={() => setDragging(false)}
                    onDrop={drop}
                    className={`flex min-h-37.5 flex-1 flex-col items-center justify-center gap-1.5 rounded-xs border border-dashed px-3 text-center transition-colors duration-150 ease-standard disabled:cursor-not-allowed ${
                        dragging ? 'border-line-focus bg-brand-subtle' : 'border-line-strong bg-subtle hover:bg-brand-subtle'
                    }`}
                >
                    <span className="grid size-8 place-items-center rounded-xs-plus bg-surface text-fg-brand">
                        <CloudArrowUpIcon size={16} weight="fill" aria-hidden />
                    </span>
                    <span className="t-admin-body-strong text-fg-primary">{t('storeBranding.image.drop')}</span>
                    <span className="t-admin-caption text-fg-secondary">{emptyHint}</span>
                </button>
            )}

            {shownError && (
                <p role="alert" className="flex items-start gap-1 t-admin-caption text-fg-primary">
                    <WarningCircleIcon size={14} aria-hidden className="mt-px shrink-0 text-feedback-danger" />
                    {shownError}
                </p>
            )}

            {value && <div className="flex-1" />}

            {!disabled && (
                <div className="flex flex-wrap items-center gap-1.5">
                    {value ? (
                        <>
                            <Button variant="outline" size="sm" disabled={busy} onClick={() => fileInput.current?.click()} aria-label={t('storeBranding.image.replaceName', { name: title })}>
                                <ArrowsClockwiseIcon size={16} aria-hidden />
                                {t('storeBranding.image.replace')}
                            </Button>
                            <Button variant="ghost" size="sm" disabled={busy} onClick={() => setPicking(true)} aria-label={t('storeBranding.image.libraryName', { name: title })}>
                                <ImagesIcon size={16} weight="fill" aria-hidden />
                                {t('storeBranding.image.library')}
                            </Button>
                        </>
                    ) : (
                        <Button variant="outline" size="sm" disabled={busy} onClick={() => setPicking(true)} aria-label={t('storeBranding.image.chooseName', { name: title })}>
                            <ImagesIcon size={16} weight="fill" aria-hidden />
                            {t('storeBranding.image.choose')}
                        </Button>
                    )}
                </div>
            )}

            <input
                ref={fileInput}
                type="file"
                accept={ACCEPT}
                hidden
                tabIndex={-1}
                aria-label={t('storeBranding.image.fileInput', { name: title })}
                onChange={(e) => {
                    upload(e.target.files);
                    e.target.value = '';
                }}
            />

            {picking && (
                <MediaPickerModal
                    context={{ title: pickerTitle, subtitle: hint, multiple: false, max: 1, kinds: ['image'], defaultFolder: 'pages', ratio, minSize }}
                    onInsert={insert}
                    onClose={() => setPicking(false)}
                />
            )}
        </div>
    );
}
