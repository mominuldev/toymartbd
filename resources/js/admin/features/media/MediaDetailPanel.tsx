import { useEffect, useRef, useState, type ReactNode } from 'react';
import { Link } from 'react-router';
import { ArrowClockwiseIcon, ArrowSquareOutIcon, CopyIcon, DownloadSimpleIcon, FilePdfIcon, LockSimpleIcon, TrashIcon, WarningCircleIcon, XIcon } from '@phosphor-icons/react';
import { Button } from '@/components/ui/Button';
import { Field } from '@/components/ui/Field';
import { IconButton, iconButtonClass } from '@/components/ui/IconButton';
import { Input } from '@/components/ui/Input';
import { QueryError } from '@/components/ui/QueryError';
import { Select } from '@/components/ui/Select';
import { useToast } from '@/components/ui/Toast';
import { useI18n } from '@/i18n/I18nProvider';
import { ApiError } from '@/lib/api';
import { inUseUsages, problemMessage, useDeleteMedia, useMedia, useReplaceMedia, useUpdateMedia, type MediaDetail, type MediaFolder, type MediaPatch, type MediaUsage } from './api';
import { MediaDeleteDialog, adminRoute } from './MediaDeleteDialog';
import { aspectLabel, folderLabel, formatName, usageContext, usageTitle } from './parts/display';
import { formatBytes, formatDhakaDateTime } from './parts/format';
import { UsageIcon } from './parts/UsageIcon';

type MediaDetailPanelProps = {
    mediaId: number;
    folders: MediaFolder[];
    canManage: boolean;
    canDelete: boolean;
    onClose: () => void;
    onDeleted: () => void;
    /** `inline` = the 344 column at 1440; `drawer` = the overlay at 1024 and below. */
    variant?: 'inline' | 'drawer';
};

/**
 * Figma MediaDetailPanel 291:2353 — "Asset details": preview, filename + stable URL, Copy
 * URL / Replace file / Download, the facts, the renditions box, alt EN + বাংলা (both
 * required; empty = Error state with the publish consequence), caption, folder, the
 * used-in list, and Delete — disabled with the reason while the file is in use (A25 rule 2).
 */
export function MediaDetailPanel({ mediaId, folders, canManage, canDelete, onClose, onDeleted, variant = 'inline' }: MediaDetailPanelProps) {
    const { t } = useI18n();
    const media = useMedia(mediaId);
    const shell =
        variant === 'inline'
            ? 'flex flex-col overflow-hidden rounded-md border border-line-default bg-surface shadow-e1'
            : 'flex h-full flex-col overflow-y-auto bg-surface shadow-e4';

    return (
        <aside aria-label={t('media.detail.title')} className={shell}>
            <header className="flex items-center gap-2 px-4 pt-4 pb-3">
                <h2 className="min-w-0 flex-1 t-admin-section text-fg-primary">{t('media.detail.title')}</h2>
                <IconButton size="sm" aria-label={t('media.detail.close')} onClick={onClose}>
                    <XIcon size={16} aria-hidden />
                </IconButton>
            </header>
            {media.isError ? (
                <div className="px-4 pb-4">
                    <QueryError error={media.error} onRetry={() => void media.refetch()} />
                </div>
            ) : !media.data ? (
                <p className="px-4 pb-4 t-admin-body text-fg-tertiary" aria-busy="true">
                    {t('common.loading')}
                </p>
            ) : (
                <DetailBody key={media.data.id} media={media.data} folders={folders} canManage={canManage} canDelete={canDelete} onDeleted={onDeleted} />
            )}
        </aside>
    );
}

