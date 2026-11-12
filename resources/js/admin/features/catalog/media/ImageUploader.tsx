import { useEffect, useRef, useState, type DragEvent, type KeyboardEvent, type ReactNode } from 'react';
import {
    ArrowLeftIcon,
    ArrowRightIcon,
    CropIcon,
    DotsSixVerticalIcon,
    ImageIcon,
    StarIcon,
    TrashIcon,
    WarningCircleIcon,
    WarningIcon,
} from '@phosphor-icons/react';
import { Button } from '@/components/ui/Button';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { IconButton } from '@/components/ui/IconButton';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { useI18n } from '@/i18n/I18nProvider';
import { ApiError } from '@/lib/api';
import {
    ACCEPTED_TYPES,
    MAX_BYTES,
    MAX_IMAGES,
    uploadImage,
    useCropImage,
    useDeleteImage,
    useInvalidateImages,
    useProductImages,
    useReorderImages,
    useUpdateImage,
    type ProductImage,
} from './api';
import { CropDialog } from './CropDialog';

export interface ImageUploaderProps {
    productId: number;
    variants: Array<{ id: number; label: string }>;
    disabled?: boolean;
    /** Used for the auto-composed alt placeholder ("{product} — image {n}"). */
    productName?: string;
    /** Rendered beside the title — the product form puts "Choose from library" here. */
    headerAction?: ReactNode;
}

type Upload = { key: string; file: File; loaded: number; error: string | null };

const megabytes = (bytes: number) => `${(bytes / 1048576).toFixed(1)} MB`;

export function hasCompleteAlt(image: Pick<ProductImage, 'alt_text' | 'alt_text_bn'>): boolean {
    return Boolean(image.alt_text?.trim()) && Boolean(image.alt_text_bn?.trim());
}

/**
 * Figma ImageUploader 201:1035 (spec A8, P4-CAT-06). Owns its queries
 * (`['products', id, 'images']`) and invalidates `['products', id]` after every write.
 *
 * Reorder: drag a tile, or focus its handle and use Space / arrow keys / Space; below
 * `lg` the Figma 768 "Move left / Move right" buttons act on the selected tile.
 * Alt text saves on blur. Uploads go one request per file, with progress.
 */
