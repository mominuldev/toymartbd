import { useEffect, useId, useRef, useState, type ReactNode, type RefObject } from 'react';
import { DotsThreeIcon } from '@phosphor-icons/react';

/** Western digits with grouping in both locales (rule 36). */
export function formatCount(value: number): string {
    return new Intl.NumberFormat('en-US').format(value);
}

/** `value`, settled for `ms` — the SERP preview asks the server once typing pauses. */
export function useDebouncedValue<T>(value: T, ms = 300): T {
    const [settled, setSettled] = useState(value);
    useEffect(() => {
        const timer = setTimeout(() => setSettled(value), ms);
        return () => clearTimeout(timer);
    }, [value, ms]);
    return settled;
}

/** The "⋯" row menu (DataTable 192:532 row actions). */
export function RowMenu({ label, children }: { label: string; children: ReactNode }) {
    const ref = useRef<HTMLDetailsElement>(null);
    return (
        <details ref={ref} className="relative">
            <summary aria-label={label} title={label} className="inline-grid size-8 cursor-pointer list-none place-items-center rounded-sm text-fg-primary hover:bg-subtle [&::-webkit-details-marker]:hidden">
                <DotsThreeIcon size={16} aria-hidden />
            </summary>
            <div className="absolute end-0 top-full z-20 mt-1 flex min-w-40 flex-col rounded-md border border-line-default bg-surface p-1 shadow-e2" onClick={() => ref.current?.removeAttribute('open')}>
                {children}
            </div>
        </details>
    );
}

export function RowMenuItem({ onClick, children, danger }: { onClick: () => void; children: ReactNode; danger?: boolean }) {
    return (
        <button type="button" onClick={onClick} className={`flex h-8 items-center rounded-xs px-3 text-start t-admin-body hover:bg-subtle ${danger ? 'text-feedback-danger' : 'text-fg-primary'}`}>
            {children}
        </button>
    );
}

/* The A23 dialog frame, as in the media library (kept local: no cross-feature imports). */
type ModalDialogProps = {
    open: boolean;
    onClose: () => void;
    /** Rendered as the dialog's accessible name. */
    title: ReactNode;
    /** The 44px tile above the title (ConfirmDialog 203:1387 geometry). */
    icon?: ReactNode;
    children?: ReactNode;
    footer: ReactNode;
    /** Focused on open; defaults to the first focusable element. Put it on the safe action. */
    initialFocus?: RefObject<HTMLElement | null>;
    width?: 'md' | 'lg';
};

/**
 * The A23 dialog frame (520 wide, radius/lg, e4; header, body, a border-topped footer) on a
 * native <dialog>.showModal(): a real focus trap, Esc closes, the background is inert.
 * Not a <form>, so it can sit anywhere — including inside another form.
 */
export function ModalDialog({ open, onClose, title, icon, children, footer, initialFocus, width = 'md' }: ModalDialogProps) {
    const ref = useRef<HTMLDialogElement>(null);
    const titleId = useId();

    useEffect(() => {
        const dialog = ref.current;
        if (!dialog) return;
        if (open && !dialog.open) {
            // jsdom has no showModal; the attribute keeps tests meaningful.
            if (typeof dialog.showModal === 'function') dialog.showModal();
            else dialog.setAttribute('open', '');
            initialFocus?.current?.focus();
        }
        if (!open && dialog.open) {
            if (typeof dialog.close === 'function') dialog.close();
            else dialog.removeAttribute('open');
        }
    }, [open, initialFocus]);

    return (
        <dialog
            ref={ref}
            aria-labelledby={titleId}
            onCancel={(event) => {
                event.preventDefault();
                onClose();
            }}
            className={`m-auto ${width === 'lg' ? 'w-[min(100%-2rem,40rem)]' : 'w-[min(100%-2rem,32.5rem)]'} rounded-lg bg-surface p-0 text-fg-primary shadow-e4 backdrop:bg-overlay`}
        >
            {open && (
                <>
                    <div className="flex flex-col items-start gap-2.5 px-6 pt-6 pb-4">
                        {icon}
                        <h2 id={titleId} className="t-admin-section text-fg-primary">
                            {title}
                        </h2>
                        {children}
                    </div>
                    <div className="flex flex-wrap justify-end gap-3 border-t border-line-default px-6 py-4">{footer}</div>
                </>
            )}
        </dialog>
    );
}