function DetailBody({ media, folders, canManage, canDelete, onDeleted }: { media: MediaDetail; folders: MediaFolder[]; canManage: boolean; canDelete: boolean; onDeleted: () => void }) {
    const { t, locale } = useI18n();
    const toast = useToast();
    const update = useUpdateMedia();
    const replace = useReplaceMedia();
    const remove = useDeleteMedia();
    const fileInput = useRef<HTMLInputElement>(null);
    const [replaceError, setReplaceError] = useState<string | null>(null);
    const [replacePercent, setReplacePercent] = useState<number | null>(null);
    const [dialog, setDialog] = useState<null | { state: 'blocked'; usages: MediaUsage[] } | { state: 'confirm' }>(null);
    const isImage = media.kind === 'image';
    const used = media.used_in.length;
    const processing = media.processing_status === 'processing';
    const usedHeading = used === 0 ? t('media.detail.unusedHeading') : used === 1 ? t('media.detail.usedHeadingOne') : t('media.detail.usedHeading', { count: used });

    const copy = () => {
        if (!media.public_url) return;
        void navigator.clipboard?.writeText(media.public_url).then(() => toast.notify(t('media.detail.copied')));
    };

    const onReplaceFile = (file: File | undefined) => {
        if (!file) return;
        setReplaceError(null);
        setReplacePercent(0);
        replace.mutate(
            { id: media.id, file, onProgress: (loaded, total) => setReplacePercent(Math.round((loaded / total) * 100)) },
            {
                onSuccess: () => toast.notify(t('media.detail.replaced')),
                onError: (error) => setReplaceError(problemMessage(error) ?? t('media.queue.network')),
                onSettled: () => setReplacePercent(null),
            },
        );
    };

    // mutateAsync, not mutate(…, { onSuccess }): the delete invalidates the list, the
    // deleted row disappears and this panel can unmount before the per-call callbacks
    // run — TanStack skips those for an unmounted observer, which dropped the toast.
    const confirmDelete = () => {
        const filename = media.filename;
        remove.mutateAsync(media.id).then(
            () => {
                setDialog(null);
                toast.notify(t('media.delete.done', { name: filename }));
                onDeleted();
            },
            (error: unknown) => {
                const usages = inUseUsages(error);
                if (usages) setDialog({ state: 'blocked', usages });
                else {
                    setDialog(null);
                    toast.notify(problemMessage(error) ?? t('media.queue.network'), 'danger');
                }
            },
        );
    };

    // The renditions box: the largest AVIF, then WebP from largest down (Figma 291:2054).
    const avif = media.renditions.filter((r) => r.format === 'avif').sort((a, b) => b.width - a.width).slice(0, 1);
    const webp = media.renditions.filter((r) => r.format === 'webp').sort((a, b) => b.width - a.width);
    const shownRenditions = [...avif, ...webp];
    const ratio = aspectLabel(media.width, media.height);
    const folder = folders.find((f) => f.id === media.folder_id);

    return (
        <div className="flex flex-col gap-4 px-4 pb-4">
            <div className={`relative aspect-square w-full overflow-hidden rounded-sm ${isImage ? 'bg-image' : 'grid place-items-center bg-subtle'}`}>
                {isImage ? (
                    media.urls.large || media.urls.medium ? (
                        <img src={media.urls.large ?? media.urls.medium ?? ''} srcSet={media.srcset?.webp} sizes="344px" alt={media.alt_text ?? ''} className="size-full object-cover" />
                    ) : (
                        <span className="grid size-full place-items-center t-admin-caption text-fg-tertiary">{t('media.detail.processing')}</span>
                    )
                ) : (
                    <FilePdfIcon size={64} weight="fill" aria-hidden className="text-fg-primary" />
                )}
            </div>

            <div className="flex min-w-0 flex-col gap-1">
                <p className="t-admin-body-strong break-all text-fg-primary">{media.filename}</p>
                {media.public_url && (
                    <p className="truncate t-admin-mono-sm text-fg-secondary" title={media.public_url}>
                        {media.public_url.replace(/^https?:\/\//, '')}
                    </p>
                )}
            </div>

            <div className="flex flex-col gap-2">
                <div className="flex flex-wrap items-start gap-2">
                    {media.public_url && (
                        <Button variant="outline" size="sm" onClick={copy}>
                            <CopyIcon size={16} aria-hidden />
                            {t('media.detail.copy')}
                        </Button>
                    )}
                    {canManage && (
                        <>
                            <Button variant="outline" size="sm" disabled={replace.isPending} onClick={() => fileInput.current?.click()}>
                                <ArrowClockwiseIcon size={16} aria-hidden />
                                {replace.isPending ? t('media.detail.replacing') : t('media.detail.replace')}
                            </Button>
                            <input
                                ref={fileInput}
                                type="file"
                                hidden
                                aria-hidden
                                tabIndex={-1}
                                accept={isImage ? 'image/jpeg,image/png,image/webp,image/avif' : 'application/pdf'}
                                data-testid="media-replace-input"
                                onChange={(e) => {
                                    onReplaceFile(e.target.files?.[0]);
                                    e.target.value = '';
                                }}
                            />
                        </>
                    )}
                    <a href={media.download_path} download className={iconButtonClass('outline', 'sm')} aria-label={t('media.detail.download')} title={t('media.detail.download')}>
                        <DownloadSimpleIcon size={16} aria-hidden />
                    </a>
                </div>
                {replacePercent !== null && (
                    <div role="progressbar" aria-label={t('media.detail.replacing')} aria-valuenow={replacePercent} aria-valuemin={0} aria-valuemax={100} className="h-1 w-full overflow-hidden rounded-full bg-subtle">
                        <div className="h-1 rounded-full bg-brand" style={{ width: `${replacePercent}%` }} />
                    </div>
                )}
                {replaceError && (
                    <p role="alert" className="flex items-start gap-1 t-admin-caption text-fg-primary">
                        <WarningCircleIcon size={14} aria-hidden className="mt-px shrink-0 text-feedback-danger" />
                        {replaceError}
                    </p>
                )}
            </div>
            {canManage && <p className="t-admin-caption text-fg-secondary">{t('media.detail.replaceHint')}</p>}

            <dl className="flex flex-col gap-2">
                <Fact label={t('media.detail.type')}>
                    {isImage ? t('media.detail.typeImage', { format: formatName(media.mime), original: formatName(media.original_mime) }) : t('media.detail.typeDocument')}
                </Fact>
                {isImage && media.width && media.height && (
                    <Fact label={t('media.detail.dimensions')}>{t('media.detail.dimensionsValue', { width: media.width, height: media.height, ratio: ratio ?? '' })}</Fact>
                )}
                <Fact label={t('media.detail.size')}>
                    {isImage && media.size_bytes > 0
                        ? t('media.detail.sizeValue', { size: formatBytes(media.size_bytes), original: formatBytes(media.original_size_bytes) })
                        : formatBytes(media.original_size_bytes)}
                </Fact>
                <Fact label={t('media.detail.uploaded')}>
                    {media.uploaded_by
                        ? t('media.detail.uploadedValue', { name: media.uploaded_by.name, date: formatDhakaDateTime(media.created_at, locale) })
                        : formatDhakaDateTime(media.created_at, locale)}
                </Fact>
                <Fact label={t('media.detail.folder')}>{folder ? folderLabel(folder, t) : t('media.folder.none')}</Fact>
            </dl>

            {isImage && (
                <section className="flex flex-col gap-2 rounded-sm bg-subtle p-3" aria-label={t('media.detail.renditions')}>
                    <h3 className="t-admin-overline text-fg-tertiary uppercase">{t('media.detail.renditions')}</h3>
                    {processing || shownRenditions.length === 0 ? (
                        <p className="t-admin-caption text-fg-secondary">{t('media.detail.renditionsPending')}</p>
                    ) : (
                        <ul className="flex flex-col gap-2">
                            {shownRenditions.map((r) => (
                                <li key={`${r.format}-${r.width}`} className="flex gap-2 t-admin-caption tabular">
                                    {/* i18n-ignore: file-format names are the same in both languages */}
                                    <span className="w-12 shrink-0 t-admin-caption-strong text-fg-primary">{r.format === 'avif' ? 'AVIF' : 'WebP'}</span>
                                    <span className="flex-1 text-fg-secondary">{t('media.detail.renditionWidth', { width: r.width })}</span>
                                    <span className="text-fg-primary">{formatBytes(r.bytes)}</span>
                                </li>
                            ))}
                        </ul>
                    )}
                    <p className="t-admin-caption text-fg-secondary">{t('media.detail.renditionsNote', { format: formatName(media.original_mime) })}</p>
                </section>
            )}

            {isImage && (
                <section className="flex flex-col gap-3" aria-label={t('media.detail.altHeading')}>
                    <h3 className="t-admin-overline text-fg-tertiary uppercase">{t('media.detail.altHeading')}</h3>
                    <TextSetting
                        label={t('media.detail.altEn')}
                        lang="en"
                        value={media.alt_text}
                        required
                        disabled={!canManage}
                        missing={t('media.detail.altMissing')}
                        onSave={(value) => update.mutateAsync({ id: media.id, patch: { alt_text: value } })}
                        field="alt_text"
                    />
                    <TextSetting
                        label={t('media.detail.altBn')}
                        lang="bn"
                        value={media.alt_text_bn}
                        required
                        disabled={!canManage}
                        missing={t('media.detail.altMissing')}
                        onSave={(value) => update.mutateAsync({ id: media.id, patch: { alt_text_bn: value } })}
                        field="alt_text_bn"
                    />
                </section>
            )}
            <TextSetting
                label={t('media.detail.caption')}
                optional
                value={media.caption}
                placeholder={t('media.detail.captionPlaceholder')}
                disabled={!canManage}
                onSave={(value) => update.mutateAsync({ id: media.id, patch: { caption: value } })}
                field="caption"
                maxLength={500}
            />

            <Field label={t('media.detail.folder')}>
                {({ id }) => (
                    <Select
                        id={id}
                        value={media.folder_id ?? ''}
                        disabled={!canManage || update.isPending}
                        onChange={(e) => update.mutate({ id: media.id, patch: { folder_id: e.target.value ? Number(e.target.value) : null } satisfies MediaPatch })}
                    >
                        <option value="">{t('media.folder.none')}</option>
                        {folders.map((f) => (
                            <option key={f.id} value={f.id}>
                                {folderLabel(f, t)}
                            </option>
                        ))}
                    </Select>
                )}
            </Field>

            <section className="flex flex-col gap-2" aria-label={usedHeading}>
                <h3 className="t-admin-overline text-fg-tertiary uppercase">{usedHeading}</h3>
                {media.used_in.map((usage) => (
                    <Link
                        key={`${usage.type}-${usage.subject_id}-${usage.position ?? ''}`}
                        to={adminRoute(usage.admin_path)}
                        className="flex items-center gap-3 rounded-sm border border-line-default px-3 py-2 hover:bg-subtle"
                        aria-label={t('media.detail.open', { title: usageTitle(usage, locale) })}
                    >
                        <UsageIcon usage={usage} />
                        <span className="flex min-w-0 flex-1 flex-col">
                            <span className="truncate t-admin-body-strong text-fg-primary">{usageTitle(usage, locale)}</span>
                            <span className="t-admin-caption text-fg-secondary">{usageContext(usage, t)}</span>
                        </span>
                        <ArrowSquareOutIcon size={16} aria-hidden className="shrink-0 text-fg-link" />
                    </Link>
                ))}
            </section>

            {canDelete && (
                <div className="flex flex-col gap-2 border-t border-line-default pt-4">
                    <Button variant="danger" className="w-full" disabled={used > 0 || remove.isPending} onClick={() => setDialog({ state: 'confirm' })}>
                        <TrashIcon size={16} aria-hidden />
                        {t('media.detail.delete')}
                    </Button>
                    {used > 0 && (
                        <p className="flex items-start gap-2 t-admin-caption text-fg-secondary">
                            <LockSimpleIcon size={16} aria-hidden className="shrink-0 text-fg-secondary" />
                            {used === 1 ? t('media.detail.deleteBlockedOne') : t('media.detail.deleteBlocked', { count: used })}
                        </p>
                    )}
                </div>
            )}

            {dialog?.state === 'blocked' && (
                <MediaDeleteDialog
                    open
                    state="blocked"
                    filename={media.filename}
                    usages={dialog.usages}
                    onClose={() => setDialog(null)}
                    onReplace={canManage ? () => fileInput.current?.click() : undefined}
                />
            )}
            {dialog?.state === 'confirm' && (
                <MediaDeleteDialog open state="confirm" filenames={[media.filename]} pending={remove.isPending} onConfirm={confirmDelete} onClose={() => setDialog(null)} />
            )}
        </div>
    );
}

function Fact({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="flex gap-3">
            <dt className="w-22 shrink-0 t-admin-caption text-fg-secondary">{label}</dt>
            <dd className="min-w-0 flex-1 t-admin-body text-fg-primary tabular">{children}</dd>
        </div>
    );
}

/** Persists one field; rejects with the server's ApiError. */
type Pending = PromiseLike<unknown>;
type Saver = (value: string | null) => Pending;

type TextSettingProps = {
    label: string;
    value: string | null;
    field: string;
    onSave: Saver;
    lang?: 'en' | 'bn';
    required?: boolean;
    optional?: boolean;
    disabled?: boolean;
    placeholder?: string;
    /** The Error-state line for an empty required alt (the publish consequence). */
    missing?: string;
    maxLength?: number;
};

/**
 * A field that saves itself on blur (or Enter) with PATCH, sending only its own key. The
 * server's 422 message for that key shows under the field, verbatim. An empty required alt
 * renders the AdminField Error state with the publish consequence (A25 rule 1).
 */
function TextSetting({ label, value, field, onSave, lang, required, optional, disabled, placeholder, missing, maxLength = 255 }: TextSettingProps) {
    const [draft, setDraft] = useState(value ?? '');
    const [error, setError] = useState<string | null>(null);
    const [saving, setSaving] = useState(false);

    // A replace or another tab's edit refreshes the record; keep the field in step when not editing.
    useEffect(() => setDraft(value ?? ''), [value]);

    const commit = async () => {
        const next = draft.trim() === '' ? null : draft.trim();
        if (next === (value ?? null)) return;
        setSaving(true);
        setError(null);
        try {
            await onSave(next);
        } catch (e) {
            setError(e instanceof ApiError ? (e.fieldError(field) ?? problemMessage(e, field)) : null);
        } finally {
            setSaving(false);
        }
    };

    const empty = required && draft.trim() === '' && !saving;
    const shownError = error ?? (empty ? (missing ?? null) : null);

    return (
        <Field label={label} required={required} optional={optional} error={shownError ?? undefined}>
            {({ id, describedBy, invalid }) => (
                <Input
                    id={id}
                    lang={lang}
                    aria-describedby={describedBy}
                    aria-invalid={invalid || undefined}
                    aria-required={required || undefined}
                    className={lang === 'bn' ? 'lang-bn' : undefined}
                    value={draft}
                    maxLength={maxLength}
                    placeholder={placeholder}
                    disabled={disabled}
                    onChange={(e) => setDraft(e.target.value)}
                    onBlur={() => void commit()}
                    onKeyDown={(e) => {
                        if (e.key === 'Enter') {
                            e.preventDefault();
                            void commit();
                        }
                    }}
                />
            )}
        </Field>
    );
}
