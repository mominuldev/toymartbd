import { forwardRef, type ButtonHTMLAttributes } from 'react';

type Variant = 'primary' | 'secondary' | 'ghost' | 'danger';
type Size = 'sm' | 'md';

const variants: Record<Variant, string> = {
    primary: 'bg-brand text-fg-on-brand hover:bg-brand-shelf',
    secondary: 'border border-line-strong bg-surface text-fg-primary hover:bg-subtle',
    ghost: 'text-fg-secondary hover:bg-subtle hover:text-fg-primary',
    danger: 'bg-danger text-fg-on-danger hover:bg-danger-shelf',
};

const sizes: Record<Size, string> = {
    sm: 'h-control-sm px-3 t-admin-button-sm',
    md: 'h-control-md px-4 t-admin-button',
};

export type ButtonProps = ButtonHTMLAttributes<HTMLButtonElement> & { variant?: Variant; size?: Size };

/** Figma AdminButton 184:242. Solid brand fill in Theme B (no gradient). */
export const Button = forwardRef<HTMLButtonElement, ButtonProps>(function Button(
    { variant = 'primary', size = 'md', className = '', type = 'button', ...props },
    ref,
) {
    return (
        <button
            ref={ref}
            type={type}
            className={`inline-flex shrink-0 items-center justify-center gap-2 rounded-sm transition-colors duration-150 ease-standard disabled:cursor-not-allowed disabled:opacity-60 ${variants[variant]} ${sizes[size]} ${className}`}
            {...props}
        />
    );
});
