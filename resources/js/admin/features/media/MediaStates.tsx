import type { ComponentType } from 'react';
import { CloudArrowUpIcon, ImagesIcon, MagnifyingGlassIcon, ShieldCheckIcon, TranslateIcon, UploadSimpleIcon, XIcon, ImageIcon, type IconProps } from '@phosphor-icons/react';
import { Button } from '@/components/ui/Button';
import { useI18n } from '@/i18n/I18nProvider';
import { formatCount } from './parts/format';

/**
 * Figma 303:57286 — the empty library (AdminEmptyState NoDataYet): an images glyph on
 * bg/brand-subtle, the title, what lives here and the alt rule, Upload files (Primary) and
 * Photo guide (Ghost), then the three facts on bg/subtle.
 */
export function EmptyLibrary({ onUpload, canManage }: { onUpload: () => void; canManage: boolean }) {
    const { t } = useI18n();
    const facts: { icon: ComponentType<IconProps>; title: string; hint: string }[] = [
        { icon: ImageIcon, title: t('media.empty.fact1'), hint: t('media.empty.fact1Hint') },
        { icon: TranslateIcon, title: t('media.empty.fact2'), hint: t('media.empty.fact2Hint') },
        { icon: ShieldCheckIcon, title: t('media.empty.fact3'), hint: t('media.empty.fact3Hint') },
    ];
    return (
        <div className="flex flex-col items-center gap-2.5 rounded-md border border-line-default bg-surface px-6 py-16 text-center">
            <span aria-hidden className="grid size-14 place-items-center rounded-full bg-brand-subtle">
                <ImagesIcon size={28} className="text-fg-brand" />
            </span>
            <h2 className="t-admin-section text-fg-primary">{t('media.empty.title')}</h2>
            <p className="max-w-sm t-admin-body text-fg-secondary">{t('media.empty.body')}</p>
            {canManage && (
                <div className="flex flex-wrap justify-center gap-2 pt-1">
                    <Button onClick={onUpload}>
                        <UploadSimpleIcon size={16} aria-hidden />
                        {t('media.upload')}
                    </Button>
                </div>
            )}
            <ul className="mt-8 grid w-full max-w-[34.5rem] grid-cols-1 gap-3 text-start sm:grid-cols-3">
                {facts.map(({ icon: Icon, title, hint }) => (
                    <li key={title} className="flex items-start gap-3 rounded-sm bg-subtle px-3 py-3">
                        <Icon size={16} aria-hidden className="mt-0.5 shrink-0 text-fg-secondary" />
                        <span className="flex flex-col">
                            <span className="t-admin-caption-strong text-fg-primary">{title}</span>
                            <span className="t-admin-caption text-fg-secondary">{hint}</span>
                        </span>
                    </li>
                ))}
            </ul>
        </div>
    );
}

export type FilterChip = { key: string; label: string; onRemove: () => void };

/**
 * Figma 303:57572 — no results: the active filters as removable chips + Clear all, then a
 * card that echoes the search and scope and offers to widen it.
 */
export function NoResults({
    query,
    chips,
    scope,
    total,
    allCount,
    onSearchAll,
    onClear,
}: {
    query: string;
    chips: FilterChip[];
    scope: string;
    total: number;
    allCount: number | undefined;
    onSearchAll: (() => void) | null;
    onClear: () => void;
}) {
    const { t } = useI18n();
    return (
        <div className="flex flex-col gap-4">
            {chips.length > 0 && (
                <div className="flex flex-wrap items-center gap-2" aria-label={t('media.filters')} role="group">
                    <span className="t-admin-caption text-fg-secondary">{t('media.filters')}</span>
                    {chips.map((chip) => (
                        <span key={chip.key} className="inline-flex h-7 items-center gap-1 rounded-xs border border-line-default bg-surface ps-2 pe-1 t-admin-caption-strong text-fg-primary">
                            {chip.label}
                            <button type="button" onClick={chip.onRemove} aria-label={t('media.filter.remove', { name: chip.label })} className="grid size-5 place-items-center rounded-xs hover:bg-subtle">
                                <XIcon size={12} aria-hidden />
                            </button>
                        </span>
                    ))}
                    <Button variant="ghost" size="sm" onClick={onClear}>
                        {t('media.filter.clearAll')}
                    </Button>
                </div>
            )}
            <div className="flex flex-col items-center gap-2.5 rounded-md border border-line-default bg-surface px-6 py-12 text-center">
                <span aria-hidden className="grid size-14 place-items-center rounded-full bg-subtle">
                    <MagnifyingGlassIcon size={28} className="text-fg-secondary" />
                </span>
                <h2 className="t-admin-section text-fg-primary">{query ? t('media.noResults.title', { query }) : t('media.noResults.titleFiltered')}</h2>
                <p className="max-w-md t-admin-body text-fg-secondary">{t('media.noResults.body', { scope, total: formatCount(total) })}</p>
                <div className="flex flex-wrap justify-center gap-2">
                    {onSearchAll && allCount !== undefined && (
                        <Button variant="outline" onClick={onSearchAll}>
                            {t('media.noResults.searchAll', { count: formatCount(allCount) })}
                        </Button>
                    )}
                    <Button variant="ghost" onClick={onClear}>
                        {t('media.noResults.clear')}
                    </Button>
                </div>
            </div>
        </div>
    );
}

/** Figma 303:54740 — the drag-and-drop overlay: a dashed border/brand frame on bg/brand-subtle. */
export function DropOverlay({ count, folder }: { count: number; folder: string }) {
    const { t } = useI18n();
    return (
        <div
            aria-live="polite"
            className="pointer-events-none absolute inset-0 z-30 flex flex-col items-center justify-center gap-2 rounded-md border-2 border-dashed border-line-brand bg-brand-subtle px-6 text-center"
        >
            <CloudArrowUpIcon size={40} weight="fill" aria-hidden className="text-fg-brand" />
            <p className="t-admin-section text-fg-primary">{count > 0 ? t('media.drop.title', { count, folder }) : t('media.drop.titleAny', { folder })}</p>
            <p className="t-admin-body text-fg-primary">{t('media.drop.limits')}</p>
            <p className="max-w-md t-admin-caption text-fg-secondary">{t('media.drop.note')}</p>
        </div>
    );
}
