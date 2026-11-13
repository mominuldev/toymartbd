import { forwardRef, type InputHTMLAttributes, type ReactNode } from 'react';
import { controlHeight, inputClass } from './Field';

export type InputProps = Omit<InputHTMLAttributes<HTMLInputElement>, 'size' | 'prefix'> & {
    size?: 'sm' | 'md';
    /** 16px leading icon (Phosphor, regular). */
    leading?: ReactNode;
    /** Inline prefix such as ৳ for money. */
    prefix?: ReactNode;
    /** Inline unit suffix (g, %, pcs). */
    suffix?: ReactNode;
};

/**
 * Figma AdminInput 184:338. A bare <input> when there is no adornment; otherwise the
 * box moves to a wrapper and the focus/error border follows the input inside it.
 */
export const Input = forwardRef<HTMLInputElement, InputProps>(function Input(
    { size = 'md', leading, prefix, suffix, className = '', ...props },
    ref,
) {
    if (!leading && !prefix && !suffix) {
        return <input ref={ref} className={`${controlHeight[size]} ${inputClass} ${className}`} {...props} />;
    }

    return (
        <div
            className={`flex ${controlHeight[size]} [:where(&)]:w-full items-center gap-2 rounded-sm border border-line-default bg-surface px-3 text-fg-tertiary focus-within:border-line-focus focus-within:ring-1 focus-within:ring-inset focus-within:ring-line-focus has-aria-invalid:border-line-danger has-disabled:bg-disabled ${className}`}
        >
            {leading && <span className="flex shrink-0 text-fg-secondary">{leading}</span>}
            {prefix && <span className="shrink-0 t-admin-num text-fg-tertiary">{prefix}</span>}
            <input
                ref={ref}
                className="h-full min-w-0 flex-1 bg-transparent t-admin-body text-fg-primary outline-none placeholder:text-fg-tertiary focus-visible:shadow-none disabled:cursor-not-allowed disabled:text-fg-disabled"
                {...props}
            />
            {suffix && <span className="shrink-0 t-admin-caption text-fg-tertiary">{suffix}</span>}
        </div>
    );
});
