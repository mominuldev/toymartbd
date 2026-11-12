import type { ReactNode, SelectHTMLAttributes } from 'react';

import { Icon } from '@/components/icons/Icon';

// Form field parts for the cart and checkout (Figma Input / Select, checkout 131:2601): 44 tall
// field (radius md, 12 inset), 1px strong border, danger border + message when invalid, helper caption underneath.

export const inputClass =
    't-body h-11 w-full rounded-md border bg-surface px-3 text-fg-primary placeholder:text-fg-tertiary aria-[invalid=true]:border-line-danger border-line-strong disabled:bg-subtle disabled:text-fg-disabled';

export const textareaClass =
    't-body min-h-17 w-full rounded-md border border-line-strong bg-surface px-3 py-2.5 text-fg-primary placeholder:text-fg-tertiary aria-[invalid=true]:border-line-danger';

export function SelectBox({ children, ...props }: SelectHTMLAttributes<HTMLSelectElement> & { children: ReactNode }) {
    return (
        <span className="relative block">
            <select {...props} className={`${inputClass} appearance-none pe-10`}>
                {children}
            </select>
            <Icon name="caretDown" className="pointer-events-none absolute end-4 top-1/2 size-icon-sm -translate-y-1/2 text-fg-primary" />
        </span>
    );
}

interface FieldProps {
    id: string;
    label: string;
    required?: boolean;
    requiredLabel?: string;
    help?: string | null;
    error?: string | null;
    children: ReactNode;
    /** Right-aligned caption under the field (the address character count). */
    aside?: ReactNode;
}

/** Label · control · helper or error. The control gets `aria-describedby={id}-help` itself. */
export function Field({ id, label, required = false, requiredLabel, help, error, children, aside }: FieldProps) {
    return (
        <div className="flex flex-col gap-2">
            <label htmlFor={id} className="t-field-label text-fg-primary">
                {label}
                {required ? (
                    <span className="text-feedback-danger" aria-hidden="true">
                        {' '}*
                    </span>
                ) : null}
                {required && requiredLabel ? <span className="sr-only"> ({requiredLabel})</span> : null}
            </label>
            {children}
            {error || help || aside ? (
                <div className="flex items-start justify-between gap-3">
                    <p id={`${id}-help`} className={`t-caption ${error ? 'text-feedback-danger' : 'text-fg-secondary'}`}>
                        {error ?? help}
                    </p>
                    {aside}
                </div>
            ) : null}
        </div>
    );
}
