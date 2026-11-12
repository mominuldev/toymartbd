import { useEffect, useId, useMemo, useRef, useState, type DragEvent, type KeyboardEvent } from 'react';
import { createPortal } from 'react-dom';
import {
    BrowserIcon,
    CircleDashedIcon,
    CloudArrowUpIcon,
    FolderSimpleIcon,
    ImageIcon,
    ImagesIcon,
    InfoIcon,
    LinkBreakIcon,
    NewspaperIcon,
    PackageIcon,
    UploadSimpleIcon,
    WarningIcon,
    XIcon,
    type Icon,
} from '@phosphor-icons/react';
import { Button } from '@/components/ui/Button';
import { IconButton } from '@/components/ui/IconButton';
import { SearchField } from '@/components/ui/SearchField';
import { Select } from '@/components/ui/Select';
import { StatusChip } from '@/components/ui/StatusChip';
import { tabClass } from '@/components/ui/Tabs';
import { useI18n } from '@/i18n/I18nProvider';
import {
    defaultFilters,
    sorts,
    useMediaCounts,
    useMediaFolders,
    useMediaList,
    type Media,
    type MediaFilters,
    type MediaKind,
    type MediaSort,
    type SystemFolderKey,
} from '../api';
import { MediaFilterItem } from '../parts/MediaFilterItem';
import { MediaTile } from '../parts/MediaTile';
import { UploadQueueItem } from '../parts/UploadQueue';
import { useMediaUploadQueue } from '../parts/useMediaUploadQueue';
import { altStateOf, aspectLabel, folderLabel } from '../parts/display';
import { formatBytes, formatCount, formatDimensions } from '../parts/format';
import { fitFlags, lacksAnyAlt, lacksBanglaAlt, togglePick } from './flags';

/** What the caller is choosing for; it sets the copy, the rules shown and the cap. */
export type MediaPickerContext = {
    /** "Choose product images". */
    title: string;
    /** "Brick Box 500 pcs · gallery has 1 of 10 images". */
    subtitle?: string;
    /** The ratio/size hint bar ("Product images: square 1:1, at least 1200 × 1200 …"). */
    hint?: string;
    /** The Upload tab dropzone's second line. */
    uploadHint?: string;
    /** Expected aspect ratio; a tile that differs shows "Not 1:1 — will crop". */
    ratio?: readonly [number, number] | null;
    /** Minimum px per side; a smaller image shows "Below 1200 px". */
    minSize?: number | null;
    /** Numbered multi-select (1 = primary) or single-select. */
    multiple: boolean;
    /** Most files that can be picked (product gallery: the remaining slots of 10). */
    max: number;
    /** Kinds the caller accepts; the type filter offers only these. */
    kinds: readonly MediaKind[];
    /** The folder the Library opens on and the Upload tab files into. */
    defaultFolder?: SystemFolderKey | null;
    /** Show the বাংলা-alt warning bar (the product stays unpublished until it is added). */
    warnMissingAlt?: boolean;
};

export type InsertFailure = { media: Media; reason: string };
/** Inserts the picks in order and resolves with the ones that failed (empty = all inserted). */
export type InsertHandler = (picked: Media[]) => InsertResult;
type InsertResult = Promise<InsertFailure[]>;

type MediaPickerModalProps = {
    context: MediaPickerContext;
    /** Insert the picks, in order. Resolve with the ones that failed (empty = all inserted). */
    onInsert: InsertHandler;
    onClose: () => void;
    /** "Add alt text" in the warning bar: insert, then take the user to the alt fields. Hidden when absent. */
    onAddAlt?: InsertHandler;
};

type Tab = 'library' | 'upload';
type View = { kind: 'all' } | { kind: 'folder'; id: number } | { kind: 'unused' } | { kind: 'missingBn' };

const IMAGE_ACCEPT = 'image/jpeg,image/png,image/webp,image/avif';

/**
 * Figma MediaPickerModal 294:2661 (Theme B over Product edit: 303:55922) — 1000 wide,
 * radius/lg, e4. Header (title + calling context, close), Library / Upload tabs, the
 * ratio hint bar on bg/subtle, then:
 *
 * - Library: a 240 folder rail (MediaFilterItem) beside search + type + sort and a 4-up
 *   MediaTile grid with cursor Load more. Multi-select numbers the tiles in pick order
 *   (1 = primary); single-select marks one tile Selected. Fit flags are display only.
 * - Upload: a dashed dropzone and the inline queue; accepted files land selected, failed
 *   files are never inserted.
 *
 * Then the বাংলা-alt warning bar (insert allowed) and the footer with Insert N. A native
 * <dialog>.showModal() gives the focus trap, Esc and the inert background; it is portalled
 * to <body> so it never nests inside the caller's <form>.
 */
