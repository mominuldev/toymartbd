import { useState, type DragEvent, type KeyboardEvent } from 'react';
import {
    ArticleIcon,
    ChatsCircleIcon,
    ClockIcon,
    DotsSixVerticalIcon,
    EyeIcon,
    EyeSlashIcon,
    ImageIcon,
    LightningIcon,
    LayoutIcon,
    PackageIcon,
    PlusIcon,
    QuestionIcon,
    ShieldCheckIcon,
    SquaresFourIcon,
    StorefrontIcon,
    TextAlignLeftIcon,
    UsersThreeIcon,
    type Icon,
} from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import type { BlockType, CmsBlock } from './api';
import { BLOCK_TYPES, blockTitle, typeLabel } from './content';
import { blockSummary, isDormant } from './summary';

/** Type glyphs (Phosphor, regular — Figma uses icon/layout, icon/package, icon/clock). */
export const typeIcons: Record<BlockType, Icon> = {
    hero: LayoutIcon,
    category_tiles: SquaresFourIcon,
    age_tiles: UsersThreeIcon,
    product_rail: PackageIcon,
    flash_sale: LightningIcon,
    brand_strip: StorefrontIcon,
    usp_band: ShieldCheckIcon,
    promo_split: ImageIcon,
    testimonials: ChatsCircleIcon,
    blog_teaser: ArticleIcon,
    rich_text: TextAlignLeftIcon,
    faq: QuestionIcon,
};

type Props = {
    blocks: CmsBlock[];
    selectedId: number | null;
    canManage: boolean;
    onSelect: (id: number) => void;
    onReorder: (ids: number[]) => void;
    onToggle: (block: CmsBlock) => void;
    onAdd: (type: BlockType) => void;
    adding: boolean;
};

/**
 * Figma B · 26 "Sections" column (241:44127) — PageBuilderSection rows (201:1716): drag handle,
 * type icon on bg/subtle, name + summary, visibility eye. Selected: bg/brand-subtle with a 2px
 * bg/brand border. A dormant row (off, not yet started, ended) is tertiary with eye-slash and
 * the clock glyph. Then the dashed "Add section" library.
 *
 * Reorder by mouse (HTML5 drag and drop) or keyboard on the handle: Space lifts, ↑/↓ move,
 * Space drops (saved), Escape cancels — each step announced in a polite live region.
 */
