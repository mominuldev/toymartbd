import { useEffect, useId, useRef, type ReactNode, type RefObject } from 'react';

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
