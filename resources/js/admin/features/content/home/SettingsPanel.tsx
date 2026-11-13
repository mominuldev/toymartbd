import { useEffect, useRef, useState } from 'react';
import { CopyIcon, TrashIcon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { controlHeight, Field, inputClass } from '@/components/ui/Field';
import { IconButton } from '@/components/ui/IconButton';
import { tabClass } from '@/components/ui/Tabs';
import { endsBeforeStart, formatDhakaDateTime, fromDhakaInput, toDhakaInput } from '@/features/content/shared/dhaka';
import type { BlockPatch, CmsBlock } from './api';
import { ContentForm, StyleForm, type MediaThumb } from './BlockForm';
import { blockTitle, typeLabel, type Content } from './content';
import { SwitchRow, type FieldCtx } from './fields';

export type PanelTab = 'content' | 'style' | 'visibility';

type Props = {
    block: CmsBlock;
    tab: PanelTab;
    onTab: (tab: PanelTab) => void;
    canManage: boolean;
    errors: Record<string, string>;
    media: ReadonlyMap<number, MediaThumb>;
    /** Autosave: called with the changed fields, debounced. */
    onSave: (block: CmsBlock, patch: BlockPatch) => void;
    onDelete: (block: CmsBlock) => void;
    onDuplicate: (block: CmsBlock) => void;
    deleting: boolean;
};

const AUTOSAVE_MS = 700;

/**
 * Figma B · 26 "Settings" column (241:44279): section name (Admin/section) with the delete
 * icon button, AdminTab row Content · Style · Visibility, the fields, and the Schedule card on
 * bg/subtle. Every edit is kept locally and autosaved to the draft after a short pause; leaving
 * the section saves at once. Nothing reaches the storefront until Publish.
 */
export function SettingsPanel({ block, tab, onTab, canManage, errors, media, onSave, onDelete, onDuplicate, deleting }: Props) {
    const { t, locale } = useI18n();
    const [content, setContent] = useState<Content>(block.content as Content);
    const [starts, setStarts] = useState(toDhakaInput(block.starts_at));
    const [ends, setEnds] = useState(toDhakaInput(block.ends_at));
    const [confirming, setConfirming] = useState(false);
    const pending = useRef<BlockPatch>({});
    const timer = useRef<number | undefined>(undefined);
    const latest = useRef({ block, onSave });
    latest.current = { block, onSave };

    const flush = () => {
        window.clearTimeout(timer.current);
        const patch = pending.current;
        pending.current = {};
        if (Object.keys(patch).length > 0) latest.current.onSave(latest.current.block, patch);
    };

    // Save what is pending when the section is left or the panel unmounts.
    useEffect(() => flush, []);

    const queue = (patch: BlockPatch, immediate = false) => {
        pending.current = { ...pending.current, ...patch };
        window.clearTimeout(timer.current);
        if (immediate) flush();
        else timer.current = window.setTimeout(flush, AUTOSAVE_MS);
    };

    const ctx: FieldCtx = { errors, disabled: !canManage };
    const name = block.label || blockTitle(block) || t(typeLabel(block.type));
    const changeContent = (next: Content) => {
        setContent(next);
        queue({ content: next });
    };
    const setSchedule = (nextStarts: string, nextEnds: string) => {
        setStarts(nextStarts);
        setEnds(nextEnds);
        if (!endsBeforeStart(nextStarts, nextEnds)) queue({ starts_at: fromDhakaInput(nextStarts), ends_at: fromDhakaInput(nextEnds) });
    };
    const windowError = endsBeforeStart(starts, ends) ? t('homeBuilder.v.endBeforeStart') : errors.ends_at;

    return (
        <section aria-labelledby="home-settings-title" className="flex h-full flex-col gap-4 overflow-y-auto border-s border-line-default bg-surface p-4">
            <div className="flex items-center gap-2">
                <h2 id="home-settings-title" className="min-w-0 flex-1 truncate t-admin-section text-fg-primary">
                    {t(typeLabel(block.type))}
                </h2>
                {canManage && (
                    <>
                        <IconButton size="sm" aria-label={t('homeBuilder.duplicate')} onClick={() => onDuplicate(block)}>
                            <CopyIcon size={16} aria-hidden />
                        </IconButton>
                        <IconButton size="sm" aria-label={t('homeBuilder.delete')} onClick={() => setConfirming(true)}>
                            <TrashIcon size={16} aria-hidden />
                        </IconButton>
                    </>
                )}
            </div>

            <div role="tablist" aria-label={t('homeBuilder.tabs')} className="flex gap-0.5 border-b border-line-default">
                {(['content', 'style', 'visibility'] as const).map((key) => (
                    <button key={key} type="button" role="tab" id={`home-tab-${key}`} aria-selected={tab === key} aria-controls="home-tabpanel" onClick={() => onTab(key)} className={tabClass(tab === key)}>
                        {t(`homeBuilder.tab.${key}`)}
                    </button>
                ))}
            </div>

            <div id="home-tabpanel" role="tabpanel" aria-labelledby={`home-tab-${tab}`} className="flex flex-col gap-4">
                {tab === 'content' && <ContentForm block={block} content={content} onChange={changeContent} ctx={ctx} media={media} />}
                {tab === 'style' && <StyleForm block={block} content={content} onChange={changeContent} ctx={ctx} />}
                {tab === 'visibility' && (
                    <>
                        <Field label={t('homeBuilder.label')} hint={t('homeBuilder.labelHint')} error={errors.label}>
                            {({ id, describedBy, invalid }) => (
                                <input
                                    id={id}
                                    aria-describedby={describedBy}
                                    aria-invalid={invalid || undefined}
                                    disabled={!canManage}
                                    className={`${controlHeight.md} ${inputClass}`}
                                    defaultValue={block.label ?? ''}
                                    onChange={(e) => queue({ label: e.target.value === '' ? null : e.target.value })}
                                />
                            )}
                        </Field>
                        <SwitchRow label={t('homeBuilder.v.enabled')} checked={block.is_enabled} onChange={(v) => queue({ is_enabled: v }, true)} disabled={!canManage} />
                        <SwitchRow label={t('homeBuilder.v.showEn')} checked={block.show_en} onChange={(v) => queue({ show_en: v }, true)} disabled={!canManage} />
                        <SwitchRow label={t('homeBuilder.v.showBn')} checked={block.show_bn} onChange={(v) => queue({ show_bn: v }, true)} disabled={!canManage} />
                        <SwitchRow label={t('homeBuilder.v.showDesktop')} checked={block.show_desktop} onChange={(v) => queue({ show_desktop: v }, true)} disabled={!canManage} />
                        <SwitchRow label={t('homeBuilder.v.showMobile')} checked={block.show_mobile} onChange={(v) => queue({ show_mobile: v }, true)} disabled={!canManage} />
                        <Field label={t('homeBuilder.v.startsAt')} hint={t('homeBuilder.v.scheduleHint')} error={errors.starts_at}>
                            {({ id, describedBy, invalid }) => (
                                <input id={id} type="datetime-local" aria-describedby={describedBy} aria-invalid={invalid || undefined} disabled={!canManage} className={`${controlHeight.md} ${inputClass}`} value={starts} onChange={(e) => setSchedule(e.target.value, ends)} />
                            )}
                        </Field>
                        <Field label={t('homeBuilder.v.endsAt')} error={windowError}>
                            {({ id, describedBy, invalid }) => (
                                <input id={id} type="datetime-local" aria-describedby={describedBy} aria-invalid={invalid || undefined} disabled={!canManage} className={`${controlHeight.md} ${inputClass}`} value={ends} onChange={(e) => setSchedule(starts, e.target.value)} />
                            )}
                        </Field>
                    </>
                )}
            </div>

            <div className="flex flex-col gap-1.5 rounded-sm bg-subtle p-3">
                <p className="t-admin-overline text-fg-tertiary uppercase">{t('homeBuilder.scheduleCard')}</p>
                <p className="t-admin-body text-fg-primary">{scheduleText(block, t, locale)}</p>
            </div>

            <ConfirmDialog
                open={confirming}
                title={t('homeBuilder.deleteTitle', { name })}
                body={t('homeBuilder.deleteBody')}
                confirmLabel={t('homeBuilder.deleteConfirm')}
                pending={deleting}
                onConfirm={() => {
                    setConfirming(false);
                    onDelete(block);
                }}
                onClose={() => setConfirming(false)}
            />
        </section>
    );
}

function scheduleText(block: CmsBlock, t: ReturnType<typeof useI18n>['t'], locale: string): string {
    const start = block.starts_at ? formatDhakaDateTime(block.starts_at, locale) : null;
    const end = block.ends_at ? formatDhakaDateTime(block.ends_at, locale) : null;
    if (start && end) return t('homeBuilder.scheduleWindow', { start, end });
    if (start) return t('homeBuilder.scheduleFrom', { start });
    if (end) return t('homeBuilder.scheduleUntil', { end });
    return t('homeBuilder.scheduleAlways');
}
