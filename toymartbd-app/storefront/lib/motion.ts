// Motion tokens (UI-UX-DESIGN-SPEC §3.3) read from the design-token CSS variables, so animation
// timing is never hard-coded in a component (rule 26). motion.dev takes seconds and a bezier array.
// Client-only: call from effects or event handlers.

export type MotionDuration = 'fast' | 'base' | 'slow';
export type MotionEase = 'standard' | 'decelerate';

function cssVar(name: string): string {
    return getComputedStyle(document.documentElement).getPropertyValue(name).trim();
}

/** "200ms" / "0.2s" → seconds. */
export function duration(name: MotionDuration): number {
    const raw = cssVar(`--tm-duration-${name}`);
    const value = Number.parseFloat(raw);
    if (!Number.isFinite(value)) return 0;
    return raw.endsWith('ms') ? value / 1000 : value;
}

/** "cubic-bezier(.2,0,0,1)" → [0.2, 0, 0, 1]. */
export function ease(name: MotionEase): [number, number, number, number] {
    const match = /cubic-bezier\(([^)]+)\)/.exec(cssVar(`--tm-ease-${name}`));
    const parts = match?.[1]?.split(',').map((p) => Number.parseFloat(p)) ?? [];
    return parts.length === 4 && parts.every(Number.isFinite) ? (parts as [number, number, number, number]) : [0.2, 0, 0, 1];
}

/** A spacing token in px, for transform offsets (`space-3` → 12). */
export function space(step: string): number {
    return Number.parseFloat(cssVar(`--tm-space-${step}`)) || 0;
}

export function prefersReducedMotion(): boolean {
    return typeof window !== 'undefined' && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
}
