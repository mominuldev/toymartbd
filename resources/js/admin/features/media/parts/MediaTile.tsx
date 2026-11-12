import type { ReactNode } from 'react';
import { CheckIcon, FilePdfIcon, ImageIcon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import { AltChip, type AltState } from './AltChip';
import { UsedBadge } from './UsedBadge';

export type MediaTileState = 'default' | 'selected' | 'ordered';

type MediaTileProps = {
    filename: string;
    /** "1600×1600 · 312 KB" or "PDF · 2 pages · 1.8 MB". */
    meta: string;
    kind: 'image' | 'document' | string;
    /** The 320/480 px rendition; null while processing (a placeholder glyph shows). */
    thumbUrl: string | null;
    /** Caption under the PDF glyph ("PDF · 2 pages"). */
    documentLabel?: string;
    usedCount: number;
    alt: AltState;
    state?: MediaTileState;
    /** The 1-based pick order for State=Ordered (1 = primary image). */
    order?: number;
    /** Extra chips under the alt chip (picker: "Not 1:1 — will crop"). */
    chips?: ReactNode;
    /** The accessible name of the tile's button; defaults to the filename. */
    label?: string;
    disabled?: boolean;
    onClick?: () => void;
    onDoubleClick?: () => void;
};

/**
 * Figma MediaTile 289:2121 — 8 padding (12 at the bottom), 8 gap, radius/sm-plus, 1px
 * border/default on bg/surface. The thumb is aspect-locked square on bg/image, radius/xs-plus,
 * with the Used-in badge top-right. Selected = 2px border/brand + bg/brand-subtle + a checked
 * box; Ordered = a numbered bg/brand disc (picker multi-select). Then the filename
 * (Admin/body-strong), the meta line (Admin/caption) and the alt chip.
 *
 * The whole tile is one button (aria-pressed), so it is reachable and operable by keyboard.
 */
export function MediaTile({
    filename,
    meta,
    kind,
    thumbUrl,
    documentLabel,
    usedCount,
    alt,
    state = 'default',
    order,
    chips,
    label,
    disabled,
    onClick,
    onDoubleClick,
}: MediaTileProps) {
    const { t } = useI18n();
    const picked = state !== 'default';

    return (
        <button
            type="button"
            aria-pressed={picked}
            aria-label={label ?? filename}
            disabled={disabled}
            onClick={onClick}
            onDoubleClick={onDoubleClick}
            className={`group flex w-full min-w-0 flex-col items-start gap-2 rounded-sm-plus text-start transition-colors duration-150 ease-standard disabled:cursor-not-allowed disabled:opacity-60 ${
                picked ? 'border-2 border-line-brand bg-brand-subtle p-[calc(var(--spacing)*2-1px)] pb-[calc(var(--spacing)*3-1px)]' : 'border border-line-default bg-surface p-2 pb-3 hover:border-line-strong'
            }`}
        >
            <span className={`relative block aspect-square w-full overflow-hidden rounded-xs-plus ${kind === 'image' ? 'bg-image' : 'bg-subtle'}`}>
                {kind === 'image' ? (
                    thumbUrl ? (
                        <img src={thumbUrl} alt="" loading="lazy" decoding="async" className="size-full object-cover" />
                    ) : (
                        <span className="grid size-full place-items-center text-fg-tertiary">
                            <ImageIcon size={32} aria-hidden />
                        </span>
                    )
                ) : (
                    <span className="absolute inset-0 flex flex-col items-center justify-center gap-1">
                        <FilePdfIcon size={40} weight="fill" aria-hidden className="text-fg-primary" />
                        {documentLabel && <span className="t-admin-caption text-fg-secondary">{documentLabel}</span>}
                    </span>
                )}
                <UsedBadge count={usedCount} className="absolute top-2 right-1.5" />
                {state === 'selected' && (
                    <span aria-hidden className="absolute top-2 left-2 grid size-5 place-items-center rounded-xs border-(length:--tm-border-width-control) border-brand bg-brand text-fg-on-brand">
                        <CheckIcon size={14} weight="bold" />
                    </span>
                )}
                {state === 'ordered' && order !== undefined && (
                    <span className="absolute top-2 left-2 grid size-6 place-items-center rounded-full bg-brand t-admin-caption-strong text-fg-on-brand tabular" aria-label={t('media.tile.order', { n: order })}>
                        {order}
                    </span>
                )}
            </span>
            <span className="flex w-full min-w-0 flex-col items-start gap-1">
                <span className="w-full truncate t-admin-body-strong text-fg-primary" title={filename}>
                    {filename}
                </span>
                <span className="w-full truncate t-admin-caption text-fg-secondary tabular">{meta}</span>
                <span className="flex flex-wrap gap-1">
                    <AltChip state={alt} />
                    {chips}
                </span>
            </span>
        </button>
    );
}
