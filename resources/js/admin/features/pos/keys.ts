/**
 * Keyboard key names the register shows in its hints (`<kbd>`). They are the keys printed on
 * the keyboard, the same in both locales — not translatable copy.
 */
export const KEY = { f8: 'F8', f9: 'F9', f12: 'F12', enter: 'Enter' } as const;

/** "F1"…"F5" for the n-th (0-based) payment method. */
export const fKey = (index: number): string => `F${index + 1}`;
