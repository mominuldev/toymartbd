import { forwardRef, type ButtonHTMLAttributes } from 'react';

export type IconButtonVariant = 'outline' | 'ghost' | 'primary';
export type IconButtonSize = 'sm' | 'md';

const variants: Record<IconButtonVariant, string> = {
    outline: 'border border-line-default bg-surface text-fg-primary hover:bg-subtle',
    ghost: 'text-fg-primary hover:bg-subtle',
    primary: 'bg-brand text-fg-on-brand hover:bg-brand-shelf',
};

/* sm 32 with a 16 icon · md 36 with an 18 icon. */
const sizes: Record<IconButtonSize, string> = { sm: 'size-8', md: 'size-9' };

/** Classes for a link or label that should look like an AdminIconButton. */
export function iconButtonClass(variant: IconButtonVariant = 'ghost', size: IconButtonSize = 'md'): string {
    return `inline-grid shrink-0 place-items-center rounded-sm transition-colors duration-150 ease-standard disabled:cursor-not-allowed disabled:text-fg-disabled disabled:hover:bg-transparent ${variants[variant]} ${sizes[size]}`;
}

export type IconButtonProps = Omit<ButtonHTMLAttributes<HTMLButtonElement>, 'aria-label'> & {
    /** Icon-only, so the name is required (Figma: "Always needs an aria-label in code"). */
    'aria-label': string;
    variant?: IconButtonVariant;
    size?: IconButtonSize;
};

/** Figma AdminIconButton 184:267. Outline for toolbars, Ghost inside cards and rows. */
export const IconButton = forwardRef<HTMLButtonElement, IconButtonProps>(function IconButton(
    { variant = 'ghost', size = 'md', className = '', type = 'button', ...props },
    ref,
) {
    return <button ref={ref} type={type} className={`${iconButtonClass(variant, size)} ${className}`} {...props} />;
});