export function SectionList({ blocks, selectedId, canManage, onSelect, onReorder, onToggle, onAdd, adding }: Props) {
    const { t, locale } = useI18n();
    const [lifted, setLifted] = useState<{ id: number; from: number; order: number[] } | null>(null);
    const [dragging, setDragging] = useState<number | null>(null);
    const [announcement, setAnnouncement] = useState('');

    const order = lifted?.order ?? blocks.map((b) => b.id);
    const byId = new Map(blocks.map((b) => [b.id, b]));
    const nameOf = (block: CmsBlock) => block.label || blockTitle(block) || t(typeLabel(block.type));

    function onHandleKey(event: KeyboardEvent<HTMLButtonElement>, block: CmsBlock) {
        if (!canManage) return;
        const total = order.length;
        const index = order.indexOf(block.id);
        const name = nameOf(block);

        if (event.key === ' ' || event.key === 'Enter') {
            event.preventDefault();
            if (!lifted) {
                setLifted({ id: block.id, from: index, order });
                setAnnouncement(t('homeBuilder.lifted', { name, position: index + 1, total }));
            } else {
                const changed = lifted.order.join() !== blocks.map((b) => b.id).join();
                setLifted(null);
                setAnnouncement(t('homeBuilder.dropped', { name, position: index + 1, total }));
                if (changed) onReorder(lifted.order);
            }
            return;
        }
        if (!lifted) return;
        if (event.key === 'Escape') {
            event.preventDefault();
            setLifted(null);
            setAnnouncement(t('homeBuilder.cancelled', { name, position: lifted.from + 1 }));
            return;
        }
        const step = event.key === 'ArrowUp' ? -1 : event.key === 'ArrowDown' ? 1 : 0;
        if (step === 0) return;
        event.preventDefault();
        const to = index + step;
        if (to < 0 || to >= total) return;
        const next = [...order];
        next.splice(index, 1);
        next.splice(to, 0, block.id);
        setLifted({ ...lifted, order: next });
        setAnnouncement(t('homeBuilder.moved', { name, position: to + 1, total }));
    }

    function onDrop(event: DragEvent<HTMLLIElement>, targetId: number) {
        event.preventDefault();
        if (dragging === null || dragging === targetId) return setDragging(null);
        const next = order.filter((id) => id !== dragging);
        next.splice(next.indexOf(targetId) + (order.indexOf(dragging) < order.indexOf(targetId) ? 1 : 0), 0, dragging);
        setDragging(null);
        onReorder(next);
    }

    return (
        <div className="flex h-full flex-col gap-2 overflow-y-auto border-e border-line-default bg-surface p-4">
            <h2 id="home-sections" className="t-admin-overline text-fg-tertiary uppercase">
                {t('homeBuilder.sections', { count: blocks.length })}
            </h2>
            <p id="home-sections-hint" className="sr-only">
                {t('homeBuilder.reorderHint')}
            </p>
            <p aria-live="polite" className="sr-only">
                {announcement}
            </p>
            {blocks.length === 0 && <p className="t-admin-body text-fg-secondary">{t('homeBuilder.empty')}</p>}
            <ol aria-labelledby="home-sections" className="flex flex-col gap-2">
                {order.map((id) => {
                    const block = byId.get(id);
                    if (!block) return null;
                    const selected = block.id === selectedId;
                    const dormant = isDormant(block);
                    const TypeIcon = dormant && (block.starts_at || block.ends_at) ? ClockIcon : typeIcons[block.type];
                    const name = nameOf(block);
                    return (
                        <li
                            key={id}
                            draggable={canManage}
                            onDragStart={() => setDragging(id)}
                            onDragOver={(e) => canManage && e.preventDefault()}
                            onDrop={(e) => onDrop(e, id)}
                            onDragEnd={() => setDragging(null)}
                            className={`flex items-center gap-2.5 rounded-sm py-2.5 ps-2 pe-2.5 ${
                                selected ? 'border-2 border-brand bg-brand-subtle' : 'border border-line-default bg-surface'
                            } ${lifted?.id === id || dragging === id ? 'shadow-e3' : ''}`}
                        >
                            <button
                                type="button"
                                aria-label={t('homeBuilder.dragHandle', { name })}
                                aria-describedby="home-sections-hint"
                                aria-pressed={lifted?.id === id}
                                disabled={!canManage}
                                onKeyDown={(e) => onHandleKey(e, block)}
                                className="grid shrink-0 cursor-grab place-items-center rounded-xs text-fg-tertiary focus-visible:shadow-focus disabled:cursor-default"
                            >
                                <DotsSixVerticalIcon size={16} aria-hidden />
                            </button>
                            <span aria-hidden className="grid shrink-0 place-items-center rounded-xs bg-subtle p-1.5 text-fg-primary">
                                <TypeIcon size={18} />
                            </span>
                            <button type="button" aria-current={selected || undefined} onClick={() => onSelect(block.id)} className="flex min-w-0 flex-1 flex-col items-start text-start">
                                <span className={`w-full truncate t-admin-body-strong ${dormant ? 'text-fg-tertiary' : 'text-fg-primary'}`}>{name}</span>
                                <span className="w-full truncate t-admin-caption text-fg-tertiary">{blockSummary(block, t, locale)}</span>
                            </button>
                            <button
                                type="button"
                                aria-label={block.is_enabled ? t('homeBuilder.hide', { name }) : t('homeBuilder.show', { name })}
                                aria-pressed={!block.is_enabled}
                                disabled={!canManage}
                                onClick={() => onToggle(block)}
                                className="grid shrink-0 place-items-center rounded-xs text-fg-primary hover:text-fg-brand disabled:text-fg-disabled"
                            >
                                {dormant ? <EyeSlashIcon size={16} aria-hidden /> : <EyeIcon size={16} aria-hidden />}
                            </button>
                        </li>
                    );
                })}
            </ol>
            {canManage && (
                <div className="flex flex-col gap-2 rounded-sm border border-dashed border-line-strong p-3">
                    <h3 className="t-admin-body-strong text-fg-primary">{t('homeBuilder.addSection')}</h3>
                    <ul className="flex flex-wrap gap-1.5">
                        {BLOCK_TYPES.map((type) => (
                            <li key={type}>
                                <button
                                    type="button"
                                    disabled={adding}
                                    aria-label={t('homeBuilder.addType', { type: t(typeLabel(type)) })}
                                    onClick={() => onAdd(type)}
                                    className="flex items-center gap-1 rounded-xs border border-line-default bg-subtle px-2 py-0.75 t-admin-caption-strong text-fg-primary hover:border-line-strong disabled:text-fg-disabled"
                                >
                                    <PlusIcon size={14} aria-hidden />
                                    {t(typeLabel(type))}
                                </button>
                            </li>
                        ))}
                    </ul>
                </div>
            )}
        </div>
    );
}
