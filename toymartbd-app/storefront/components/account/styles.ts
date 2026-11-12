// Class strings for the account screens (Figma page 03 §08 Account, pages 05/06). Small buttons are the
// Button Size=Small (36 tall, 14 Fredoka label): Primary is the candy gradient, Outline is white with a
// 2px soft-pink border and coral label (Figma Button 29:316).

const small =
    'relative isolate inline-flex h-9 min-w-24 items-center justify-center gap-2 rounded-full px-4 t-button-small whitespace-nowrap transition-transform duration-(--tm-duration-fast) ease-standard before:absolute before:inset-0 before:-z-10 before:rounded-full before:transition-colors hover:before:bg-fg-primary/8 active:scale-98';

export const smallButton = {
    primary: `${small} bg-(image:--tm-gradient-button-primary) text-fg-on-brand shadow-gloss-button`,
    outline: `${small} border-2 border-line-brand-subtle bg-surface text-fg-brand`,
} as const;

/** Card: white surface, hairline border, radius sm, 16 inset. */
export const card = 'rounded-sm border border-line-default bg-surface p-4';