export function ImageUploader({ productId, variants, disabled = false, productName, headerAction }: ImageUploaderProps) {
    const { t } = useI18n();
    const query = useProductImages(productId);
    const invalidate = useInvalidateImages(productId);
    const update = useUpdateImage(productId);
    const reorder = useReorderImages(productId);
    const remove = useDeleteImage(productId);
    const crop = useCropImage(productId);

    const images = [...(query.data ?? [])].sort((a, b) => a.position - b.position);
    const [uploads, setUploads] = useState<Upload[]>([]);
    const [dragOver, setDragOver] = useState(false);
    const [dragId, setDragId] = useState<number | null>(null);
    const [lifted, setLifted] = useState<number | null>(null);
    const [selected, setSelected] = useState<number | null>(null);
    const [announcement, setAnnouncement] = useState('');
    const [confirming, setConfirming] = useState<ProductImage | null>(null);
    const [cropping, setCropping] = useState<ProductImage | null>(null);
    const fileInput = useRef<HTMLInputElement>(null);
    const handles = useRef(new Map<number, HTMLButtonElement>());

    const pending = uploads.filter((u) => u.error === null).length;
    const room = MAX_IMAGES - images.length - pending;
    const missingAlt = images.filter((i) => !hasCompleteAlt(i)).length;

    // Keep keyboard focus on the moved tile's handle after the list re-renders.
    useEffect(() => {
        if (lifted !== null) handles.current.get(lifted)?.focus();
    }, [lifted, images.map((i) => i.id).join()]);

    function addFiles(files: File[]) {
        if (disabled) return;
        let free = room;
        const next: Upload[] = files.map((file) => {
            const typeOk = file.type === '' || (ACCEPTED_TYPES as readonly string[]).includes(file.type);
            const error = !typeOk
                ? t('images.wrongType')
                : file.size > MAX_BYTES
                  ? t('images.tooLarge', { size: megabytes(file.size) })
                  : free <= 0
                    ? t('images.tooMany', { max: MAX_IMAGES })
                    : null;
            if (error === null) free -= 1;
            return { key: crypto.randomUUID(), file, loaded: 0, error };
        });
        setUploads((current) => [...current, ...next]);

        for (const item of next.filter((u) => u.error === null)) {
            uploadImage(productId, item.file, (loaded) => setUploads((cur) => cur.map((u) => (u.key === item.key ? { ...u, loaded } : u))))
                .then(() => {
                    setUploads((cur) => cur.filter((u) => u.key !== item.key));
                    void invalidate();
                })
                .catch((error: unknown) => {
                    const message =
                        error instanceof ApiError ? (error.fieldError('file') ?? error.problem.detail ?? error.problem.title) : String(error);
                    setUploads((cur) => cur.map((u) => (u.key === item.key ? { ...u, error: message } : u)));
                });
        }
    }

    function move(id: number, by: number) {
        const ids = images.map((i) => i.id);
        const from = ids.indexOf(id);
        const to = Math.max(0, Math.min(ids.length - 1, from + by));
        if (from < 0 || from === to) return;
        ids.splice(to, 0, ...ids.splice(from, 1));
        reorder.mutate(ids);
        setAnnouncement(t('images.moved', { from: from + 1, to: to + 1, total: ids.length }));
    }

    function onHandleKey(event: KeyboardEvent<HTMLButtonElement>, image: ProductImage, index: number) {
        if (event.key === ' ' || event.key === 'Enter') {
            event.preventDefault();
            if (lifted === image.id) {
                setLifted(null);
                setAnnouncement(t('images.dropped', { to: index + 1 }));
            } else {
                setLifted(image.id);
                setAnnouncement(t('images.lifted', { n: index + 1, total: images.length }));
            }
        } else if (lifted === image.id && (event.key === 'ArrowLeft' || event.key === 'ArrowUp')) {
            event.preventDefault();
            move(image.id, -1);
        } else if (lifted === image.id && (event.key === 'ArrowRight' || event.key === 'ArrowDown')) {
            event.preventDefault();
            move(image.id, 1);
        } else if (event.key === 'Escape' && lifted !== null) {
            setLifted(null);
        }
    }

    function onDropTile(event: DragEvent, target: ProductImage) {
        if (dragId === null) return;
        event.preventDefault();
        event.stopPropagation();
        const ids = images.map((i) => i.id);
        move(dragId, ids.indexOf(target.id) - ids.indexOf(dragId));
        setDragId(null);
    }

    function saveAlt(image: ProductImage, key: 'alt_text' | 'alt_text_bn', value: string) {
        const next = value.trim() === '' ? null : value.trim();
        if (next === (image[key] ?? null)) return;
        update.mutate({ id: image.id, patch: { [key]: next } });
    }

    const isFileDrag = (event: DragEvent) => Array.from(event.dataTransfer.types).includes('Files');

    return (
        <section
            aria-label={t('images.region')}
            className="flex flex-col gap-4 rounded-md border border-line-default bg-surface p-5 shadow-e1"
            onDragOver={(event) => {
                if (!disabled && isFileDrag(event)) {
                    event.preventDefault();
                    setDragOver(true);
                }
            }}
            onDragLeave={(event) => {
                if (!event.currentTarget.contains(event.relatedTarget as Node | null)) setDragOver(false);
            }}
            onDrop={(event) => {
                if (!isFileDrag(event)) return;
                event.preventDefault();
                setDragOver(false);
                addFiles(Array.from(event.dataTransfer.files));
            }}
        >
            <div className="flex items-center gap-2">
                <h2 className="min-w-0 flex-1 t-admin-section text-fg-primary">{t('images.title', { count: images.length, max: MAX_IMAGES })}</h2>
                {headerAction}
            </div>

            <div className="@container">
                <ul className="grid grid-cols-2 gap-4 @md:grid-cols-3 @2xl:grid-cols-4">
                    {images.map((image, index) => {
                        const n = index + 1;
                        const complete = hasCompleteAlt(image);
                        const missingLang = !image.alt_text?.trim() ? t('images.lang.en') : t('images.lang.bn');
                        return (
                            <li
                                key={image.id}
                                className={`flex min-w-0 flex-col gap-2 ${dragId === image.id ? 'opacity-90' : ''}`}
                                draggable={!disabled}
                                onDragStart={(event) => {
                                    setDragId(image.id);
                                    event.dataTransfer.effectAllowed = 'move';
                                }}
                                onDragEnd={() => setDragId(null)}
                                onDragOver={(event) => {
                                    if (dragId !== null) event.preventDefault();
                                }}
                                onDrop={(event) => onDropTile(event, image)}
                                onFocusCapture={() => setSelected(image.id)}
                                onClick={() => setSelected(image.id)}
                                aria-current={selected === image.id ? 'true' : undefined}
                            >
                                <div
                                    className={`relative aspect-square overflow-hidden rounded-sm bg-subtle ${
                                        image.is_primary ? 'border-2 border-brand' : 'border border-line-default'
                                    } ${lifted === image.id ? 'shadow-e3' : ''}`}
                                >
                                    {image.processing_status === 'ready' && image.urls.thumb ? (
                                        <picture>
                                            {image.srcset.avif && <source type="image/avif" srcSet={image.srcset.avif} sizes="(min-width: 1024px) 11rem, 50vw" />}
                                            <img
                                                src={image.urls.thumb}
                                                srcSet={image.srcset.webp ?? undefined}
                                                sizes="(min-width: 1024px) 11rem, 50vw"
                                                width={image.width ?? undefined}
                                                height={image.height ?? undefined}
                                                alt={image.alt_text ?? ''}
                                                draggable={false}
                                                className="size-full object-cover"
                                            />
                                        </picture>
                                    ) : (
                                        <div className="grid size-full place-items-center p-2 text-center">
                                            {image.processing_status === 'failed' ? (
                                                <span className="flex flex-col items-center gap-1 t-admin-caption text-fg-primary">
                                                    <WarningCircleIcon size={20} aria-hidden className="text-feedback-danger" />
                                                    {t('images.failed')}
                                                </span>
                                            ) : (
                                                <span className="t-admin-caption text-fg-tertiary" role="status">
                                                    {image.processing_status === 'processing' ? t('images.processing') : ''}
                                                    {image.processing_status !== 'processing' && <ImageIcon size={28} aria-hidden className="text-fg-tertiary" />}
                                                </span>
                                            )}
                                        </div>
                                    )}

                                    {image.is_primary && (
                                        <span className="absolute start-1.5 top-1.5 rounded-xs bg-brand px-2 py-0.5 t-admin-caption-strong text-fg-on-brand">
                                            {t('images.primary')}
                                        </span>
                                    )}
                                    {!complete && (
                                        <span
                                            className="absolute end-2 top-2 rounded-full bg-surface p-1"
                                            title={t('images.altMissing', { lang: missingLang })}
                                        >
                                            <WarningIcon size={16} aria-hidden className="text-feedback-warning" />
                                            <span className="sr-only">{t('images.altMissing', { lang: missingLang })}</span>
                                        </span>
                                    )}

                                    <div className="absolute inset-x-0 bottom-0 flex h-9 justify-center gap-0.5 bg-surface p-1">
                                        <IconButton
                                            size="sm"
                                            aria-label={t('images.crop', { n })}
                                            disabled={disabled || image.processing_status !== 'ready'}
                                            onClick={() => setCropping(image)}
                                        >
                                            <CropIcon size={16} aria-hidden />
                                        </IconButton>
                                        <IconButton
                                            size="sm"
                                            aria-label={image.is_primary ? t('images.isPrimary', { n }) : t('images.makePrimary', { n })}
                                            aria-pressed={image.is_primary}
                                            disabled={disabled}
                                            onClick={() => !image.is_primary && update.mutate({ id: image.id, patch: { is_primary: true } })}
                                        >
                                            <StarIcon size={16} aria-hidden />
                                        </IconButton>
                                        <IconButton
                                            ref={(node) => {
                                                if (node) handles.current.set(image.id, node);
                                                else handles.current.delete(image.id);
                                            }}
                                            size="sm"
                                            aria-label={t('images.reorder', { n })}
                                            aria-pressed={lifted === image.id}
                                            disabled={disabled || images.length < 2}
                                            onKeyDown={(event) => onHandleKey(event, image, index)}
                                            onBlur={() => lifted === image.id && setLifted(null)}
                                            className="cursor-grab"
                                        >
                                            <DotsSixVerticalIcon size={16} aria-hidden />
                                        </IconButton>
                                        <IconButton size="sm" aria-label={t('images.delete', { n })} disabled={disabled} onClick={() => setConfirming(image)}>
                                            <TrashIcon size={16} aria-hidden />
                                        </IconButton>
                                    </div>
                                </div>

                                <Input
                                    key={`en-${image.id}-${image.alt_text ?? ''}`}
                                    size="sm"
                                    aria-label={t('images.altEn', { n })}
                                    aria-invalid={!image.alt_text?.trim() || undefined}
                                    defaultValue={image.alt_text ?? ''}
                                    placeholder={t('images.altPlaceholder', { product: productName ?? '', n }).replace(/^ — /, '')}
                                    maxLength={255}
                                    className="text-ellipsis"
                                    disabled={disabled}
                                    onBlur={(event) => saveAlt(image, 'alt_text', event.target.value)}
                                />
                                <Input
                                    key={`bn-${image.id}-${image.alt_text_bn ?? ''}`}
                                    size="sm"
                                    lang="bn"
                                    aria-label={t('images.altBn', { n })}
                                    aria-invalid={!image.alt_text_bn?.trim() || undefined}
                                    defaultValue={image.alt_text_bn ?? ''}
                                    placeholder={t('images.altBnMissing')}
                                    maxLength={255}
                                    className="text-ellipsis"
                                    disabled={disabled}
                                    onBlur={(event) => saveAlt(image, 'alt_text_bn', event.target.value)}
                                />
                                {variants.length > 0 && (
                                    <Select
                                        size="sm"
                                        aria-label={t('images.variant', { n })}
                                        value={image.variant_id ?? ''}
                                        disabled={disabled}
                                        onChange={(event) =>
                                            update.mutate({ id: image.id, patch: { variant_id: event.target.value === '' ? null : Number(event.target.value) } })
                                        }
                                    >
                                        <option value="">{t('images.variantAll')}</option>
                                        {variants.map((variant) => (
                                            <option key={variant.id} value={variant.id}>
                                                {variant.label}
                                            </option>
                                        ))}
                                    </Select>
                                )}
                            </li>
                        );
                    })}

                    {uploads.map((item) => {
                        const percent = Math.round((item.loaded / Math.max(item.file.size, 1)) * 100);
                        return (
                            <li key={item.key} className="flex min-w-0 flex-col gap-2">
                                <div
                                    className={`relative grid aspect-square place-items-center overflow-hidden rounded-sm bg-subtle p-2 text-center ${
                                        item.error ? 'border border-line-danger' : 'border border-line-default'
                                    }`}
                                >
                                    {item.error ? (
                                        <div className="flex flex-col items-center gap-1" role="alert">
                                            <WarningCircleIcon size={20} aria-hidden className="text-feedback-danger" />
                                            <p className="t-admin-caption text-fg-primary">{item.error}</p>
                                            <p className="truncate t-admin-caption text-fg-tertiary">{item.file.name}</p>
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                onClick={() => setUploads((cur) => cur.filter((u) => u.key !== item.key))}
                                            >
                                                {t('images.remove')}
                                            </Button>
                                        </div>
                                    ) : (
                                        <>
                                            <p className="t-admin-caption text-fg-primary" role="status">
                                                {t('images.uploading', { percent, size: megabytes(item.file.size) })}
                                            </p>
                                            <div
                                                role="progressbar"
                                                aria-valuemin={0}
                                                aria-valuemax={100}
                                                aria-valuenow={percent}
                                                aria-label={item.file.name}
                                                className="absolute inset-x-0 bottom-0 h-1 bg-line-default"
                                            >
                                                <div className="h-full bg-brand transition-[width]" style={{ width: `${percent}%` }} />
                                            </div>
                                        </>
                                    )}
                                </div>
                            </li>
                        );
                    })}

                    {!disabled && room > 0 && (
                        <li className={images.length + uploads.length === 0 ? 'col-span-full' : ''}>
                            <button
                                type="button"
                                onClick={() => fileInput.current?.click()}
                                className={`flex w-full flex-col items-center justify-center gap-1.5 rounded-sm border-[1.5px] px-2 py-3 transition-colors duration-150 ease-standard ${
                                    images.length + uploads.length === 0 ? 'h-45' : ''
                                } ${dragOver ? 'border-solid border-brand bg-brand-subtle' : 'border-dashed border-line-strong bg-subtle hover:bg-brand-subtle'}`}
                            >
                                <ImageIcon size={28} aria-hidden className="text-fg-secondary" />
                                <span className="t-admin-body-strong text-fg-primary">{dragOver ? t('images.dropActive') : t('images.add')}</span>
                                <span className="t-admin-caption text-fg-tertiary">{t('images.drop')}</span>
                            </button>
                            <input
                                ref={fileInput}
                                type="file"
                                multiple
                                accept={ACCEPTED_TYPES.join(',')}
                                className="sr-only"
                                tabIndex={-1}
                                aria-hidden
                                data-testid="image-file-input"
                                onChange={(event) => {
                                    addFiles(Array.from(event.target.files ?? []));
                                    event.target.value = '';
                                }}
                            />
                        </li>
                    )}
                </ul>
            </div>

            {images.length > 1 && !disabled && (
                <div className="flex flex-wrap items-center gap-2 lg:hidden">
                    <Button variant="outline" size="sm" disabled={selected === null || images[0]?.id === selected} onClick={() => selected !== null && move(selected, -1)}>
                        <ArrowLeftIcon size={16} aria-hidden />
                        {t('images.moveLeft')}
                    </Button>
                    <Button
                        variant="outline"
                        size="sm"
                        disabled={selected === null || images[images.length - 1]?.id === selected}
                        onClick={() => selected !== null && move(selected, 1)}
                    >
                        <ArrowRightIcon size={16} aria-hidden />
                        {t('images.moveRight')}
                    </Button>
                    <span className="t-admin-caption text-fg-tertiary">{t('images.touchHint')}</span>
                </div>
            )}

            {missingAlt > 0 && (
                <p className="flex items-start gap-1 t-admin-caption text-fg-primary" data-testid="alt-required">
                    <WarningIcon size={14} aria-hidden className="mt-px shrink-0 text-feedback-warning" />
                    {t('images.altRequired', { count: missingAlt })}
                </p>
            )}
            <p className="t-admin-caption text-fg-tertiary">{t('images.help')}</p>

            <p className="sr-only" aria-live="polite">
                {announcement}
            </p>

            <ConfirmDialog
                open={confirming !== null}
                title={t('images.deleteTitle', { n: confirming ? images.findIndex((i) => i.id === confirming.id) + 1 : 0 })}
                body={t('images.deleteBody')}
                confirmLabel={t('images.deleteConfirm')}
                confirmText={t('images.deleteWord')}
                typePrompt={t('images.deletePrompt')}
                pending={remove.isPending}
                onClose={() => setConfirming(null)}
                onConfirm={() => {
                    if (confirming) remove.mutate(confirming.id, { onSuccess: () => setConfirming(null) });
                }}
            />

            {cropping && (
                <CropDialog
                    image={cropping}
                    pending={crop.isPending}
                    onClose={() => setCropping(null)}
                    onApply={(rect) => crop.mutate({ id: cropping.id, rect }, { onSuccess: () => setCropping(null) })}
                />
            )}
        </section>
    );
}
