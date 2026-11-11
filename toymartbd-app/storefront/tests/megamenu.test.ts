import { describe, expect, it } from 'vitest';

import { barKey, panelKey } from '@/lib/megamenu-keys';

// Storefront spec §A4.4 keyboard contract.

describe('mega menu: L1 bar', () => {
    const at = (index: number, open = false, hasPanel = true) => ({ index, count: 4, open, hasPanel });

    it('ArrowRight / ArrowLeft move between L1 items and wrap', () => {
        expect(barKey('ArrowRight', at(0))).toEqual({ type: 'focusItem', index: 1, open: false });
        expect(barKey('ArrowRight', at(3))).toEqual({ type: 'focusItem', index: 0, open: false });
        expect(barKey('ArrowLeft', at(0))).toEqual({ type: 'focusItem', index: 3, open: false });
    });

    it('keeps an open panel open while moving (content swaps, no close/reopen)', () => {
        expect(barKey('ArrowRight', at(1, true))).toEqual({ type: 'focusItem', index: 2, open: true });
    });

    it.each(['ArrowDown', 'Enter', ' '])('%s opens the panel and targets its first link', (key) => {
        expect(barKey(key, at(2))).toEqual({ type: 'openPanel', index: 2 });
    });

    it('Enter on a childless L1 item is left to the browser (follows the link)', () => {
        expect(barKey('Enter', at(2, false, false))).toBeNull();
        expect(barKey('ArrowDown', at(2, false, false))).toBeNull();
    });

    it('Home / End jump to the first / last item', () => {
        expect(barKey('Home', at(2))).toEqual({ type: 'focusItem', index: 0, open: false });
        expect(barKey('End', at(0))).toEqual({ type: 'focusItem', index: 3, open: false });
    });

    it('Escape closes only when something is open; Tab is never intercepted', () => {
        expect(barKey('Escape', at(1, true))).toEqual({ type: 'close', index: 1 });
        expect(barKey('Escape', at(1, false))).toBeNull();
        expect(barKey('Tab', at(1, true))).toBeNull();
    });

    it('does nothing with an empty bar', () => {
        expect(barKey('ArrowRight', { index: 0, count: 0, open: false, hasPanel: false })).toBeNull();
    });
});

describe('mega menu: inside a panel', () => {
    const columns = [5, 3, 4];

    it('ArrowDown / ArrowUp move within a column and wrap to the same column', () => {
        expect(panelKey('ArrowDown', { column: 0, row: 0 }, columns)).toEqual({ type: 'focusLink', column: 0, row: 1 });
        expect(panelKey('ArrowDown', { column: 1, row: 2 }, columns)).toEqual({ type: 'focusLink', column: 1, row: 0 });
        expect(panelKey('ArrowUp', { column: 2, row: 0 }, columns)).toEqual({ type: 'focusLink', column: 2, row: 3 });
    });

    it('ArrowRight / ArrowLeft keep the vertical index, clamped to a shorter column', () => {
        expect(panelKey('ArrowRight', { column: 0, row: 1 }, columns)).toEqual({ type: 'focusLink', column: 1, row: 1 });
        expect(panelKey('ArrowRight', { column: 0, row: 4 }, columns)).toEqual({ type: 'focusLink', column: 1, row: 2 });
        expect(panelKey('ArrowLeft', { column: 2, row: 3 }, columns)).toEqual({ type: 'focusLink', column: 1, row: 2 });
    });

    it('stops at the outer columns (the promo CTA slot does not exist yet)', () => {
        expect(panelKey('ArrowRight', { column: 2, row: 0 }, columns)).toBeNull();
        expect(panelKey('ArrowLeft', { column: 0, row: 0 }, columns)).toBeNull();
    });

    it('Home / End go to the first / last link of the whole panel', () => {
        expect(panelKey('Home', { column: 2, row: 2 }, columns)).toEqual({ type: 'focusLink', column: 0, row: 0 });
        expect(panelKey('End', { column: 0, row: 0 }, columns)).toEqual({ type: 'focusLink', column: 2, row: 3 });
    });

    it('Escape closes (the component returns focus to the L1 item)', () => {
        expect(panelKey('Escape', { column: 1, row: 1 }, columns)).toEqual({ type: 'close' });
    });

    it('ignores Tab (native DOM order) and other keys', () => {
        expect(panelKey('Tab', { column: 0, row: 0 }, columns)).toBeNull();
        expect(panelKey('a', { column: 0, row: 0 }, columns)).toBeNull();
    });

    it('a single-column panel wraps vertically and goes nowhere sideways', () => {
        expect(panelKey('ArrowUp', { column: 0, row: 0 }, [2])).toEqual({ type: 'focusLink', column: 0, row: 1 });
        expect(panelKey('ArrowRight', { column: 0, row: 0 }, [2])).toBeNull();
    });

    it('survives an empty panel', () => {
        expect(panelKey('ArrowDown', { column: 0, row: 0 }, [])).toBeNull();
        expect(panelKey('Escape', { column: 0, row: 0 }, [])).toEqual({ type: 'close' });
    });
});
