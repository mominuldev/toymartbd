import { useEffect, useMemo, useRef, useState, type DragEvent } from 'react';
import { useSearchParams } from 'react-router';
import { CopyIcon, FolderSimpleIcon, UploadSimpleIcon, WarningIcon } from '@phosphor-icons/react';
import { ContentHeader } from '@/components/shell/ContentHeader';
import { Button } from '@/components/ui/Button';
import { QueryError } from '@/components/ui/QueryError';
import { SearchField } from '@/components/ui/SearchField';
import { SegmentedControl } from '@/components/ui/SegmentedControl';
import { Select } from '@/components/ui/Select';
import { useToast } from '@/components/ui/Toast';
import { can, useMe } from '@/features/auth/api';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import {
    ACCEPT,
    kinds,
    problemMessage,
    saveBlob,
    sorts,
    useBulkDelete,
    useBulkDownload,
    useBulkMove,
    useMediaCounts,
    useMediaFolders,
    useMediaList,
    useMediaStorage,
    type BulkDeleteResult,
    type Media,
    type MediaFilters,
    type MediaKind,
    type MediaSort,
    type MissingAlt,
} from './api';
import { MediaDeleteDialog } from './MediaDeleteDialog';
import { MediaDetailPanel } from './MediaDetailPanel';
import { MoveDialog, NewFolderDialog } from './MediaDialogs';
import { MediaBulkBar, MediaList } from './MediaList';
import { MediaRail } from './MediaRail';
import { DropOverlay, EmptyLibrary, NoResults, type FilterChip } from './MediaStates';
import { altStateOf, folderLabel, tileMeta } from './parts/display';
import { formatBytes, formatCount } from './parts/format';
import { MediaTile } from './parts/MediaTile';
import { UploadQueue } from './parts/UploadQueue';
import { useBreakpoint } from './parts/useBreakpoint';
import { useMediaUploadQueue } from './parts/useMediaUploadQueue';

const sortLabel: Record<MediaSort, MessageKey> = { newest: 'media.sort.newest', oldest: 'media.sort.oldest', name: 'media.sort.name', size: 'media.sort.size' };
const sortCaption: Record<MediaSort, MessageKey> = {
    newest: 'media.sortCaption.newest',
    oldest: 'media.sortCaption.oldest',
    name: 'media.sortCaption.name',
    size: 'media.sortCaption.size',
};
const kindLabel: Record<MediaKind, MessageKey> = { image: 'media.type.image', document: 'media.type.document' };

function useDebounced<T>(value: T, ms = 250): T {
    const [debounced, setDebounced] = useState(value);
    useEffect(() => {
        const id = window.setTimeout(() => setDebounced(value), ms);
        return () => window.clearTimeout(id);
    }, [value, ms]);
    return debounced;
}

/** URL ⇄ filters. `?folder=12|none|unused&kind=image&alt=bn&q=…&sort=size&view=list&asset=42`. */
function readFilters(params: URLSearchParams, search: string): MediaFilters {
    const folder = params.get('folder') ?? '';
    const kind = params.get('kind') as MediaKind | null;
    const alt = params.get('alt') as MissingAlt | null;
    const sort = params.get('sort') as MediaSort | null;
    return {
        folder: /^\d+$/.test(folder) ? Number(folder) : folder === 'none' ? 'none' : null,
        unused: folder === 'unused',
        kind: kind && kinds.includes(kind) ? kind : null,
        missingAlt: alt === 'bn' || alt === 'en' ? alt : null,
        search,
        sort: sort && sorts.includes(sort) ? sort : 'newest',
    };
}

/**
 * Media library — `/store/media` (admin spec §B9, Figma Theme B 303:53185 and siblings).
 * Header with the counts line; toolbar (search, type, sort, grid/list) with its state in the
 * URL; the folder rail; a cursor-paginated tile grid or a DataTable list with bulk actions;
 * the detail panel (inline at 1440, a drawer at 1024, behind a bottom bar at 768); a page-wide
 * drop target and the upload queue. Every number and every rule is the server's.
 */
