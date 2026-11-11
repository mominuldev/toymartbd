import { forwardRef, type SelectHTMLAttributes } from 'react';
import { CaretDownIcon } from '@phosphor-icons/react';
import { controlHeight } from './Field';

export type SelectProps = Omit<SelectHTMLAttributes<HTMLSelectElement>, 'size'> & { size?: 'sm' | 'md' };

/**
 * Figma AdminSelect 184:369 — a native <select> (the design's own choice at 768, and the
 * accessible default everywhere) with the Figma trigger look: border/default, 16px
 * caret, focus = 2px border/focus, disabled = bg/disabled.
 */
export const Select = forwardRef<HTMLSelectElement, SelectProps>(function Select({ size = 'md', className = '', ...props }, ref) {
    return (
        <div className={`relative ${className}`}>
            <select
                ref={ref}
                className={`${controlHeight[size]} w-full appearance-none rounded-sm border border-line-default bg-surface ps-3 pe-9 t-admin-body text-fg-primary focus:border-line-focus focus:ring-1 focus:ring-inset focus:ring-line-focus aria-invalid:border-line-danger disabled:cursor-not-allowed disabled:bg-disabled disabled:text-fg-disabled`}
                {...props}
            />
            <CaretDownIcon size={16} aria-hidden className="pointer-events-none absolute end-3 top-1/2 -translate-y-1/2 text-fg-primary" />
        </div>
    );
});
