// Pure keyboard model of the mega menu — storefront spec §A4.4. The component maps a keydown to
// one of these decisions and only then touches the DOM, so the whole contract is unit-tested
// here (tests/megamenu.test.ts).
//
//  Bar (L1):    ArrowRight/Left move between items (wrapping); an open panel stays open and swaps
//               to the new item. ArrowDown/Enter/Space open the panel and focus its first link.
//               Home/End jump to the first/last item. Escape closes. Tab is native.
//  Panel:       ArrowDown/Up move within a column, wrapping to that column's other end.
//               ArrowRight/Left jump to the same row of the adjacent column (clamped to its length).
//               From the last column ArrowRight would reach the promo CTA; there is no promo slot
//               until the CMS exists, so it stays put. Home/End: first/last link of the panel.
//               Escape closes and returns focus to the L1 item. Tab is native (DOM order).

export type BarAction =
    | { type: 'focusItem'; index: number; open: boolean }
    | { type: 'openPanel'; index: number }
    | { type: 'close'; index: number }
    | null;

export type PanelAction = { type: 'focusLink'; column: number; row: number } | { type: 'close' } | null;

export interface BarState {
    index: number;
    count: number;
    /** Whether a panel is currently open. */
    open: boolean;
    /** Whether the focused item has a panel at all (a childless L1 is a plain link). */
    hasPanel: boolean;
}

export function barKey(key: string, { index, count, open, hasPanel }: BarState): BarAction {
    if (count === 0) return null;
    switch (key) {
        case 'ArrowRight':
            return { type: 'focusItem', index: (index + 1) % count, open };
        case 'ArrowLeft':
            return { type: 'focusItem', index: (index - 1 + count) % count, open };
        case 'Home':
            return { type: 'focusItem', index: 0, open };
        case 'End':
            return { type: 'focusItem', index: count - 1, open };
        case 'ArrowDown':
        case 'Enter':
        case ' ':
            // Enter on a childless item keeps its native meaning: follow the link.
            return hasPanel ? { type: 'openPanel', index } : null;
        case 'Escape':
            return open ? { type: 'close', index } : null;
        default:
            return null;
    }
}

export interface PanelPosition {
    column: number;
    row: number;
}

/** `columns[i]` is the number of focusable links in column i. */
export function panelKey(key: string, { column, row }: PanelPosition, columns: readonly number[]): PanelAction {
    const sizes = columns.filter((n) => n > 0);
    if (sizes.length === 0) return key === 'Escape' ? { type: 'close' } : null;
    const col = Math.min(Math.max(column, 0), sizes.length - 1);
    const size = sizes[col]!;
    const at = (c: number, r: number): PanelAction => ({ type: 'focusLink', column: c, row: Math.min(r, sizes[c]! - 1) });

    switch (key) {
        case 'ArrowDown':
            return at(col, (row + 1) % size);
        case 'ArrowUp':
            return at(col, (row - 1 + size) % size);
        case 'ArrowRight':
            return col < sizes.length - 1 ? at(col + 1, row) : null; // promo CTA: not built yet
        case 'ArrowLeft':
            return col > 0 ? at(col - 1, row) : null;
        case 'Home':
            return at(0, 0);
        case 'End':
            return at(sizes.length - 1, sizes[sizes.length - 1]! - 1);
        case 'Escape':
            return { type: 'close' };
        default:
            return null;
    }
}

/** Hover-intent timings from §A4.2. */
export const OPEN_DELAY_MS = 120;
export const CLOSE_DELAY_MS = 180;
