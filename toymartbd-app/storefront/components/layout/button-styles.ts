// Figma Button 29:316 ("candy" treatment) as class strings, for links and buttons alike.
// Primary: Gradient/Button-primary pill, white label. Secondary: flat brand-subtle pill, navy
// label, coral icon. Hover/pressed are a navy scrim at 8% / 12% over the face (the _scrim layer);
// pressed also scales to 0.98. Width hugs the label with a 128 floor so Bangla never clips.
// Primary carries the Gloss/Button effect (1.5px white inset top gloss, no drop shadow).
const base =
    'relative isolate inline-flex min-w-32 items-center justify-center rounded-full whitespace-nowrap transition-transform duration-(--tm-duration-fast) ease-standard before:absolute before:inset-0 before:-z-10 before:rounded-full before:transition-colors hover:before:bg-fg-primary/8 active:scale-98 active:before:bg-fg-primary/12';

const sizes = {
    /** 52 tall, 24 icon (Size=Large). */
    lg: 't-button h-control-lg gap-3 px-6',
    /** 44 tall, 20 icon (Size=Medium). */
    md: 't-button h-control-md gap-3 px-5',
} as const;

const variants = {
    primary: 'bg-(image:--tm-gradient-button-primary) text-fg-on-brand shadow-gloss-button',
    secondary: 'bg-brand-subtle text-fg-primary [&>svg]:text-fg-brand',
    /** Size-matched to the others: the 2px border sits inside the same height (Figma Variant=Outline). */
    outline: 'border-(length:--tm-border-width-strong) border-line-brand-subtle bg-surface text-fg-brand',
    /** Text only (Figma Variant=Ghost). */
    ghost: 'bg-transparent text-fg-brand',
} as const;

export function buttonClass(variant: keyof typeof variants = 'primary', size: keyof typeof sizes = 'lg'): string {
    return `${base} ${sizes[size]} ${variants[variant]}`;
}

export const buttonIconClass = { lg: 'size-icon-lg', md: 'size-icon-md' } as const;