export function MediaPickerModal({ context, onInsert, onClose, onAddAlt }: MediaPickerModalProps) {
    const { t } = useI18n();
    const dialog = useRef<HTMLDialogElement>(null);
    const titleId = useId();
    const subtitleId = useId();
    const tabIds = { library: useId(), upload: useId() };
    const panelId = useId();

    const [tab, setTab] = useState<Tab>('library');
    const [picked, setPicked] = useState<Media[]>([]);
    const [inserting, setInserting] = useState(false);
    const [failures, setFailures] = useState<InsertFailure[]>([]);
    const [announcement, setAnnouncement] = useState('');

    const folders = useMediaFolders();
    const defaultFolderId = folders.data?.find((f) => f.system_key === context.defaultFolder)?.id ?? null;

    useEffect(() => {
        const node = dialog.current;
        if (node && !node.open) {
            if (typeof node.showModal === 'function') node.showModal();
            else node.setAttribute('open', '');
        }
    }, []);

    function close() {
        if (!inserting) onClose();
    }

    function toggle(media: Media) {
        const next = togglePick(picked, media, context.multiple, context.max);
        if (next === null) {
            setAnnouncement(t('picker.maxReached', { max: context.max }));
            return;
        }
        setAnnouncement('');
        setPicked(next);
    }

    const queue = useMediaUploadQueue({
        folderId: defaultFolderId,
        onUploaded: (media) => {
            // Accepted files land selected (if the caller takes their kind and there is room).
            if (!context.kinds.includes(media.kind)) return;
            setPicked((current) => {
                if (current.some((p) => p.id === media.id)) return current;
                if (!context.multiple) return [media];
                return current.length < context.max ? [...current, media] : current;
            });
        },
    });
    // Keep picks fresh as the queue polls processing files (alt status, URLs).
    const uploaded = useMemo(() => new Map(queue.items.flatMap((i) => (i.result ? [[i.result.id, i.result] as const] : []))), [queue.items]);
    const current = picked.map((p) => uploaded.get(p.id) ?? p);
    const uploadedIds = new Set(uploaded.keys());

    async function insert(handler: InsertHandler) {
        if (current.length === 0 || inserting) return;
        setInserting(true);
        setFailures([]);
        try {
            const failed = await handler(current);
            if (failed.length === 0) {
                onClose();
                return;
            }
            // Keep only what did not go in, so a retry never inserts a file twice.
            setFailures(failed);
            setPicked(failed.map((f) => f.media));
        } finally {
            setInserting(false);
        }
    }

    const missingBn = context.warnMissingAlt ? current.filter(lacksBanglaAlt).length : 0;
    const newMissingAlt = context.warnMissingAlt ? current.filter((m) => uploadedIds.has(m.id) && lacksAnyAlt(m)).length : 0;
    const showAltBar = tab === 'library' ? missingBn > 0 : newMissingAlt > 0;
    const uploading = queue.counts.uploading > 0;

    const insertLabel = inserting
        ? t('picker.inserting')
        : current.length === 0
          ? t('picker.insert')
          : current.length === 1
            ? t('picker.insertOne')
            : t('picker.insertMany', { count: current.length });

    let footer: string;
    if (tab === 'upload') {
        const ready = current.filter((m) => uploadedIds.has(m.id)).length;
        footer = queue.counts.failed > 0 ? t('picker.footer.upload', { ready, failed: queue.counts.failed }) : t('picker.footer.uploadNoFailed', { ready });
    } else if (context.multiple) {
        footer = current.length >= context.max ? t('picker.footer.full', { count: current.length, max: context.max }) : t('picker.footer.ordered', { count: current.length });
    } else {
        footer = t('picker.footer.single', { count: current.length });
    }

    function onTabKey(event: KeyboardEvent<HTMLButtonElement>) {
        if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight' && event.key !== 'Home' && event.key !== 'End') return;
        event.preventDefault();
        const next: Tab = event.key === 'Home' ? 'library' : event.key === 'End' ? 'upload' : tab === 'library' ? 'upload' : 'library';
        setTab(next);
        document.getElementById(tabIds[next])?.focus();
    }

    return createPortal(
        <dialog
            ref={dialog}
            aria-labelledby={titleId}
            aria-describedby={context.subtitle ? subtitleId : undefined}
            onCancel={(event) => {
                event.preventDefault();
                close();
            }}
            className="m-auto hidden h-[min(100dvh-2rem,55rem)] open:flex w-[min(100%-2rem,62.5rem)] flex-col overflow-hidden rounded-lg bg-surface p-0 text-fg-primary shadow-e4 backdrop:bg-overlay"
        >
            <header className="flex shrink-0 items-center gap-3 px-6 pt-5 pb-3">
                <div className="flex min-w-0 flex-1 flex-col gap-1">
                    <h2 id={titleId} className="t-admin-section text-fg-primary">
                        {context.title}
                    </h2>
                    {context.subtitle && (
                        <p id={subtitleId} className="t-admin-caption text-fg-secondary tabular">
                            {context.subtitle}
                        </p>
                    )}
                </div>
                <IconButton aria-label={t('picker.close')} onClick={close} disabled={inserting}>
                    <XIcon size={18} aria-hidden />
                </IconButton>
            </header>

            <div role="tablist" aria-label={t('picker.tabs')} className="flex shrink-0 gap-0.5 border-b border-line-default px-6">
                {(['library', 'upload'] as const).map((key) => (
                    <button
                        key={key}
                        id={tabIds[key]}
                        type="button"
                        role="tab"
                        aria-selected={tab === key}
                        aria-controls={panelId}
                        tabIndex={tab === key ? 0 : -1}
                        onClick={() => setTab(key)}
                        onKeyDown={onTabKey}
                        className={tabClass(tab === key)}
                    >
                        {t(key === 'library' ? 'picker.tab.library' : 'picker.tab.upload')}
                    </button>
                ))}
            </div>

            {context.hint && (
                <p className="flex shrink-0 items-center gap-2 bg-subtle px-6 py-3 t-admin-caption text-fg-primary">
                    <InfoIcon size={16} aria-hidden className="shrink-0 text-fg-primary" />
                    <span className="min-w-0 flex-1">{context.hint}</span>
                </p>
            )}

            <div id={panelId} role="tabpanel" aria-labelledby={tabIds[tab]} className="min-h-0 flex-1 overflow-y-auto px-6 py-5">
                {tab === 'library' ? (
                    <LibraryTab context={context} defaultFolderId={defaultFolderId} foldersReady={folders.isSuccess} picked={current} onToggle={toggle} />
                ) : (
                    <UploadTab context={context} queue={queue} />
                )}
            </div>

            {failures.length > 0 && (
                <ul role="alert" className="flex shrink-0 flex-col gap-1 bg-danger-subtle px-6 py-3">
                    {failures.map((f) => (
                        <li key={f.media.id} className="flex items-start gap-2 t-admin-caption text-fg-primary">
                            <LinkBreakIcon size={16} aria-hidden className="shrink-0 text-feedback-danger" />
                            {t('picker.product.attachFailed', { name: f.media.filename, reason: f.reason })}
                        </li>
                    ))}
                </ul>
            )}

            {showAltBar && (
                <div className="flex shrink-0 items-center gap-2 bg-warning-subtle px-6 py-3" data-testid="picker-alt-warning">
                    <WarningIcon size={16} aria-hidden className="shrink-0 text-feedback-warning" />
                    <p className="min-w-0 flex-1 t-admin-caption text-fg-primary">
                        {tab === 'library'
                            ? missingBn === 1
                                ? t('picker.altWarningOne')
                                : t('picker.altWarningMany', { count: missingBn })
                            : t('picker.uploadAltWarning', { count: newMissingAlt })}
                    </p>
                    {onAddAlt && (
                        <Button variant="ghost" size="sm" disabled={inserting || uploading} onClick={() => void insert(onAddAlt)}>
                            {t('picker.addAlt')}
                        </Button>
                    )}
                </div>
            )}

            <footer className="flex shrink-0 flex-wrap items-center gap-3 border-t border-line-default px-6 py-4">
                <p className="min-w-0 flex-1 t-admin-caption text-fg-secondary tabular" aria-live="polite">
                    {footer}
                </p>
                <Button variant="ghost" onClick={close} disabled={inserting}>
                    {t('common.cancel')}
                </Button>
                <Button onClick={() => void insert(onInsert)} disabled={current.length === 0 || inserting || uploading}>
                    {insertLabel}
                </Button>
            </footer>
            <p className="sr-only" aria-live="assertive">
                {announcement}
            </p>
        </dialog>,
        document.body,
    );
}

