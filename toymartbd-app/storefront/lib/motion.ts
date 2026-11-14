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

/**
 * Opacity/transform keyframes through the browser's own Web Animations API (Phase 9 performance:
 * this replaced the `motion` package, ~21 KB of gzipped JS on the listing and product pages, for the
 * two effects the storefront uses). Durations and easing still come from the motion tokens.
 */
export function play(el: HTMLElement, keyframes: Keyframe[], length: MotionDuration, curve: MotionEase): void {
    if (typeof el.animate !== 'function') return;
    el.animate(keyframes, { duration: duration(length) * 1000, easing: `cubic-bezier(${ease(curve).join(',')})`, fill: 'none' });
}

/** Calls `onEnter` once, when at least `amount` of `el` is in the viewport. Returns a stop function. */
export function onceInView(el: Element, onEnter: () => void, amount = 0.15): () => void {
    if (typeof IntersectionObserver === 'undefined') {
        onEnter();
        return () => {};
    }
    const observer = new IntersectionObserver(
        (entries) => {
            if (entries.some((e) => e.isIntersecting)) {
                observer.disconnect();
                onEnter();
            }
        },
        { threshold: amount },
    );
    observer.observe(el);

    return () => observer.disconnect();
}
