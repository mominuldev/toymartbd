import { useEffect, useId, useRef, useState } from 'react';
import { useI18n } from '@/i18n/I18nProvider';
import { Button } from './Button';
import { Field, inputClass } from './Field';

type ConfirmDialogProps = {
    open: boolean;
    title: string;
    body: string;
    confirmLabel: string;
    /** Destructive actions require typing this exact text (docs/UI-UX-DESIGN-SPEC.md §4, ConfirmDialog). */
    confirmText: string;
    typePrompt: string;
    pending?: boolean;
    onConfirm: () => void;
    onClose: () => void;
};

/**
 * Figma ConfirmDialog 203:1387. Built on <dialog>.showModal(), which gives a real
 * focus trap, Esc to close and inert background for free.
 */
export function ConfirmDialog({ open, title, body, confirmLabel, confirmText, typePrompt, pending, onConfirm, onClose }: ConfirmDialogProps) {
    const { t } = useI18n();
    const ref = useRef<HTMLDialogElement>(null);
    const titleId = useId();
    const [typed, setTyped] = useState('');

    useEffect(() => {
        const dialog = ref.current;
        if (!dialog) return;
        if (open && !dialog.open) {
            setTyped('');
            // jsdom has no showModal; the attribute keeps tests meaningful.
            if (typeof dialog.showModal === 'function') dialog.showModal();
            else dialog.setAttribute('open', '');
        }
        if (!open && dialog.open) dialog.close();
    }, [open]);

    return (
        <dialog
            ref={ref}
            aria-labelledby={titleId}
            onClose={onClose}
            onCancel={onClose}
            className="m-auto w-[min(100%-2rem,28rem)] rounded-lg border border-line-default bg-surface-raised p-0 text-fg-primary shadow-e4 backdrop:bg-overlay"
        >
            <form
                method="dialog"
                className="space-y-4 p-6"
                onSubmit={(event) => {
                    event.preventDefault();
                    if (typed === confirmText) onConfirm();
                }}
            >
                <h2 id={titleId} className="t-admin-section">
                    {title}
                </h2>
                <p className="t-admin-body text-fg-secondary">{body}</p>
                <Field label={typePrompt}>
                    {({ id }) => (
                        <input
                            id={id}
                            autoComplete="off"
                            value={typed}
                            onChange={(e) => setTyped(e.target.value)}
                            className={`h-control-md ${inputClass}`}
                        />
                    )}
                </Field>
                <div className="flex justify-end gap-2">
                    <Button variant="secondary" onClick={onClose}>
                        {t('common.cancel')}
                    </Button>
                    <Button type="submit" variant="danger" disabled={typed !== confirmText || pending}>
                        {confirmLabel}
                    </Button>
                </div>
            </form>
        </dialog>
    );
}
