import { useMemo, useState } from 'react';
import { Link } from 'react-router';
import { ArrowClockwiseIcon, ArrowCounterClockwiseIcon, ArrowLeftIcon, CalendarBlankIcon, DesktopIcon, DeviceMobileIcon, EyeIcon } from '@phosphor-icons/react';
import { can, useMe } from '@/features/auth/api';
import { useI18n } from '@/i18n/I18nProvider';
import { ApiError } from '@/lib/api';
import { Sidebar } from '@/components/shell/Sidebar';
import { Button } from '@/components/ui/Button';
import { IconButton, iconButtonClass } from '@/components/ui/IconButton';
import { QueryError } from '@/components/ui/QueryError';
import { segmentGroupClass } from '@/components/ui/SegmentedControl';
import { useToast } from '@/components/ui/Toast';
import { formatDhakaDayMonth } from '@/features/content/shared/dhaka';
import {
    useCreateBlock,
    useDeleteBlock,
    useDuplicateBlock,
    useHomeDraft,
    usePreviewToken,
    usePublish,
    useReorderBlocks,
    useUpdateBlock,
    type BlockPatch,
    type BlockType,
    type CmsBlock,
} from './api';
import type { MediaThumb } from './BlockForm';
import { blockTitle, typeLabel } from './content';
import { PreviewCanvas, type Device } from './PreviewCanvas';
import { SectionList } from './SectionList';
import { SettingsPanel, type PanelTab } from './SettingsPanel';
import { VersionHistoryDialog } from './VersionHistoryDialog';

type Change = { id: number; before: BlockPatch; after: BlockPatch };

/** The server's values for the keys of a patch — what undo restores. */
function beforeOf(block: CmsBlock, patch: BlockPatch): BlockPatch {
    const before: Record<string, unknown> = {};
    for (const key of Object.keys(patch)) before[key] = (block as unknown as Record<string, unknown>)[key];
    return before as BlockPatch;
}

function errorsOf(error: unknown): Record<string, string> {
    if (!(error instanceof ApiError)) return {};
    return Object.fromEntries((error.problem.errors ?? []).map((e) => [e.field, e.message]));
}

/**
 * Homepage builder — Figma B · 26 Page builder (241:44102), Theme B. Full height beside the
 * 64-wide dark sidebar rail, no topbar: the Editor bar (back, title + draft status, desktop /
 * mobile and EN / বাং segments, undo / redo, Preview, Schedule, Publish), then Sections (328) ·
 * live Canvas · Settings (340).
 *
 * Edits autosave to the draft; Publish freezes it into a new version (history in the dialog
 * behind the status line). The canvas is the storefront in draft mode behind a signed,
 * 10-minute preview token — never an API credential in the browser (rule 28).
 */