export function MediaLibraryPage() {
    const { t } = useI18n();
    const toast = useToast();
    const { data: me } = useMe();
    const canManage = can(me, 'media.manage');
    const canDelete = can(me, 'media.delete');
    const bp = useBreakpoint();
    const [params, setParams] = useSearchParams();
    const [search, setSearch] = useState(params.get('q') ?? '');
    const debounced = useDebounced(search.trim());
    const filters = useMemo(() => readFilters(params, debounced), [params, debounced]);
    const view = params.get('view') === 'list' ? 'list' : 'grid';
    const assetParam = params.get('asset');
    const selectedId = assetParam && /^\d+$/.test(assetParam) ? Number(assetParam) : null;

    const folders = useMediaFolders();
    const counts = useMediaCounts();
    const storage = useMediaStorage();
    const list = useMediaList(filters);
    const rows = useMemo(() => list.data?.pages.flatMap((p) => p.data) ?? [], [list.data]);
    const total = list.data?.pages[0]?.meta.total ?? 0;
    const folderList = folders.data ?? [];

    // A deep link (?asset=42) opens the details wherever they live at this width.
    const [drawerOpen, setDrawerOpen] = useState(selectedId !== null && bp !== 'sm');
    const [checked, setChecked] = useState<Set<number>>(new Set());
    const [names, setNames] = useState<Map<number, Media>>(new Map());
    const [bulkResult, setBulkResult] = useState<(BulkDeleteResult & { names: Map<number, string> }) | null>(null);
    const [dialog, setDialog] = useState<null | 'folder' | 'move' | 'bulkDelete'>(null);
    const [dragCount, setDragCount] = useState<number | null>(null);
    const dragDepth = useRef(0);
    const fileInput = useRef<HTMLInputElement>(null);
    const bulkMove = useBulkMove();
    const bulkDelete = useBulkDelete();
    const bulkZip = useBulkDownload();

    const setParam = (changes: Record<string, string | null>, { keepSelection = false } = {}) => {
        setParams(
            (current) => {
                const next = new URLSearchParams(current);
                Object.entries(changes).forEach(([key, value]) => (value ? next.set(key, value) : next.delete(key)));
                return next;
            },
            { replace: true },
        );
        // A view change clears the bulk selection (admin spec, bulk selection rule).
        if (!keepSelection) {
            setChecked(new Set());
            setBulkResult(null);
        }
    };

    useEffect(() => {
        if (debounced !== (params.get('q') ?? '')) setParam({ q: debounced || null });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [debounced]);

    // Remember the rows seen, so a bulk result can name files that have since left the list.
    useEffect(() => {
        if (rows.length === 0) return;
        setNames((prev) => {
            const next = new Map(prev);
            rows.forEach((r) => next.set(r.id, r));
            return next;
        });
    }, [rows]);

    const targetFolderId = typeof filters.folder === 'number' ? filters.folder : null;
    const targetFolder = folderList.find((f) => f.id === targetFolderId);
    const targetFolderName = targetFolder ? folderLabel(targetFolder, t) : t('media.folder.none');
    const queue = useMediaUploadQueue({ folderId: targetFolderId });
    const newWithoutAlt = queue.items.filter((i) => i.result && i.result.kind === 'image' && i.result.alt_status !== 'complete' && i.status !== 'failed').map((i) => i.result!);

    const select = (id: number | null) => {
        setParam({ asset: id ? String(id) : null }, { keepSelection: true });
        if (id !== null && (bp === 'lg' || view === 'list')) setDrawerOpen(true);
        if (id === null) setDrawerOpen(false);
    };

    const startUpload = (files: FileList | File[] | null) => {
        if (!files || !canManage) return;
        const list = Array.from(files);
        if (list.length > 0) queue.add(list);
    };

    // Drag-and-drop anywhere over the page (Figma 303:54740). A depth counter, because
    // dragenter/dragleave fire for every child the pointer crosses.
    const isFileDrag = (e: DragEvent) => Array.from(e.dataTransfer?.types ?? []).includes('Files');
    const dropHandlers = canManage
        ? {
              onDragEnter: (e: DragEvent) => {
                  if (!isFileDrag(e)) return;
                  e.preventDefault();
                  dragDepth.current += 1;
                  setDragCount(e.dataTransfer.items?.length ?? 0);
              },
              onDragOver: (e: DragEvent) => {
                  if (!isFileDrag(e)) return;
                  e.preventDefault();
                  e.dataTransfer.dropEffect = 'copy';
              },
              onDragLeave: (e: DragEvent) => {
                  if (!isFileDrag(e)) return;
                  dragDepth.current = Math.max(0, dragDepth.current - 1);
                  if (dragDepth.current === 0) setDragCount(null);
              },
              onDrop: (e: DragEvent) => {
                  if (!isFileDrag(e)) return;
                  e.preventDefault();
                  dragDepth.current = 0;
                  setDragCount(null);
                  startUpload(e.dataTransfer.files);
              },
          }
        : {};

    // Header counts line.
    const files = counts.data?.all;
    const usedBytes = storage.data ? formatBytes(storage.data.used_bytes) : null;
    const quota = storage.data?.quota_bytes ? formatBytes(storage.data.quota_bytes) : null;
    const missingBn = counts.data?.missing_alt.bn ?? 0;
    let subtitle: string | undefined;
    if (files !== undefined && usedBytes) {
        if (files === 0) subtitle = quota ? t('media.subtitleEmptyQuota', { used: usedBytes, quota }) : t('media.subtitleEmpty', { used: usedBytes });
        else if (missingBn > 0)
            subtitle = quota
                ? t('media.subtitleQuota', { files: formatCount(files), used: usedBytes, quota, missing: formatCount(missingBn) })
                : t('media.subtitle', { files: formatCount(files), used: usedBytes, missing: formatCount(missingBn) });
        else subtitle = quota ? t('media.subtitleQuotaNoMissing', { files: formatCount(files), used: usedBytes, quota }) : t('media.subtitleNoMissing', { files: formatCount(files), used: usedBytes });
    }

    const filtered = filters.folder !== null || filters.unused || filters.kind !== null || filters.missingAlt !== null || filters.search !== '';
    const libraryEmpty = counts.data?.all === 0 && !filtered;

    // What the grid heading calls the current view.
    const viewName = filters.missingAlt
        ? t(filters.missingAlt === 'bn' ? 'media.rail.missingBn' : 'media.rail.missingEn')
        : filters.unused
          ? t('media.rail.unused')
          : filters.folder === 'none'
            ? t('media.folder.none')
            : typeof filters.folder === 'number'
              ? (targetFolder ? folderLabel(targetFolder, t) : '')
              : filters.kind
                ? t(kindLabel[filters.kind])
                : t('media.rail.all');

    const chips: FilterChip[] = [
        ...(typeof filters.folder === 'number' && targetFolder
            ? [{ key: 'folder', label: t('media.filter.folder', { name: folderLabel(targetFolder, t) }), onRemove: () => setParam({ folder: null }) }]
            : []),
        ...(filters.folder === 'none' ? [{ key: 'folder', label: t('media.filter.folder', { name: t('media.folder.none') }), onRemove: () => setParam({ folder: null }) }] : []),
        ...(filters.unused ? [{ key: 'unused', label: t('media.rail.unused'), onRemove: () => setParam({ folder: null }) }] : []),
        ...(filters.kind ? [{ key: 'kind', label: t('media.filter.type', { name: t(kindLabel[filters.kind]) }), onRemove: () => setParam({ kind: null }) }] : []),
        ...(filters.missingAlt
            ? [{ key: 'alt', label: t(filters.missingAlt === 'bn' ? 'media.rail.missingBn' : 'media.rail.missingEn'), onRemove: () => setParam({ alt: null }) }]
            : []),
    ];
    const scope = [
        targetFolder ? t('media.scope.folder', { name: folderLabel(targetFolder, t) }) : '',
        filters.unused ? t('media.scope.unused') : '',
        filters.kind ? t('media.scope.kind', { name: t(kindLabel[filters.kind]) }) : '',
        filters.missingAlt === 'bn' ? t('media.scope.missingBn') : filters.missingAlt === 'en' ? t('media.scope.missingEn') : '',
    ].join('');
    const scopeTotal =
        typeof filters.folder === 'number' ? (counts.data?.folders.find((f) => f.id === filters.folder)?.count ?? 0) : filters.unused ? (counts.data?.unused ?? 0) : (counts.data?.all ?? 0);
    const clearAll = () => {
        setSearch('');
        setParam({ folder: null, kind: null, alt: null, q: null });
    };

    // Bulk selection (list view).
    const checkedRows = rows.filter((r) => checked.has(r.id));
    const checkedUsed = checkedRows.filter((r) => r.used_in_count > 0).length;
    const toggle = (id: number) =>
        setChecked((prev) => {
            const next = new Set(prev);
            if (next.has(id)) next.delete(id);
            else next.add(id);
            return next;
        });
    const togglePage = () => setChecked((prev) => (rows.every((r) => prev.has(r.id)) ? new Set() : new Set(rows.map((r) => r.id))));
    const selectionIds = [...checked];

    const runBulkDelete = () => {
        const ids = selectionIds;
        bulkDelete.mutate(ids, {
            onSuccess: (result) => {
                setDialog(null);
                const resultNames = new Map(ids.map((id) => [id, names.get(id)?.filename ?? `#${id}`]));
                setBulkResult({ ...result, names: resultNames });
                // Keep only what was not deleted selected, so the operator sees what is left.
                setChecked(new Set(result.results.filter((r) => r.status === 'in_use').map((r) => r.id)));
                if (selectedId !== null && result.results.some((r) => r.id === selectedId && r.status === 'deleted')) select(null);
            },
            onError: (error) => {
                setDialog(null);
                toast.notify(problemMessage(error, 'ids') ?? t('error.title'), 'danger');
            },
        });
    };

    const runZip = () =>
        bulkZip.mutate(selectionIds, {
            onSuccess: ({ blob, filename }) => saveBlob(blob, filename),
            onError: (error) => toast.notify(problemMessage(error, 'ids') ?? t('error.title'), 'danger'),
        });

    const runMove = (folderId: number | null) =>
        bulkMove.mutate(
            { ids: selectionIds, folderId },
            {
                onSuccess: ({ moved }) => {
                    setDialog(null);
                    const folder = folderList.find((f) => f.id === folderId);
                    toast.notify(t('media.bulk.moved', { count: moved, folder: folder ? folderLabel(folder, t) : t('media.folder.none') }));
                    setChecked(new Set());
                },
                onError: (error) => toast.notify(problemMessage(error, 'ids') ?? t('error.title'), 'danger'),
            },
        );

    const firstMissingAlt = checkedRows.find((r) => r.kind === 'image' && r.alt_status !== 'complete');

    // Panel placement: inline only in the 1440 grid; a drawer otherwise.
    const inlinePanel = selectedId !== null && bp === 'xl' && view === 'grid';
    const drawerPanel = selectedId !== null && !inlinePanel && drawerOpen;
    const selectedRow = rows.find((r) => r.id === selectedId);

    const header = (
        <ContentHeader
            breadcrumb={[{ label: t('media.crumb.store') }, { label: t('media.crumb.online') }, { label: t('media.title') }]}
            title={t('media.title')}
            subtitle={subtitle}
            actions={
                canManage && (
                    <>
                        {!libraryEmpty && (
                            <Button variant="outline" onClick={() => setDialog('folder')} className="max-lg:hidden">
                                <FolderSimpleIcon size={16} weight="fill" aria-hidden />
                                {t('media.newFolder')}
                            </Button>
                        )}
                        <Button onClick={() => fileInput.current?.click()}>
                            <UploadSimpleIcon size={16} aria-hidden />
                            {bp === 'sm' ? t('media.uploadShort') : t('media.upload')}
                        </Button>
                    </>
                )
            }
        />
    );

    const fileInputEl = (
        <input
            ref={fileInput}
            type="file"
            multiple
            hidden
            accept={ACCEPT}
            data-testid="media-upload-input"
            onChange={(e) => {
                startUpload(e.target.files);
                e.target.value = '';
            }}
        />
    );

    const queuePanel = queue.items.length > 0 && (
        <UploadQueue items={queue.items} counts={queue.counts} folderName={targetFolderName} onRetry={queue.retry} onRemove={queue.remove} onClose={queue.reset} />
    );

    if (counts.isError) {
        return (
            <section className="flex flex-col gap-6">
                {header}
                <QueryError error={counts.error} onRetry={() => void counts.refetch()} />
            </section>
        );
    }

    if (libraryEmpty) {
        return (
            <section className="relative flex flex-col gap-6" {...dropHandlers}>
                {header}
                {fileInputEl}
                <div className="relative">
                    <EmptyLibrary canManage={canManage} onUpload={() => fileInput.current?.click()} />
                    {dragCount !== null && <DropOverlay count={dragCount} folder={targetFolderName} />}
                </div>
                {queuePanel}
            </section>
        );
    }

    const typeSelect = (
        <Select aria-label={t('media.type')} value={filters.kind ?? ''} onChange={(e) => setParam({ kind: e.target.value || null })} className="min-w-0 flex-1 lg:w-50 lg:flex-none">
            <option value="">{t('media.type.all')}</option>
            {kinds.map((k) => (
                <option key={k} value={k}>
                    {t(kindLabel[k])}
                </option>
            ))}
        </Select>
    );

    const folderValue = filters.unused ? 'unused' : filters.folder === null ? '' : String(filters.folder);
    const folderCount = (id: number) => counts.data?.folders.find((f) => f.id === id)?.count ?? 0;

    return (
        <section className="flex flex-col gap-6 pb-20 lg:pb-0" {...dropHandlers}>
            {header}
            {fileInputEl}

            {/* Toolbar: search · type · sort · grid/list. State lives in the URL. */}
            <div className="flex flex-col gap-3">
                <div className="flex flex-wrap items-center gap-3">
                    <SearchField
                        label={t('media.search')}
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        placeholder={t('media.searchPlaceholder')}
                        maxLength={100}
                        className="w-full lg:w-auto lg:flex-1"
                    />
                    <div className="hidden lg:block">{typeSelect}</div>
                    <Select aria-label={t('media.sort')} value={filters.sort} onChange={(e) => setParam({ sort: e.target.value === 'newest' ? null : e.target.value })} className="hidden lg:block lg:w-50">
                        {sorts.map((s) => (
                            <option key={s} value={s}>
                                {t(sortLabel[s])}
                            </option>
                        ))}
                    </Select>
                    <SegmentedControl
                        label={t('media.view')}
                        options={[
                            { value: 'grid', label: t('media.view.grid') },
                            { value: 'list', label: t('media.view.list') },
                        ]}
                        value={view}
                        onChange={(next) => setParam({ view: next === 'list' ? 'list' : null })}
                        className="hidden h-10 lg:flex"
                    />
                </div>
                {/* 768: folder and type as selects (Figma 301:52924). */}
                <div className="flex items-center gap-3 lg:hidden">
                    <Select aria-label={t('media.folderSelect')} value={folderValue} onChange={(e) => setParam({ folder: e.target.value || null })} className="min-w-0 flex-1">
                        <option value="">{t('media.folderOption', { name: t('media.rail.all'), count: formatCount(counts.data?.all ?? 0) })}</option>
                        {folderList.map((f) => (
                            <option key={f.id} value={f.id}>
                                {t('media.folderOption', { name: folderLabel(f, t), count: formatCount(folderCount(f.id)) })}
                            </option>
                        ))}
                        <option value="unused">{t('media.folderOption', { name: t('media.rail.unused'), count: formatCount(counts.data?.unused ?? 0) })}</option>
                    </Select>
                    {typeSelect}
                    <SegmentedControl
                        label={t('media.view')}
                        options={[
                            { value: 'grid', label: t('media.view.grid') },
                            { value: 'list', label: t('media.view.list') },
                        ]}
                        value={view}
                        onChange={(next) => setParam({ view: next === 'list' ? 'list' : null })}
                        className="h-10"
                    />
                </div>
                {missingBn > 0 && filters.missingAlt !== 'bn' && (
                    <div className="flex items-center gap-3 rounded-md bg-warning-subtle px-4 py-3 lg:hidden">
                        <WarningIcon size={16} aria-hidden className="shrink-0 text-feedback-warning" />
                        <p className="min-w-0 flex-1 t-admin-caption text-fg-primary">{t('media.attentionBanner', { count: formatCount(missingBn) })}</p>
                        <Button variant="ghost" size="sm" onClick={() => setParam({ alt: 'bn' })}>
                            {t('media.attentionReview')}
                        </Button>
                    </div>
                )}
            </div>

            {view === 'list' && checked.size > 0 && (
                <MediaBulkBar
                    count={checked.size}
                    unused={checked.size - checkedUsed}
                    used={checkedUsed}
                    canManage={canManage}
                    canDelete={canDelete}
                    zipping={bulkZip.isPending}
                    deleting={bulkDelete.isPending}
                    result={bulkResult}
                    onMove={() => setDialog('move')}
                    onZip={runZip}
                    onAddAlt={firstMissingAlt ? () => select(firstMissingAlt.id) : null}
                    onDelete={() => setDialog('bulkDelete')}
                    onClear={() => {
                        setChecked(new Set());
                        setBulkResult(null);
                    }}
                />
            )}
            {view === 'list' && checked.size === 0 && bulkResult && (
                <MediaBulkBar
                    count={0}
                    unused={0}
                    used={0}
                    canManage={false}
                    canDelete={false}
                    zipping={false}
                    deleting={false}
                    result={bulkResult}
                    onMove={() => undefined}
                    onZip={() => undefined}
                    onAddAlt={null}
                    onDelete={() => undefined}
                    onClear={() => setBulkResult(null)}
                />
            )}

            <div
                className={`relative grid grid-cols-1 gap-6 lg:grid-cols-[12.5rem_minmax(0,1fr)] xl:grid-cols-[15rem_minmax(0,1fr)] ${inlinePanel ? 'xl:grid-cols-[15rem_minmax(0,1fr)_21.5rem]' : ''}`}
            >
                <div className="hidden lg:block">
                    <MediaRail
                        filters={filters}
                        folders={folderList}
                        counts={counts.data}
                        storage={storage.data}
                        canManage={canManage}
                        onFolder={(folder) => setParam({ folder: folder === null ? null : String(folder) })}
                        onKind={(kind) => setParam({ kind })}
                        onMissingAlt={(lang) => setParam({ alt: lang })}
                        onNewFolder={() => setDialog('folder')}
                    />
                </div>

                <div className="flex min-w-0 flex-col gap-4">
                    {list.isError ? (
                        <QueryError error={list.error} onRetry={() => void list.refetch()} />
                    ) : list.isPending ? (
                        <p className="t-admin-body text-fg-tertiary" aria-busy="true">
                            {t('common.loading')}
                        </p>
                    ) : rows.length === 0 ? (
                        <NoResults
                            query={filters.search}
                            chips={chips}
                            scope={scope}
                            total={scopeTotal}
                            allCount={counts.data?.all}
                            onSearchAll={filters.search && chips.length > 0 ? () => setParam({ folder: null, kind: null, alt: null }) : null}
                            onClear={clearAll}
                        />
                    ) : (
                        <>
                            {view === 'grid' && (
                                <p className="flex flex-wrap items-baseline gap-2">
                                    <span className="t-admin-body-strong text-fg-primary">{viewName}</span>
                                    <span className="t-admin-caption text-fg-secondary tabular">
                                        {t('media.gridHeading', { files: formatCount(total), sort: t(sortCaption[filters.sort]) })}
                                    </span>
                                </p>
                            )}
                            {newWithoutAlt.length > 0 && (
                                <div className="flex items-center gap-3 rounded-md bg-warning-subtle px-4 py-3" role="status">
                                    <WarningIcon size={16} aria-hidden className="shrink-0 text-feedback-warning" />
                                    <p className="min-w-0 flex-1 t-admin-body text-fg-primary">{t('media.newAltBar', { count: newWithoutAlt.length })}</p>
                                    <Button variant="ghost" size="sm" onClick={() => select(newWithoutAlt[0]!.id)}>
                                        {t('media.addAltText')}
                                    </Button>
                                </div>
                            )}
                            {view === 'grid' ? (
                                <>
                                    <ul aria-label={t('media.grid')} className="grid grid-cols-2 gap-4 sm:grid-cols-[repeat(auto-fill,minmax(10rem,1fr))]">
                                        {rows.map((media) => (
                                            <li key={media.id} className="flex">
                                                <MediaTile
                                                    filename={media.filename}
                                                    meta={tileMeta(media)}
                                                    kind={media.kind}
                                                    thumbUrl={media.urls.thumb}
                                                    documentLabel={media.kind === 'document' ? t('media.pdf') : undefined}
                                                    usedCount={media.used_in_count}
                                                    alt={altStateOf(media)}
                                                    state={selectedId === media.id ? 'selected' : 'default'}
                                                    onClick={() => select(selectedId === media.id ? null : media.id)}
                                                />
                                            </li>
                                        ))}
                                    </ul>
                                    <div className="flex items-center justify-center gap-3">
                                        <p className="t-admin-caption text-fg-secondary tabular" aria-live="polite">
                                            {t('media.showing', { shown: formatCount(rows.length), total: formatCount(total) })}
                                        </p>
                                        {list.hasNextPage && (
                                            <Button variant="outline" size="sm" onClick={() => void list.fetchNextPage()} disabled={list.isFetchingNextPage}>
                                                {list.isFetchingNextPage ? t('common.loading') : t('common.loadMore')}
                                            </Button>
                                        )}
                                    </div>
                                </>
                            ) : (
                                <MediaList
                                    rows={rows}
                                    folders={folderList}
                                    selected={checked}
                                    selectable={canManage || canDelete}
                                    total={total}
                                    hasMore={Boolean(list.hasNextPage)}
                                    loadingMore={list.isFetchingNextPage}
                                    onToggle={toggle}
                                    onTogglePage={togglePage}
                                    onOpen={(id) => select(id)}
                                    onLoadMore={() => void list.fetchNextPage()}
                                />
                            )}
                        </>
                    )}
                </div>

                {inlinePanel && (
                    <div className="hidden xl:block">
                        <div className="sticky top-6">
                            <MediaDetailPanel mediaId={selectedId} folders={folderList} canManage={canManage} canDelete={canDelete} onClose={() => select(null)} onDeleted={() => select(null)} />
                        </div>
                    </div>
                )}

                {dragCount !== null && <DropOverlay count={dragCount} folder={targetFolderName} />}
            </div>

            {/* 1024 and below: the detail panel is an overlay drawer over a scrim (Figma 301:52247). */}
            {drawerPanel && (
                <div className="fixed inset-0 z-40 flex justify-end" onKeyDown={(e) => e.key === 'Escape' && setDrawerOpen(false)}>
                    <button type="button" tabIndex={-1} aria-hidden onClick={() => setDrawerOpen(false)} className="absolute inset-0 bg-overlay" />
                    <div role="dialog" aria-modal="true" aria-label={t('media.detail.title')} className="relative h-full w-[min(100%,22.5rem)]">
                        <MediaDetailPanel
                            variant="drawer"
                            mediaId={selectedId}
                            folders={folderList}
                            canManage={canManage}
                            canDelete={canDelete}
                            onClose={() => setDrawerOpen(false)}
                            onDeleted={() => select(null)}
                        />
                    </div>
                </div>
            )}

            {/* 768: the bottom selection bar with Copy URL + Details (Figma 301:52924). */}
            {bp === 'sm' && view === 'grid' && selectedRow && !drawerPanel && (
                <div role="region" aria-label={t('media.detail.title')} className="fixed inset-x-0 bottom-0 z-30 flex items-center gap-3 border-t border-line-default bg-surface px-4 py-3 shadow-e3">
                    <div className="min-w-0 flex-1">
                        <p className="truncate t-admin-body-strong text-fg-primary">{selectedRow.filename}</p>
                        <p className="truncate t-admin-caption text-fg-secondary">
                            {selectedRow.used_in_count > 0
                                ? t('media.selection.summary', {
                                      count: selectedRow.used_in_count,
                                      alt: t(altStateOf(selectedRow) === 'complete' ? 'media.selection.altComplete' : 'media.selection.altIncomplete'),
                                  })
                                : t('media.selection.summaryUnused', { alt: t(altStateOf(selectedRow) === 'complete' ? 'media.selection.altComplete' : 'media.selection.altIncomplete') })}
                        </p>
                    </div>
                    {selectedRow.public_url && (
                        <Button
                            variant="outline"
                            onClick={() => void navigator.clipboard?.writeText(selectedRow.public_url!).then(() => toast.notify(t('media.detail.copied')))}
                        >
                            <CopyIcon size={16} aria-hidden />
                            {t('media.detail.copy')}
                        </Button>
                    )}
                    <Button onClick={() => setDrawerOpen(true)}>{t('media.selection.details')}</Button>
                </div>
            )}

            {queuePanel}

            <NewFolderDialog
                open={dialog === 'folder'}
                onClose={() => setDialog(null)}
                onCreated={(folder) => {
                    setDialog(null);
                    toast.notify(t('media.folder.created', { name: folder.name }));
                    setParam({ folder: String(folder.id) });
                }}
            />
            {dialog === 'move' && <MoveDialog open count={checked.size} folders={folderList} pending={bulkMove.isPending} onClose={() => setDialog(null)} onMove={runMove} />}
            {dialog === 'bulkDelete' && (
                <MediaDeleteDialog
                    open
                    state="confirm"
                    filenames={checkedRows.filter((r) => r.used_in_count === 0).map((r) => r.filename)}
                    skipped={checkedUsed}
                    pending={bulkDelete.isPending}
                    onConfirm={runBulkDelete}
                    onClose={() => setDialog(null)}
                />
            )}
        </section>
    );
}
