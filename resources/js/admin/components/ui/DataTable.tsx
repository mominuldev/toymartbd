import type { ReactNode } from 'react';

/*
 * Figma DataTable 192:532 (Theme B Products table 241:37281). Shared classes rather than a
 * column-config component, so each list keeps a real <table> with its own cells.
 *   card    radius/md, border/default, bg/surface, e1, clipped
 *   header  bg/subtle, 40 high, Admin/overline in text/secondary, 12 side padding
 *   row     60 high, border/default between rows, hover bg/subtle
 *   cell    12×8 padding, text/primary (Admin/body per cell)
 *   footer  border/default top, 12×16 padding: "Showing …" caption + pager buttons (outline sm)
 */
export const dataTable = {
    card: 'overflow-hidden rounded-md border border-line-default bg-surface shadow-e1',
    table: 'w-full border-collapse',
    head: 'bg-subtle',
    th: 'h-10 px-3 [:where(&)]:text-start align-middle t-admin-overline whitespace-nowrap text-fg-secondary',
    row: 'border-t border-line-default hover:bg-subtle',
    /* No type class here: add t-admin-body (or mono/caption) per cell, so a Bangla twin of
       one style never overrides another style on the same element. */
    td: 'h-15 px-3 py-2 align-middle text-fg-primary',
    footer: 'flex flex-wrap items-center justify-between gap-3 border-t border-line-default px-4 py-3',
} as const;

/** The table card's footer row: a caption on the left, pager or "load more" on the right. */
export function DataTableFooter({ summary, children }: { summary: ReactNode; children?: ReactNode }) {
    return (
        <div className={dataTable.footer}>
            <p className="t-admin-caption text-fg-secondary tabular" aria-live="polite">
                {summary}
            </p>
            {children && <div className="flex items-center gap-2">{children}</div>}
        </div>
    );
}