type LibraryTabProps = {
    context: MediaPickerContext;
    defaultFolderId: number | null;
    foldersReady: boolean;
    picked: Media[];
    onToggle: (media: Media) => void;
};

function LibraryTab({ context, defaultFolderId, foldersReady, picked, onToggle }: LibraryTabProps) {
    const { t } = useI18n();
    const counts = useMediaCounts();
    const folders = useMediaFolders();
    const onlyKind = context.kinds.length === 1 ? context.kinds[0]! : null;

    // Open on the caller's folder once the folder list says which id that is.
    const [view, setView] = useState<View | null>(null);
    const effectiveView: View | null = view ?? (foldersReady || !context.defaultFolder ? (defaultFolderId !== null ? { kind: 'folder', id: defaultFolderId } : { kind: 'all' }) : null);
    const [kind, setKind] = useState<MediaKind | null>(onlyKind);
    const [sort, setSort] = useState<MediaSort>('newest');
    const [typed, setTyped] = useState('');
    const [search, setSearch] = useState('');

    useEffect(() => {
        const id = window.setTimeout(() => setSearch(typed), 250);
        return () => window.clearTimeout(id);
    }, [typed]);

    const filters: MediaFilters = {
        ...defaultFilters,
        folder: effectiveView?.kind === 'folder' ? effectiveView.id : null,
        unused: effectiveView?.kind === 'unused',
        missingAlt: effectiveView?.kind === 'missingBn' ? 'bn' : null,
        kind,
        sort,
        search,
    };
    const list = useMediaList(filters, { enabled: effectiveView !== null });
    const items = list.data?.pages.flatMap((p) => p.data) ?? [];
    const total = list.data?.pages[0]?.meta.total ?? 0;

    const order = new Map(picked.map((m, i) => [m.id, i + 1]));
    const viewName =
        effectiveView?.kind === 'folder'
            ? (() => {
                  const folder = folders.data?.find((f) => f.id === effectiveView.id);
                  return folder ? folderLabel(folder, t) : '';
              })()
            : effectiveView?.kind === 'unused'
              ? t('media.rail.unused')
              : effectiveView?.kind === 'missingBn'
                ? t('media.rail.missingBn')
                : t('media.rail.all');
    const viewCount =
        effectiveView?.kind === 'folder'
            ? counts.data?.folders.find((f) => f.id === effectiveView.id)?.count
            : effectiveView?.kind === 'unused'
              ? counts.data?.unused
              : effectiveView?.kind === 'missingBn'
                ? counts.data?.missing_alt.bn
                : counts.data?.all;

    const isView = (v: View) => effectiveView !== null && JSON.stringify(effectiveView) === JSON.stringify(v);
    const folderCount = (id: number) => counts.data?.folders.find((f) => f.id === id)?.count;

    return (
        <div className="flex items-start gap-6">
            <nav aria-label={t('picker.folders')} className="flex w-60 shrink-0 flex-col gap-0.5 max-lg:hidden">
                <MediaFilterItem label={t('media.rail.all')} icon={ImagesIcon} count={fmt(counts.data?.all)} active={isView({ kind: 'all' })} onClick={() => setView({ kind: 'all' })} />
                {(folders.data ?? []).map((folder) => (
                    <MediaFilterItem
                        key={folder.id}
                        label={folderLabel(folder, t)}
                        icon={folderIcon(folder.system_key)}
                        count={fmt(folderCount(folder.id))}
                        active={isView({ kind: 'folder', id: folder.id })}
                        onClick={() => setView({ kind: 'folder', id: folder.id })}
                    />
                ))}
                <MediaFilterItem label={t('media.rail.unused')} icon={CircleDashedIcon} count={fmt(counts.data?.unused)} active={isView({ kind: 'unused' })} onClick={() => setView({ kind: 'unused' })} />
                {context.kinds.includes('image') && (
                    <>
                        <div aria-hidden className="h-px w-full bg-line-default" />
                        <MediaFilterItem
                            label={t('media.rail.missingBn')}
                            warning
                            count={fmt(counts.data?.missing_alt.bn)}
                            active={isView({ kind: 'missingBn' })}
                            onClick={() => setView({ kind: 'missingBn' })}
                        />
                    </>
                )}
            </nav>

            <div className="flex min-w-0 flex-1 flex-col gap-4">
                <div className="flex flex-wrap items-center gap-2">
                    <SearchField
                        label={t('picker.search')}
                        placeholder={viewCount !== undefined ? t('picker.searchIn', { count: formatCount(viewCount), folder: viewName }) : t('picker.search')}
                        value={typed}
                        onChange={(event) => setTyped(event.target.value)}
                        className="min-w-48 flex-1 max-lg:basis-full"
                    />
                    {/* Mobile and 768: the rail becomes a select (Figma 301:52924). */}
                    <Select
                        aria-label={t('media.folderSelect')}
                        className="w-50 max-lg:flex-1 lg:hidden"
                        value={effectiveView ? JSON.stringify(effectiveView) : ''}
                        onChange={(event) => setView(JSON.parse(event.target.value) as View)}
                    >
                        <option value={JSON.stringify({ kind: 'all' })}>{t('media.rail.all')}</option>
                        {(folders.data ?? []).map((folder) => (
                            <option key={folder.id} value={JSON.stringify({ kind: 'folder', id: folder.id })}>
                                {folderLabel(folder, t)}
                            </option>
                        ))}
                        <option value={JSON.stringify({ kind: 'unused' })}>{t('media.rail.unused')}</option>
                        {context.kinds.includes('image') && <option value={JSON.stringify({ kind: 'missingBn' })}>{t('media.rail.missingBn')}</option>}
                    </Select>
                    <Select aria-label={t('media.type')} className="w-50 max-lg:flex-1" value={kind ?? ''} onChange={(event) => setKind((event.target.value || null) as MediaKind | null)}>
                        {onlyKind === null && <option value="">{t('media.type.all')}</option>}
                        {context.kinds.map((k) => (
                            <option key={k} value={k}>
                                {t(k === 'image' ? 'media.type.image' : 'media.type.document')}
                            </option>
                        ))}
                    </Select>
                    <Select aria-label={t('media.sort')} className="w-50 max-lg:flex-1" value={sort} onChange={(event) => setSort(event.target.value as MediaSort)}>
                        {sorts.map((s) => (
                            <option key={s} value={s}>
                                {t(`media.sort.${s}`)}
                            </option>
                        ))}
                    </Select>
                </div>

                {list.isSuccess && items.length === 0 ? (
                    <div className="flex flex-col items-center gap-2 rounded-md border border-dashed border-line-strong bg-subtle p-8 text-center">
                        <p className="t-admin-body text-fg-secondary">{search ? t('picker.noResults', { search }) : t('picker.empty')}</p>
                        {search && (
                            <Button variant="outline" size="sm" onClick={() => setTyped('')}>
                                {t('picker.clearSearch')}
                            </Button>
                        )}
                    </div>
                ) : (
                    <ul aria-label={t('picker.grid')} aria-busy={list.isFetching || undefined} className="grid grid-cols-2 gap-4 sm:grid-cols-3 md:grid-cols-4">
                        {items.map((media) => {
                            const n = order.get(media.id);
                            const flags = fitFlags(media, context.ratio, context.minSize);
                            const meta = tileMeta(media, flags.crop);
                            const accepted = context.kinds.includes(media.kind);
                            return (
                                <li key={media.id} className="min-w-0">
                                    <MediaTile
                                        filename={media.filename}
                                        meta={meta}
                                        kind={media.kind}
                                        thumbUrl={media.urls.thumb ?? media.urls.medium}
                                        usedCount={media.used_in_count}
                                        alt={altStateOf(media)}
                                        state={n === undefined ? 'default' : context.multiple ? 'ordered' : 'selected'}
                                        order={n}
                                        disabled={!accepted}
                                        label={n !== undefined && context.multiple ? t('picker.pickOrdered', { name: media.filename, meta, n }) : t('picker.pick', { name: media.filename, meta })}
                                        chips={
                                            <>
                                                {flags.crop && <StatusChip tone="warning" label={t('picker.flag.crop')} />}
                                                {flags.small && <StatusChip tone="warning" label={t('picker.flag.small', { min: context.minSize ?? 0 })} />}
                                                {media.processing_status === 'processing' && <StatusChip tone="neutral" label={t('picker.flag.processing')} />}
                                            </>
                                        }
                                        onClick={() => onToggle(media)}
                                    />
                                </li>
                            );
                        })}
                    </ul>
                )}

                {list.isSuccess && items.length > 0 && (
                    <div className="flex items-center gap-3">
                        <p className="min-w-0 flex-1 t-admin-caption text-fg-secondary tabular">{t('picker.showing', { shown: formatCount(items.length), total: formatCount(total) })}</p>
                        {list.hasNextPage && (
                            <Button variant="outline" size="sm" disabled={list.isFetchingNextPage} onClick={() => void list.fetchNextPage()}>
                                {list.isFetchingNextPage ? t('common.loading') : t('common.loadMore')}
                            </Button>
                        )}
                    </div>
                )}
            </div>
        </div>
    );
}

