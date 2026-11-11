import type { ColorToken } from './generated';

export { colorTokens, resolvedColors } from './generated';
export type { ColorToken } from './generated';

/** data-theme values. Light is also the :root default. */
export const themes = ['light', 'dark', 'admin-light', 'admin-dark'] as const;
export type Theme = (typeof themes)[number];

/**
 * CSS reference for a colour token, for places a utility class cannot reach —
 * Recharts fills and strokes, inline SVG. Follows the active theme at runtime.
 */
export function cssVar(token: ColorToken): string {
    return `var(--tm-${token.replaceAll('/', '-')})`;
}
