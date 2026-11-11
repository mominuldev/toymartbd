import { forwardRef, type ButtonHTMLAttributes } from 'react';

export type ButtonVariant = 'primary' | 'secondary' | 'outline' | 'ghost' | 'danger';
export type ButtonSize = 'sm' | 'md' | 'lg';

/*
 * Figma AdminButton 184:242 (Theme B). One class string per variant; the disabled
 * look is Figma's own Disabled state (bg/disabled + text/disabled), not an opacity fade.
 *   primary    bg/brand (solid in Theme B, no gradient)
 *   secondary  bg/brand-subtle + text/brand
 *   outline    bg/surface + border/default
 *   ghost      no fill
 *   danger     bg/danger — only in ConfirmDialog or the FormActions destructive slot
 */
const variants: Record<ButtonVariant, string> = {
    primary: 'bg-brand text-fg-on-brand hover:bg-brand-shelf disabled:bg-disabled disabled:text-fg-disabled',
    secondary: 'bg-brand-subtle text-fg-brand hover:bg-brand-subtle-shelf disabled:bg-disabled disabled:text-fg-disabled',
    outline: 'border border-line-default bg-surface text-fg-primary hover:bg-subtle disabled:bg-disabled disabled:text-fg-disabled',
    ghost: 'text-fg-primary hover:bg-subtle disabled:bg-transparent disabled:text-fg-disabled',
    danger: 'bg-danger text-fg-on-danger hover:bg-danger-shelf disabled:bg-disabled disabled:text-fg-disabled',
};

/* sm 32 · md 36 · lg 44 high; icons 16 / 16 / 20 (pass the icon at that size). */
const sizes: Record<ButtonSize, string> = {
    sm: 'h-8 gap-1.5 px-3 t-admin-button-sm',
    md: 'h-9 gap-2 px-3.5 t-admin-button',
    lg: 'h-11 gap-2 px-4.5 t-admin-button',
};

/** Classes for anything that should look like an AdminButton (a router <Link>, a <label>). */
export function buttonClass(variant: ButtonVariant = 'primary', size: ButtonSize = 'md'): string {
    return `inline-flex shrink-0 items-center justify-center rounded-sm whitespace-nowrap transition-colors duration-150 ease-standard disabled:cursor-not-allowed ${variants[variant]} ${sizes[size]}`;
}

export type ButtonProps = ButtonHTMLAttributes<HTMLButtonElement> & { variant?: ButtonVariant; size?: ButtonSize };

/** Figma AdminButton 184:242. Radius 10 (radius/sm), never a pill. */
export const Button = forwardRef<HTMLButtonElement, ButtonProps>(function Button(
    { variant = 'primary', size = 'md', className = '', type = 'button', ...props },
    ref,
) {
    return <button ref={ref} type={type} className={`${buttonClass(variant, size)} ${className}`} {...props} />;
});
