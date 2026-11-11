import { forwardRef, type InputHTMLAttributes } from 'react';
import { MagnifyingGlassIcon } from '@phosphor-icons/react';
import { Kbd } from './Kbd';

export type SearchFieldProps = Omit<InputHTMLAttributes<HTMLInputElement>, 'type'> & {
    /** Visually hidden label (the placeholder is not a label). */
    label: string;
    /** Keyboard hint shown at the end, e.g. ⌘K. */
    shortcut?: string;
    className?: string;
};

/**
 * Figma SearchField 185:28 — 36 high on bg/subtle with a border/default hairline,
 * 16px magnifying glass, optional Kbd. Focus lifts it to bg/surface with the 2px focus border.
 */
export const SearchField = forwardRef<HTMLInputElement, SearchFieldProps>(function SearchField({ label, shortcut, className = '', ...props }, ref) {
    return (
        <label
            className={`flex h-9 min-w-0 items-center gap-2 rounded-sm border border-line-default bg-subtle ps-3 pe-2 text-fg-tertiary focus-within:border-line-focus focus-within:bg-surface focus-within:ring-1 focus-within:ring-inset focus-within:ring-line-focus ${className}`}
        >
            <MagnifyingGlassIcon size={16} aria-hidden className="shrink-0 text-fg-secondary" />
            <span className="sr-only">{label}</span>
            <input
                ref={ref}
                type="search"
                className="h-full min-w-0 flex-1 bg-transparent t-admin-body text-fg-primary outline-none placeholder:text-fg-tertiary focus-visible:shadow-none"
                {...props}
            />
            {shortcut && <Kbd>{shortcut}</Kbd>}
        </label>
    );
});
