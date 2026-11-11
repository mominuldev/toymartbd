import type { ReactNode } from 'react';

type FormActionsProps = {
    /** Left: the far-left destructive action (behind ConfirmDialog) and/or the save status. */
    start?: ReactNode;
    /** Right: Discard (ghost) · secondary (outline) · Save (primary), in that order. */
    children: ReactNode;
};

/**
 * Figma FormActions 198:1085 — the sticky save bar: 64 high, bg/surface, border/default
 * top edge, e4, 24 side padding, 12 between buttons. Bleeds to the content edges.
 */
export function FormActions({ start, children }: FormActionsProps) {
    return (
        <div className="sticky bottom-0 z-10 -mx-4 -mb-4 flex min-h-16 flex-wrap items-center gap-3 border-t border-line-default bg-surface px-4 py-3.5 shadow-e4 md:-mx-5 md:-mb-10 md:px-5 xl:-mx-6 xl:px-6">
            <div className="flex min-w-0 flex-1 items-center gap-2">{start}</div>
            <div className="flex flex-wrap items-center gap-3">{children}</div>
        </div>
    );
}
