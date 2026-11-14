import { useEffect, useRef } from 'react';

/** Opens and closes a native <dialog> with `open`, falling back to the attribute where showModal is missing (jsdom). */
export function useDialog(open: boolean, onOpen: () => void) {
    const ref = useRef<HTMLDialogElement>(null);
    useEffect(() => {
        const dialog = ref.current;
        if (!dialog) return;
        if (open && !dialog.open) {
            onOpen();
            if (typeof dialog.showModal === 'function') dialog.showModal();
            else dialog.setAttribute('open', '');
        }
        if (!open && dialog.open) {
            if (typeof dialog.close === 'function') dialog.close();
            else dialog.removeAttribute('open');
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);
    return ref;
}

/** Closes a dialog, falling back to the attribute where close() is missing (jsdom). */
export function closeDialog(dialog: HTMLDialogElement | null, onClosed: () => void): void {
    if (!dialog) return;
    if (typeof dialog.close === 'function') dialog.close();
    else {
        dialog.removeAttribute('open');
        onClosed();
    }
}