/** "1600×1600 · 298 KB", or "1800×1200 · 3:2 · 402 KB" when the ratio is off (Figma 303:55922). */
function tileMeta(media: Media, showRatio: boolean): string {
    if (media.kind !== 'image') return `PDF · ${formatBytes(media.size_bytes || media.original_size_bytes)}`;
    return [formatDimensions(media.width, media.height), showRatio ? aspectLabel(media.width, media.height) : null, media.size_bytes > 0 ? formatBytes(media.size_bytes) : null]
        .filter(Boolean)
        .join(' · ');
}

const fmt = (n: number | undefined) => (n === undefined ? undefined : formatCount(n));

function folderIcon(key: SystemFolderKey | null): Icon {
    // Same glyphs as the library rail.
    return folderIcons[key ?? 'custom'];
}

const folderIcons: Record<SystemFolderKey | 'custom', Icon> = {
    products: PackageIcon,
    category_banners: ImageIcon,
    blog: NewspaperIcon,
    pages: BrowserIcon,
    custom: FolderSimpleIcon,
};

function UploadTab({ context, queue }: { context: MediaPickerContext; queue: ReturnType<typeof useMediaUploadQueue> }) {
    const { t } = useI18n();
    const input = useRef<HTMLInputElement>(null);
    const [over, setOver] = useState(false);
    const accept = context.kinds.includes('document') ? `${IMAGE_ACCEPT},application/pdf` : IMAGE_ACCEPT;
    const isFileDrag = (event: DragEvent) => Array.from(event.dataTransfer.types).includes('Files');

    return (
        <div className="flex flex-col gap-4">
            <div
                onDragOver={(event) => {
                    if (!isFileDrag(event)) return;
                    event.preventDefault();
                    setOver(true);
                }}
                onDragLeave={(event) => {
                    if (!event.currentTarget.contains(event.relatedTarget as Node | null)) setOver(false);
                }}
                onDrop={(event) => {
                    if (!isFileDrag(event)) return;
                    event.preventDefault();
                    setOver(false);
                    queue.add(Array.from(event.dataTransfer.files));
                }}
                className={`flex flex-col items-center gap-2 rounded-md border px-6 py-8 text-center transition-colors duration-150 ease-standard ${
                    over ? 'border-solid border-line-brand bg-brand-subtle' : 'border-dashed border-line-strong bg-subtle'
                }`}
            >
                <CloudArrowUpIcon size={32} weight="fill" aria-hidden className="text-fg-primary" />
                <p className="t-admin-body-strong text-fg-primary">{over ? t('picker.dropActive') : t('picker.drop')}</p>
                {context.uploadHint && <p className="t-admin-caption text-fg-secondary">{context.uploadHint}</p>}
                <Button variant="outline" size="sm" onClick={() => input.current?.click()}>
                    <UploadSimpleIcon size={16} aria-hidden />
                    {t('picker.browse')}
                </Button>
                <input
                    ref={input}
                    type="file"
                    multiple={context.multiple}
                    accept={accept}
                    className="sr-only"
                    tabIndex={-1}
                    aria-hidden
                    data-testid="picker-file-input"
                    onChange={(event) => {
                        queue.add(Array.from(event.target.files ?? []));
                        event.target.value = '';
                    }}
                />
            </div>

            {queue.items.length > 0 && (
                <ul aria-label={t('picker.uploadQueue')} className="flex flex-col overflow-hidden rounded-md border border-line-default">
                    {queue.items.map((item) => (
                        <UploadQueueItem key={item.key} item={item} onRetry={() => queue.retry(item.key)} onRemove={() => queue.remove(item.key)} />
                    ))}
                </ul>
            )}
        </div>
    );
}