export function HomeBuilderPage() {
    const { t, locale } = useI18n();
    const toast = useToast();
    const { data: me } = useMe();
    const canManage = can(me, 'content.manage');

    const draft = useHomeDraft();
    const create = useCreateBlock();
    const update = useUpdateBlock();
    const remove = useDeleteBlock();
    const duplicate = useDuplicateBlock();
    const reorder = useReorderBlocks();
    const publish = usePublish();

    const [selectedId, setSelectedId] = useState<number | null>(null);
    const [tab, setTab] = useState<PanelTab>('content');
    const [device, setDevice] = useState<Device>('desktop');
    const [previewLocale, setPreviewLocale] = useState<'en' | 'bn'>(locale === 'bn' ? 'bn' : 'en');
    const [revision, setRevision] = useState(0);
    const [panelRevision, setPanelRevision] = useState(0);
    const [savedAt, setSavedAt] = useState<Date | null>(null);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [undo, setUndo] = useState<Change[]>([]);
    const [redo, setRedo] = useState<Change[]>([]);
    const [historyOpen, setHistoryOpen] = useState(false);

    const token = usePreviewToken(previewLocale, draft.isSuccess);
    const blocks = draft.data?.blocks ?? [];
    const selected = blocks.find((b) => b.id === selectedId) ?? blocks[0] ?? null;
    const media: ReadonlyMap<number, MediaThumb> = useMemo(() => new Map((draft.data?.media ?? []).map((m): [number, MediaThumb] => [m.id, m])), [draft.data?.media]);
    const refreshPreview = () => setRevision((r) => r + 1);
    // Canvas outline names: the section type, then the name the section list shows ("Product rail · Best sellers").
    const labels = useMemo(
        () =>
            Object.fromEntries(
                blocks.map((b): [string, string] => {
                    const type = t(typeLabel(b.type));
                    const name = b.label || blockTitle(b);
                    return [b.uuid, name ? `${type} · ${name}` : type];
                }),
            ),
        [blocks, t],
    );

    const save = (block: CmsBlock, patch: BlockPatch, record: 'push' | 'none' = 'push') => {
        const before = beforeOf(block, patch);
        update.mutate(
            { id: block.id, patch },
            {
                onSuccess: () => {
                    setErrors({});
                    setSavedAt(new Date());
                    refreshPreview();
                    if (record === 'push') {
                        setUndo((stack) => [...stack.slice(-49), { id: block.id, before, after: patch }]);
                        setRedo([]);
                    }
                },
                onError: (error) => setErrors(errorsOf(error)),
            },
        );
    };

    const step = (from: Change[], setFrom: (c: Change[]) => void, setTo: (f: (c: Change[]) => Change[]) => void, direction: 'undo' | 'redo') => {
        const change = from[from.length - 1];
        if (!change) return;
        const block = blocks.find((b) => b.id === change.id);
        if (!block) return setFrom(from.slice(0, -1));
        setFrom(from.slice(0, -1));
        setTo((stack) => [...stack, change]);
        setSelectedId(block.id);
        update.mutate(
            { id: block.id, patch: direction === 'undo' ? change.before : change.after },
            {
                onSuccess: () => {
                    setSavedAt(new Date());
                    setPanelRevision((r) => r + 1); // the panel re-reads the restored values
                    refreshPreview();
                },
                onError: (error) => setErrors(errorsOf(error)),
            },
        );
    };

    const add = (type: BlockType) => {
        const position = selected ? blocks.findIndex((b) => b.id === selected.id) + 1 : undefined;
        create.mutate(
            { type, position },
            {
                onSuccess: (block) => {
                    setSelectedId(block.id);
                    setTab('content');
                    refreshPreview();
                },
                onError: () => toast.notify(t('homeBuilder.loadFailed'), 'danger'),
            },
        );
    };

    const onPublish = () =>
        publish.mutate(
            {},
            {
                onSuccess: (version) => toast.notify(t('homeBuilder.published', { version: version.version }), 'success'),
                onError: (error) => toast.notify(error instanceof ApiError && error.status === 409 ? t('homeBuilder.nothingToPublish') : t('homeBuilder.loadFailed'), 'danger'),
            },
        );

    const live = draft.data?.live ?? null;
    const status = update.isPending
        ? t('homeBuilder.status.saving')
        : Object.keys(errors).length > 0
          ? t('homeBuilder.status.saveFailed')
          : savedAt
            ? t('homeBuilder.status.saved', { time: new Intl.DateTimeFormat(locale === 'bn' ? 'bn-BD' : 'en-GB', { timeStyle: 'short', numberingSystem: 'latn' }).format(savedAt) })
            : draft.data?.has_unpublished_changes
              ? t('homeBuilder.status.unpublished')
              : t('homeBuilder.status.clean');
    const liveLine = live ? t('homeBuilder.status.live', { version: live.version, date: live.published_at ? formatDhakaDayMonth(live.published_at, locale) : '' }) : t('homeBuilder.status.neverLive');

    return (
        <div className="flex h-dvh bg-canvas text-fg-primary">
            <aside data-theme="admin-dark" className="sticky top-0 hidden h-dvh w-16 shrink-0 lg:block">
                <Sidebar permissions={me?.permissions ?? []} collapsed user={me ? { name: me.name, role: '' } : undefined} />
            </aside>

            <main id="content" className="flex min-w-0 flex-1 flex-col">
                <div className="flex shrink-0 flex-wrap items-center gap-3 border-b border-line-default bg-surface px-4 py-2.5">
                    <Link to="/store/pages" aria-label={t('homeBuilder.back')} className={iconButtonClass('ghost')}>
                        <ArrowLeftIcon size={18} aria-hidden />
                    </Link>
                    <div className="flex min-w-0 flex-col">
                        <h1 className="t-admin-body-strong text-fg-primary">{t('homeBuilder.title')}</h1>
                        <p className="t-admin-caption text-fg-tertiary">
                            <span aria-live="polite">{status}</span>
                            {' · '}
                            <button type="button" onClick={() => setHistoryOpen(true)} className="underline-offset-2 hover:text-fg-primary hover:underline">
                                {liveLine}
                                {/* Visually hidden, not aria-label: the visible line stays part of the name (WCAG 2.5.3). */}
                                <span className="sr-only"> — {t('homeBuilder.history')}</span>
                            </button>
                        </p>
                    </div>
                    <div className="min-w-0 flex-1" />
                    <div role="group" aria-label={t('homeBuilder.device')} className={`${segmentGroupClass} max-lg:hidden`}>
                        {(['desktop', 'mobile'] as const).map((d) => (
                            <button key={d} type="button" aria-pressed={device === d} aria-label={t(`homeBuilder.device.${d}`)} onClick={() => setDevice(d)} className="rounded-xs px-2.5 py-1.25 text-fg-secondary aria-pressed:bg-surface aria-pressed:text-fg-primary aria-pressed:shadow-e1">
                                {d === 'desktop' ? <DesktopIcon size={16} aria-hidden /> : <DeviceMobileIcon size={16} aria-hidden />}
                            </button>
                        ))}
                    </div>
                    <div role="group" aria-label={t('homeBuilder.language')} className={segmentGroupClass}>
                        {(['en', 'bn'] as const).map((l) => (
                            <button key={l} type="button" lang={l} aria-pressed={previewLocale === l} onClick={() => setPreviewLocale(l)} className="rounded-xs px-2.5 py-1.25 t-admin-button-sm text-fg-secondary aria-pressed:bg-surface aria-pressed:text-fg-primary aria-pressed:shadow-e1">
                                {l === 'en' ? 'EN' : 'বাং'}
                            </button>
                        ))}
                    </div>
                    <IconButton aria-label={t('homeBuilder.undo')} disabled={!canManage || undo.length === 0 || update.isPending} onClick={() => step(undo, setUndo, setRedo, 'undo')}>
                        <ArrowCounterClockwiseIcon size={18} aria-hidden />
                    </IconButton>
                    <IconButton aria-label={t('homeBuilder.redo')} disabled={!canManage || redo.length === 0 || update.isPending} onClick={() => step(redo, setRedo, setUndo, 'redo')}>
                        <ArrowClockwiseIcon size={18} aria-hidden />
                    </IconButton>
                    {token.data?.url ? (
                        <a href={token.data.url} target="_blank" rel="noopener" className={`inline-flex h-9 items-center gap-2 rounded-sm border border-line-default bg-surface px-3.5 t-admin-button text-fg-primary hover:bg-subtle`}>
                            <EyeIcon size={16} aria-hidden />
                            {t('homeBuilder.preview')}
                        </a>
                    ) : (
                        <Button variant="outline" disabled>
                            <EyeIcon size={16} aria-hidden />
                            {t('homeBuilder.preview')}
                        </Button>
                    )}
                    <Button variant="outline" disabled={!selected} onClick={() => setTab('visibility')}>
                        <CalendarBlankIcon size={16} aria-hidden />
                        {t('homeBuilder.schedule')}
                    </Button>
                    <Button disabled={!canManage || publish.isPending || !draft.data?.has_unpublished_changes} onClick={onPublish}>
                        {t('homeBuilder.publish')}
                    </Button>
                </div>

                {!canManage && draft.isSuccess && <p className="border-b border-line-default bg-subtle px-4 py-2 t-admin-caption text-fg-secondary">{t('homeBuilder.readOnly')}</p>}

                {draft.isError ? (
                    <div className="p-6">
                        <QueryError error={draft.error} onRetry={() => void draft.refetch()} />
                    </div>
                ) : (
                    <div className="flex min-h-0 flex-1">
                        <div className="w-82 shrink-0 max-md:w-full">
                            <SectionList
                                blocks={blocks}
                                selectedId={selected?.id ?? null}
                                canManage={canManage}
                                onSelect={(id) => setSelectedId(id)}
                                onReorder={(ids) => reorder.mutate({ ids }, { onSuccess: refreshPreview })}
                                onToggle={(block) => save(block, { is_enabled: !block.is_enabled })}
                                onAdd={add}
                                adding={create.isPending}
                            />
                        </div>
                        <PreviewCanvas
                            url={token.data?.url}
                            loading={token.isPending}
                            device={device}
                            revision={revision}
                            selectedId={selected?.uuid ?? null}
                            labels={labels}
                            onSelect={(uuid) => {
                                const block = blocks.find((b) => b.uuid === uuid);
                                if (!block) return;
                                setSelectedId(block.id);
                                setTab('content');
                            }}
                        />
                        <div className="w-85 shrink-0 max-md:hidden">
                            {selected ? (
                                <SettingsPanel
                                    key={`${selected.id}:${panelRevision}`}
                                    block={selected}
                                    tab={tab}
                                    onTab={setTab}
                                    canManage={canManage}
                                    errors={errors}
                                    media={media}
                                    onSave={(block, patch) => save(block, patch)}
                                    onDelete={(block) =>
                                        remove.mutate(block.id, {
                                            onSuccess: () => {
                                                setSelectedId(null);
                                                refreshPreview();
                                            },
                                        })
                                    }
                                    onDuplicate={(block) =>
                                        duplicate.mutate(block.id, {
                                            onSuccess: (copy) => {
                                                setSelectedId(copy.id);
                                                refreshPreview();
                                            },
                                        })
                                    }
                                    deleting={remove.isPending}
                                />
                            ) : (
                                <p className="h-full border-s border-line-default bg-surface p-4 t-admin-body text-fg-secondary">{t('homeBuilder.panel.empty')}</p>
                            )}
                        </div>
                    </div>
                )}
            </main>

            <VersionHistoryDialog open={historyOpen} onClose={() => setHistoryOpen(false)} canManage={canManage} />
        </div>
    );
}
